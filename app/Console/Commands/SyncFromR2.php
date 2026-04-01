<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;

class SyncFromR2 extends Command
{
    private const LOCATION_SEGMENTS = ['rajkot', 'ahmedabad'];

    protected $signature = 'r2:sync
        {--prefix=albums  : R2 path prefix to scan (default: albums)}
        {--user_id=1      : User ID to assign as owner for newly created albums and media}
        {--dry-run        : Preview what would be created without writing to the database}
    ';

    protected $description = 'Sync the folder/file structure already in Cloudflare R2 into the albums and media database tables';

    private string $disk;
    private bool   $dryRun;
    private User   $user;

    private int $albumsCreated = 0;
    private int $albumsSkipped = 0;
    private int $albumsRelocated = 0;
    private int $mediaCreated  = 0;
    private int $mediaSkipped  = 0;
    private int $coversUpdated = 0;
    private int $coversSkipped = 0;

    public function handle(): int
    {
        $this->disk   = (string) config('filesystems.media_disk', 'public');
        $this->dryRun = (bool) $this->option('dry-run');
        $prefix       = rtrim((string) $this->option('prefix'), '/');
        $userId       = (int) $this->option('user_id');

        $user = User::find($userId);
        if (! $user) {
            $this->error("User with ID {$userId} not found.");
            return 1;
        }
        $this->user = $user;

        if ($this->dryRun) {
            $this->warn('[DRY RUN] No changes will be written to the database.');
        }

        $this->info("Scanning R2 disk [{$this->disk}] under prefix [{$prefix}] …");

        // ------------------------------------------------------------------
        // 1. List all objects in one recursive pass to get path + file-size.
        //    FileAttributes items carry fileSize() so no extra API calls needed.
        // ------------------------------------------------------------------
        /** @var FileAttributes[] $fileItems */
        $fileItems = [];
        /** @var array<string, true> $dirPaths  unique directory paths → future albums */
        $dirPaths = [];

        try {
            $listing = Storage::disk($this->disk)
                ->getDriver()
                ->listContents($prefix, true);   // true = recursive

            foreach ($listing as $item) {
                if ($item instanceof DirectoryAttributes) {
                    $dirPath = trim($item->path(), '/');

                    if ($dirPath === '' || $dirPath === $prefix) {
                        continue;
                    }

                    if ($prefix === 'albums' && ! $this->isUnderKnownLocationPath($dirPath)) {
                        continue;
                    }

                    if (! $this->isLocationContainerDir($dirPath, $prefix)) {
                        $dirPaths[$dirPath] = true;
                    }

                    continue;
                }

                if (! ($item instanceof FileAttributes)) {
                    continue;
                }

                $path = $item->path();

                // Skip R2 zero-byte directory-placeholder objects (key ends with /)
                if (str_ends_with($path, '/')) {
                    continue;
                }

                // Skip keys without a file extension (e.g. "done_9", "new_19")
                // and skip unknown extensions that are not supported media types.
                if (! $this->hasSupportedExtension($path)) {
                    continue;
                }

                // When scanning the albums root, only sync known location trees.
                if ($prefix === 'albums' && ! $this->isUnderKnownLocationPath($path)) {
                    continue;
                }

                // Collect every ancestor directory between $prefix and this file,
                // so each folder in the hierarchy becomes a candidate Album.
                $cur = dirname($path);
                while ($cur !== '.' && $cur !== $prefix && strlen($cur) > strlen($prefix)) {
                    if (! $this->isLocationContainerDir($cur, $prefix)) {
                        $dirPaths[$cur] = true;
                    }

                    $parent = dirname($cur);
                    if ($parent === $cur) {
                        break;
                    }
                    $cur = $parent;
                }
                // Also register the file's immediate parent (may equal $prefix child)
                if (
                    $cur !== '.'
                    && $cur !== $prefix
                    && strlen($cur) > strlen($prefix)
                    && ! $this->isLocationContainerDir($cur, $prefix)
                ) {
                    $dirPaths[$cur] = true;
                }

                $fileItems[] = $item;
            }
        } catch (\Throwable $e) {
            $this->error('Failed to list R2 contents: ' . $e->getMessage());
            return 1;
        }

        // Sort dirs by depth (fewest slashes first) so parents are created before children
        $sortedDirs = array_keys($dirPaths);
        usort($sortedDirs, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));

        $this->info(sprintf(
            'Found %d folders and %d media files.',
            count($sortedDirs),
            count($fileItems),
        ));

        // ------------------------------------------------------------------
        // 2. Sync albums — one per unique folder
        // ------------------------------------------------------------------
        $this->newLine();
        $this->info('--- Syncing albums ---');

        /** @var array<string, Album> $albumMap  r2_path → Album model */
        $albumMap = [];

        foreach ($sortedDirs as $dirPath) {
            $album = $this->syncAlbum($dirPath, $albumMap);
            if ($album !== null) {
                $albumMap[$dirPath] = $album;
            }
        }

        // ------------------------------------------------------------------
        // 3. Sync media — one per file
        // ------------------------------------------------------------------
        $this->newLine();
        $this->info('--- Syncing media ---');

        $bar = $this->output->createProgressBar(count($fileItems));
        $bar->start();

        foreach ($fileItems as $fileItem) {
            $this->syncMedia($fileItem, $albumMap);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ------------------------------------------------------------------
        // 4. Sync album cover images from cover-images/*
        // ------------------------------------------------------------------
        if ($prefix === 'albums') {
            $this->newLine();
            $this->info('--- Syncing cover images ---');
            $this->syncCoverImages();
            $this->newLine();
        }

        // ------------------------------------------------------------------
        // 5. Summary
        // ------------------------------------------------------------------
        $this->info('Sync complete.');
        $this->table(
            ['Entity', 'Created', 'Skipped (already in DB)'],
            [
                ['Albums', $this->albumsCreated, $this->albumsSkipped],
                ['Media',  $this->mediaCreated,  $this->mediaSkipped],
                ['Cover images', $this->coversUpdated, $this->coversSkipped],
            ],
        );

        if ($this->albumsRelocated > 0) {
            $this->line("Albums location corrected in DB: {$this->albumsRelocated}");
        }

        return 0;
    }

    // -----------------------------------------------------------------------
    // Per-directory: find-or-create Album
    // -----------------------------------------------------------------------
    private function syncAlbum(string $dirPath, array &$albumMap): ?Album
    {
        if ($this->isLocationContainerPath($dirPath)) {
            return null;
        }

        // Already in DB — reuse it
        $existing = Album::where('r2_path', $dirPath)->first();
        if ($existing) {
            $locationFromPath = $this->inferLocationFromPath($dirPath);

            if ($locationFromPath !== null && $existing->location !== $locationFromPath) {
                if (! $this->dryRun) {
                    $existing->update(['location' => $locationFromPath]);
                }
                $this->albumsRelocated++;
            }

            $this->albumsSkipped++;
            return $existing;
        }

        // Derive a human-readable title from the folder segment.
        // Segment format from AlbumService: "{safe_title}_{album_id}"
        $segment = basename($dirPath);
        $title   = $this->titleFromSegment($segment);
        $slug    = $this->generateUniqueSlug($title);

        // Resolve parent album from the map (already created in an earlier iteration)
        $parentPath  = dirname($dirPath);
        $parentAlbum = (isset($albumMap[$parentPath])) ? $albumMap[$parentPath] : null;

        $this->line(sprintf(
            '  [%s] Album: <info>%s</info>  (r2_path: %s%s)',
            $this->dryRun ? 'DRY' : 'NEW',
            $title,
            $dirPath,
            $parentAlbum ? ', parent: ' . $parentAlbum->title : '',
        ));

        $this->albumsCreated++;

        if ($this->dryRun) {
            return null;
        }

        $location = $this->inferLocationFromPath($dirPath)
            ?? $parentAlbum?->location
            ?? ($this->user->location ?: 'Rajkot');

        return Album::create([
            'user_id'   => $this->user->id,
            'parent_id' => $parentAlbum?->id,
            'title'     => $title,
            'slug'      => $slug,
            'type'      => 'event',
            'is_public' => true,
            'r2_path'   => $dirPath,
            'location'  => $location,
        ]);
    }

    // -----------------------------------------------------------------------
    // Per-file: find-or-create Media
    // -----------------------------------------------------------------------
    private function syncMedia(FileAttributes $fileItem, array $albumMap): void
    {
        $filePath = $fileItem->path();

        // Guard against extensionless/unsupported keys that may slip in.
        if (! $this->hasSupportedExtension($filePath)) {
            return;
        }

        // Already exists in DB (live or soft-deleted) — skip
        if (Media::withTrashed()->where('file_path', $filePath)->exists()) {
            $this->mediaSkipped++;
            return;
        }

        $dir      = dirname($filePath);
        $album    = $albumMap[$dir] ?? null;

        $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $this->mimeFromExt($ext);
        $fileType = str_starts_with($mimeType, 'video') ? 'video' : 'image';
        [$width, $height] = $this->extractImageDimensionsFromStorage($filePath, $mimeType);

        // fileSize() is populated by the listing call — no extra R2 request needed
        $fileSize = $fileItem->fileSize() ?? 0;

        // Parse taken_at from the filename timestamp when present.
        // File naming pattern from R2StorageService: {uniqid}_{date}_{time}.ext
        // e.g.  69c23ca11e7e0_20250807_101324.jpg  →  2025-08-07 10:13:24
        $takenAt = $this->parseTakenAt(basename($filePath));

        $this->mediaCreated++;

        if ($this->dryRun) {
            return;
        }

        try {
            Media::create([
                'user_id'   => $this->user->id,
                'album_id'  => $album?->id,
                'file_path' => $filePath,
                'file_name' => basename($filePath),
                'file_type' => $fileType,
                'file_size' => $fileSize,
                'mime_type' => $mimeType,
                'width'     => $width,
                'height'    => $height,
                'taken_at'  => $takenAt,
            ]);
        } catch (\Throwable $e) {
            // Roll back the counter and count as skipped so the summary is accurate.
            $this->mediaCreated--;
            $this->mediaSkipped++;
            $this->warn("  [SKIP] {$filePath}: " . $e->getMessage());
        }
    }

    private function syncCoverImages(): void
    {
        $coverFiles = Storage::disk($this->disk)->allFiles('cover-images');

        if (count($coverFiles) === 0) {
            $this->line('No files found under cover-images/.');
            return;
        }

        foreach ($coverFiles as $coverPath) {
            if (! $this->hasSupportedExtension($coverPath)) {
                continue;
            }

            $album = $this->resolveAlbumForCoverPath($coverPath);

            if (! $album) {
                $this->coversSkipped++;
                $this->line("  [SKIP] No matching album for {$coverPath}");
                continue;
            }

            if ($album->cover_image === $coverPath) {
                $this->coversSkipped++;
                continue;
            }

            $this->coversUpdated++;

            if ($this->dryRun) {
                $this->line("  [DRY] Cover image → {$album->title} ({$coverPath})");
                continue;
            }

            $album->update(['cover_image' => $coverPath]);
            $this->line("  [SET] {$album->title} cover image set to {$coverPath}");
        }
    }

    private function resolveAlbumForCoverPath(string $coverPath): ?Album
    {
        $normalized = trim($coverPath, '/');
        $parts = array_values(array_filter(explode('/', $normalized), fn ($p) => $p !== ''));

        if (count($parts) >= 3 && strtolower($parts[0]) === 'cover-images') {
            $folderKey = $parts[1];

            if (ctype_digit($folderKey)) {
                $album = Album::find((int) $folderKey);
                if ($album) {
                    return $album;
                }
            }

            if (preg_match('/_(\d+)$/', $folderKey, $m)) {
                $album = Album::find((int) $m[1]);
                if ($album) {
                    return $album;
                }
            }

            $folderSlug = Str::slug(str_replace('_', ' ', $folderKey));
            if ($folderSlug !== '') {
                $album = Album::where('slug', $folderSlug)->first();
                if ($album) {
                    return $album;
                }
            }
        }

        // Preferred structure: cover-images/{album_id}/filename.ext
        if (preg_match('#^cover-images/(\d+)/#', $normalized, $m)) {
            return Album::find((int) $m[1]);
        }

        $filename = pathinfo($normalized, PATHINFO_FILENAME);

        if ($filename === '') {
            return null;
        }

        // Fallback: numeric filename means album id.
        if (ctype_digit($filename)) {
            return Album::find((int) $filename);
        }

        // Fallback: try suffix pattern like safe_title_123.
        if (preg_match('/_(\d+)$/', $filename, $m)) {
            $album = Album::find((int) $m[1]);
            if ($album) {
                return $album;
            }
        }

        // Fallback: try filename as slug.
        $slug = Str::slug(str_replace('_', ' ', $filename));
        if ($slug !== '') {
            return Album::where('slug', $slug)->first();
        }

        return null;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Convert an R2 folder segment back into a readable title.
     *
     * AlbumService stores segments as "{safe_title}_{album_id}"
     */
    private function titleFromSegment(string $segment): string
    {
        // Strip the trailing _{numeric_id} suffix added by AlbumService::computeR2Path()
        $withoutId = (string) preg_replace('/_\d+$/', '', $segment);

        return Str::title(str_replace('_', ' ', $withoutId));
    }

    /**
     * Extract a DateTimeImmutable from a filename that contains a
     * YYYYMMDD_HHmmss stamp (as produced by R2StorageService::uploadFile).
     *
     * Only matches years 20xx (2000–2099) to avoid treating arbitrary 8-digit
     * sequences (e.g. "26870601" in VID_26870601_064401.mp4) as dates, which
     * produces out-of-range values that MySQL rejects.
     */
    private function parseTakenAt(string $filename): ?\DateTimeImmutable
    {
        // Year must start with "20" — covers 2000–2099 only.
        if (preg_match('/(?<!\d)(20\d{6})_(\d{6})(?!\d)/', $filename, $m)) {
            $dt = \DateTimeImmutable::createFromFormat('Ymd_His', $m[1] . '_' . $m[2]);
            return ($dt !== false) ? $dt : null;
        }

        return null;
    }

    private function mimeFromExt(string $ext): string
    {
        return match ($ext) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'         => 'image/png',
            'gif'         => 'image/gif',
            'webp'        => 'image/webp',
            'avif'        => 'image/avif',
            'jfif'        => 'image/jpeg',
            'heic'        => 'image/heic',
            'heif'        => 'image/heif',
            'bmp'         => 'image/bmp',
            'svg'         => 'image/svg+xml',
            'tiff', 'tif' => 'image/tiff',
            'mp4'         => 'video/mp4',
            'mov'         => 'video/quicktime',
            'avi'         => 'video/x-msvideo',
            'mkv'         => 'video/x-matroska',
            'webm'        => 'video/webm',
            '3gp'         => 'video/3gpp',
            default       => 'application/octet-stream',
        };
    }

    private function hasSupportedExtension(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === '') {
            return false;
        }

        return $this->mimeFromExt($ext) !== 'application/octet-stream';
    }

    /**
     * Read intrinsic image dimensions from storage when possible.
     *
     * @return array{0:int|null,1:int|null}
     */
    private function extractImageDimensionsFromStorage(string $path, string $mimeType): array
    {
        if (!str_starts_with($mimeType, 'image/') || $mimeType === 'image/svg+xml') {
            return [null, null];
        }

        $stream = Storage::disk($this->disk)->readStream($path);
        if ($stream === false) {
            return [null, null];
        }

        try {
            $contents = stream_get_contents($stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($contents === false) {
            return [null, null];
        }

        $size = @getimagesizefromstring($contents);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    private function isLocationContainerDir(string $dirPath, string $prefix): bool
    {
        $cleanPrefix = trim($prefix, '/');
        $relative = trim(substr($dirPath, strlen($cleanPrefix)), '/');

        if ($relative === '' || str_contains($relative, '/')) {
            return false;
        }

        return in_array(strtolower($relative), self::LOCATION_SEGMENTS, true);
    }

    private function isLocationContainerPath(string $path): bool
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));

        if (count($parts) !== 2 || strtolower($parts[0]) !== 'albums') {
            return false;
        }

        return in_array(strtolower($parts[1]), self::LOCATION_SEGMENTS, true);
    }

    private function inferLocationFromPath(string $path): ?string
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));

        if (count($parts) < 2 || strtolower($parts[0]) !== 'albums') {
            return null;
        }

        return match (strtolower($parts[1])) {
            'rajkot' => 'Rajkot',
            'ahmedabad' => 'Ahmedabad',
            default => null,
        };
    }

    private function isUnderKnownLocationPath(string $path): bool
    {
        $parts = array_values(array_filter(explode('/', trim($path, '/')), fn ($p) => $p !== ''));

        if (count($parts) < 2 || strtolower($parts[0]) !== 'albums') {
            return false;
        }

        return in_array(strtolower($parts[1]), self::LOCATION_SEGMENTS, true);
    }

    private function generateUniqueSlug(string $title): string
    {
        $base    = Str::slug($title) ?: 'album';
        $slug    = $base;
        $counter = 2;

        while (Album::where('slug', $slug)->exists()) {
            $slug = $base . '-' . $counter++;
        }

        return $slug;
    }
}
