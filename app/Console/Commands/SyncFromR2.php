<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use App\Services\ThumbnailService;
use Illuminate\Database\QueryException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use App\Support\MediaDimensionExtractor;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;

use App\Enums\AlbumLocation;

class SyncFromR2 extends Command
{
    private const LOCATION_SEGMENTS = ['rajkot', 'ahmedabad', 'anniversaries']; // For path parsing only; enum used elsewhere

    protected $signature = 'r2:sync
        {--prefix=albums  : R2 path prefix to scan (default: albums)}
        {--album=         : Scope sync to one album (album id, slug, or full r2 path)}
        {--memory-limit=1024M : PHP memory limit for this command when regenerating thumbnails}
        {--user_id=1      : User ID to assign as owner for newly created albums and media}
        {--dry-run        : Preview what would be created without writing to the database}
        {--prune          : Delete DB media records for files no longer in R2 (sync cleanup)}
        {--regenerate-thumbnails : Delete and regenerate thumbnails for all synced media}
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
    private int $thumbGenerated = 0;
    private int $thumbSkipped = 0;
    private int $thumbFailed = 0;
    private int $thumbRegenerated = 0;
    private int $coversUpdated = 0;
    private int $coversSkipped = 0;
    private int $mediaDeleted = 0;
    private int $albumsDeleted = 0;

    public function handle(ThumbnailService $thumbnailService): int
    {
        $this->disk   = (string) config('filesystems.media_disk', 'public');
        $this->dryRun = (bool) $this->option('dry-run');
        $prune        = (bool) $this->option('prune');
        $regenerate   = (bool) $this->option('regenerate-thumbnails');
        $prefix       = rtrim((string) $this->option('prefix'), '/');
        $albumScope   = trim((string) $this->option('album'));
        $userId       = (int) $this->option('user_id');

        if ($albumScope !== '') {
            $resolvedPrefix = $this->resolveAlbumScopePrefix($albumScope);
            if ($resolvedPrefix === null) {
                $this->error("Album scope [{$albumScope}] not found. Use album id, slug, or full albums/... path.");
                return 1;
            }

            $prefix = $resolvedPrefix;
            $this->info("Using album scope prefix [{$prefix}] from --album option.");
        }

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
        /** @var array<string, true> $remoteFilePathSet normalized file paths found in R2 */
        $remoteFilePathSet = [];

        try {
            /** @var \Illuminate\Filesystem\FilesystemAdapter $filesystem */
            $filesystem = Storage::disk($this->disk);

            $listing = $filesystem
                ->getAdapter()
                ->listContents($prefix, true);   // true = recursive

            foreach ($listing as $item) {
                if ($item instanceof DirectoryAttributes) {
                    // Directory entries in object storage can be placeholders.
                    // Build album candidates from actual media file ancestry instead.
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
                $remoteFilePathSet[$this->normalizePath($path)] = true;
            }
        } catch (\Throwable $e) {
            $this->error('Failed to list R2 contents: ' . $e->getMessage());
            return 1;
        }

        if ($this->shouldRegisterPrefixAsAlbum($prefix) && count($fileItems) > 0) {
            $dirPaths[$prefix] = true;
        }

        // Sort dirs by depth (fewest slashes first) so parents are created before children
        $sortedDirs = array_keys($dirPaths);
        usort($sortedDirs, fn ($a, $b) => substr_count($a, '/') <=> substr_count($b, '/'));
        $remoteAlbumPathSet = array_fill_keys(array_map(fn ($p) => $this->normalizePath($p), $sortedDirs), true);

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

        // Guard against drifted AUTO_INCREMENT values after manual imports.
        $this->ensureMediaAutoIncrementIsHealthy();

        $bar = $this->output->createProgressBar(count($fileItems));
        $bar->start();

        foreach ($fileItems as $fileItem) {
            $this->syncMedia($fileItem, $albumMap, $thumbnailService);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ------------------------------------------------------------------
        // 4. Prune orphaned media/albums before expensive thumbnail regeneration
        // ------------------------------------------------------------------
        if ($prune) {
            $this->newLine();
            $this->info('--- Pruning orphaned media (files in DB but not in R2) ---');
            $this->pruneOrphanedDataByPrefix($prefix, $remoteFilePathSet, $remoteAlbumPathSet, $thumbnailService);
            $this->newLine();
        } elseif ($this->albumsCreated > 0 || $this->mediaCreated > 0) {
            $this->warn('[INFO] Use --prune flag to delete DB records for files that have been removed from R2.');
        }

        // ------------------------------------------------------------------
        // 5. Regenerate thumbnails (optional)
        // ------------------------------------------------------------------
        if ($regenerate) {
            $this->applyMemoryLimitForThumbnailRegeneration();
            $this->newLine();
            $this->info('--- Regenerating thumbnails for all synced media ---');
            $this->regenerateAllThumbnails($albumMap, $thumbnailService);
            $this->newLine();
        } elseif ($this->mediaCreated > 0 || $this->mediaSkipped > 0) {
            $this->warn('[INFO] Use --regenerate-thumbnails flag to delete and regenerate all thumbnails.');
        }

        // ------------------------------------------------------------------
        // 6. Sync album cover images from cover-images/*
        // ------------------------------------------------------------------
        if ($prefix === 'albums') {
            $this->newLine();
            $this->info('--- Syncing cover images ---');
            $this->syncCoverImages();
            $this->newLine();
        }

        // ------------------------------------------------------------------
        // 7. Summary
        // ------------------------------------------------------------------
        $this->info('Sync complete.');
        $this->table(
            ['Entity', 'Created', 'Skipped', 'Regenerated', 'Deleted'],
            [
                ['Albums', $this->albumsCreated, $this->albumsSkipped, '—', $this->albumsDeleted],
                ['Media',  $this->mediaCreated,  $this->mediaSkipped, '—', $this->mediaDeleted],
                ['Thumbnails', $this->thumbGenerated, ($this->thumbSkipped + $this->thumbFailed), $this->thumbRegenerated, '—'],
                ['Cover images', $this->coversUpdated, $this->coversSkipped, '—', 0],
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

        // Already in DB (including soft-deleted) — reuse/restore it
        $normalizedDirPath = $this->normalizePath($dirPath);
        $existing = Album::withTrashed()
            ->whereRaw('TRIM(LEADING \'/\' FROM r2_path) = ?', [$normalizedDirPath])
            ->first();
        if ($existing) {
            $locationFromPath = $this->inferLocationFromPath($dirPath);

            if ($existing->trashed() && ! $this->dryRun) {
                $existing->restore();
            }

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
            ?? ($this->user->location ?: AlbumLocation::Rajkot->value);

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
    private function syncMedia(FileAttributes $fileItem, array $albumMap, ThumbnailService $thumbnailService): void
    {
        $filePath = $fileItem->path();

        // Guard against extensionless/unsupported keys that may slip in.
        if (! $this->hasSupportedExtension($filePath)) {
            return;
        }

        $normalizedFilePath = $this->normalizePath($filePath);
        $existingMedia = Media::withTrashed()
            ->whereRaw('TRIM(LEADING \'/\' FROM file_path) = ?', [$normalizedFilePath])
            ->first();
        if ($existingMedia) {
            if ($existingMedia->trashed() && ! $this->dryRun) {
                $existingMedia->restore();
            }

            $this->mediaSkipped++;
            return;
        }

        $dir      = dirname($filePath);
        $album    = $albumMap[$dir] ?? null;

        $ext      = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeType = $this->mimeFromExt($ext);
        $fileType = str_starts_with($mimeType, 'video') ? 'video' : 'image';
        [$width, $height] = MediaDimensionExtractor::fromStorage($this->disk, $filePath, $mimeType);

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
            $media = $this->createMediaWithAutoIncrementRecovery([
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

            if ($fileType === 'image' || $fileType === 'video') {
                $thumbStatus = $thumbnailService->generateWithStatus($media);

                if ($thumbStatus === 'generated') {
                    if ($this->shouldSyncDimensionsFromThumbnail($media)) {
                        $thumbnailService->syncDimensionsFromThumbnail($media);
                    }
                    $this->thumbGenerated++;
                } elseif ($thumbStatus === 'skipped') {
                    $this->thumbSkipped++;
                } else {
                    $this->thumbFailed++;
                }
            }
        } catch (\Throwable $e) {
            // Roll back the counter and count as skipped so the summary is accurate.
            $this->mediaCreated--;
            $this->mediaSkipped++;
            $this->warn("  [SKIP] {$filePath}: " . $e->getMessage());
        }
    }

    /**
     * Create a media row and recover once if MySQL auto-increment drifted.
     */
    private function createMediaWithAutoIncrementRecovery(array $attributes): Media
    {
        try {
            return Media::create($attributes);
        } catch (QueryException $e) {
            if (! $this->isDuplicatePrimaryKeyError($e)) {
                throw $e;
            }

            $this->ensureMediaAutoIncrementIsHealthy(true);

            return Media::create($attributes);
        }
    }

    private function isDuplicatePrimaryKeyError(QueryException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'duplicate entry')
            && (str_contains($message, 'media.primary') || str_contains($message, 'for key \"primary\"') || str_contains($message, "for key 'primary'"));
    }

    /**
     * Ensure media.AUTO_INCREMENT is greater than MAX(id) on MySQL/MariaDB.
     */
    private function ensureMediaAutoIncrementIsHealthy(bool $verbose = false): void
    {
        if ($this->dryRun) {
            return;
        }

        try {
            $driver = DB::connection()->getDriverName();
            if (! in_array($driver, ['mysql', 'mariadb'], true)) {
                return;
            }

            $maxId = (int) (DB::table('media')->max('id') ?? 0);

            $row = DB::selectOne(
                'SELECT AUTO_INCREMENT AS next_id
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                ['media'],
            );

            $nextId = (int) (($row->next_id ?? 0));
            if ($nextId > $maxId) {
                return;
            }

            $target = $maxId + 1;
            DB::statement('ALTER TABLE media AUTO_INCREMENT = ' . $target);

            $this->warn("[FIX] Adjusted media AUTO_INCREMENT to {$target}.");
        } catch (\Throwable $e) {
            if ($verbose) {
                $this->warn('[WARN] Failed to auto-fix media AUTO_INCREMENT: ' . $e->getMessage());
            }
        }
    }

    private function shouldSyncDimensionsFromThumbnail(Media $media): bool
    {
        if (empty($media->thumbnail_path)) {
            return false;
        }

        $width = (int) ($media->width ?? 0);
        $height = (int) ($media->height ?? 0);

        if ($width <= 0 || $height <= 0) {
            return true;
        }

        return false;
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

    private function shouldRegisterPrefixAsAlbum(string $prefix): bool
    {
        $prefix = trim($prefix, '/');

        if ($prefix === '' || $prefix === 'albums') {
            return false;
        }

        if (! $this->isUnderKnownLocationPath($prefix)) {
            return false;
        }

        if ($this->isLocationContainerPath($prefix)) {
            return false;
        }

        return true;
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

        return AlbumLocation::fromSlug($parts[1])?->value;
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

    private function resolveAlbumScopePrefix(string $albumScope): ?string
    {
        $value = $this->normalizePath($albumScope);

        if ($value === '') {
            return null;
        }

        if (str_starts_with(strtolower($value), 'albums/')) {
            return $value;
        }

        if (ctype_digit($value)) {
            $album = Album::withTrashed()->find((int) $value);
            $path = $this->normalizePath((string) ($album?->r2_path ?? ''));
            return $path !== '' ? $path : null;
        }

        $album = Album::withTrashed()->where('slug', $value)->first();
        $path = $this->normalizePath((string) ($album?->r2_path ?? ''));

        return $path !== '' ? $path : null;
    }

    private function applyMemoryLimitForThumbnailRegeneration(): void
    {
        $limit = trim((string) $this->option('memory-limit'));

        if ($limit === '') {
            return;
        }

        $current = (string) ini_get('memory_limit');

        if (@ini_set('memory_limit', $limit) !== false) {
            $this->line("[INFO] memory_limit adjusted: {$current} -> {$limit}");
        } else {
            $this->warn("[WARN] Unable to set memory_limit to {$limit}; current={$current}");
        }
    }

    /**
     * Mirror DB state to the scanned R2 scope defined by --prefix.
     *
     * - Media: delete DB rows whose file_path is in prefix scope but not in the
     *   current R2 listing.
     * - Albums: delete DB rows whose r2_path is in prefix scope but not in the
     *   current R2 folder listing.
     */
    private function pruneOrphanedDataByPrefix(
        string $prefix,
        array $remoteFilePathSet,
        array $remoteAlbumPathSet,
        ThumbnailService $thumbnailService,
    ): void {
        $normalizedPrefix = $this->normalizePath($prefix);

        if ($normalizedPrefix === '') {
            $this->warn('Empty prefix scope cannot be pruned safely.');
            return;
        }

        $this->pruneOrphanedMediaByPrefix($normalizedPrefix, $remoteFilePathSet, $thumbnailService);
        $this->pruneOrphanedAlbumsByPrefix($normalizedPrefix, $remoteAlbumPathSet);
    }

    private function pruneOrphanedMediaByPrefix(string $prefix, array $remoteFilePathSet, ThumbnailService $thumbnailService): void
    {
        $mediaQuery = Media::withTrashed()->where(function ($query) use ($prefix) {
            $query->whereRaw('TRIM(LEADING \'/\' FROM file_path) = ?', [$prefix])
                ->orWhereRaw('TRIM(LEADING \'/\' FROM file_path) LIKE ?', [$prefix . '/%']);
        });

        $total = (clone $mediaQuery)->count();

        if ($total === 0) {
            $this->info('No media records in prune scope.');
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $mediaQuery
            ->orderBy('id')
            ->chunkById(200, function ($mediaRecords) use ($thumbnailService, $remoteFilePathSet, $bar) {
                foreach ($mediaRecords as $media) {
                    $normalizedFilePath = $this->normalizePath((string) $media->file_path);

                    if (! isset($remoteFilePathSet[$normalizedFilePath])) {
                        $this->mediaDeleted++;

                        if (! $this->dryRun) {
                            try {
                                if (! empty($media->thumbnail_path)) {
                                    $thumbnailService->delete($media);
                                }

                                $media->forceDelete();

                                $this->newLine();
                                $this->line("  [DELETED] {$media->file_name} (no longer in R2)");
                            } catch (\Throwable $e) {
                                $this->newLine();
                                $this->warn("  [FAILED TO DELETE] {$media->file_name}: {$e->getMessage()}");
                                $this->mediaDeleted--;
                            }
                        } else {
                            $this->newLine();
                            $this->line("  [DRY] Would delete media: {$media->file_name}");
                        }
                    }

                    $bar->advance();
                    unset($media);
                }
            });

        $bar->finish();
        $this->newLine(2);

        if ($this->mediaDeleted === 0) {
            $this->info('No orphaned media found.');
        } else {
            $this->info("Deleted {$this->mediaDeleted} orphaned media record(s).");
        }
    }

    private function pruneOrphanedAlbumsByPrefix(string $prefix, array $remoteAlbumPathSet): void
    {
        $albumsInScope = Album::withTrashed()
            ->whereNotNull('r2_path')
            ->where(function ($query) use ($prefix) {
                $query->whereRaw('TRIM(LEADING \'/\' FROM r2_path) = ?', [$prefix])
                    ->orWhereRaw('TRIM(LEADING \'/\' FROM r2_path) LIKE ?', [$prefix . '/%']);
            })
            ->get();

        if ($albumsInScope->isEmpty()) {
            $this->info('No album records in prune scope.');
            return;
        }

        $staleAlbums = $albumsInScope
            ->filter(function (Album $album) use ($remoteAlbumPathSet) {
                $albumPath = $this->normalizePath((string) $album->r2_path);
                return ! isset($remoteAlbumPathSet[$albumPath]);
            })
            ->sortByDesc(fn (Album $album) => substr_count((string) $album->r2_path, '/'))
            ->values();

        if ($staleAlbums->isEmpty()) {
            $this->info('No orphaned albums found.');
            return;
        }

        foreach ($staleAlbums as $album) {
            $this->albumsDeleted++;

            if ($this->dryRun) {
                $this->line("  [DRY] Would delete album: {$album->title} ({$album->r2_path})");
                continue;
            }

            try {
                $this->purgeAlbumMediaBeforeAlbumDelete($album);
                $album->forceDelete();
                $this->line("  [DELETED] Album: {$album->title} ({$album->r2_path})");
            } catch (\Throwable $e) {
                $this->warn("  [FAILED TO DELETE] Album {$album->title}: {$e->getMessage()}");
                $this->albumsDeleted--;
            }
        }
    }

    private function purgeAlbumMediaBeforeAlbumDelete(Album $album): void
    {
        $album->media()->withTrashed()->orderBy('id')->chunkById(100, function ($mediaRecords) {
            foreach ($mediaRecords as $media) {
                try {
                    if (! empty($media->thumbnail_path)) {
                        app(ThumbnailService::class)->delete($media);
                    }

                    $media->forceDelete();
                    $this->mediaDeleted++;
                } catch (\Throwable $e) {
                    $this->warn("  [FAILED TO DELETE] Media {$media->file_name}: {$e->getMessage()}");
                }
            }
        });
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\\\', '/', $path), '/');
    }

    /**
     * Delete and regenerate thumbnails for all media in synced albums.
     * This is useful after fixing thumbnail generation bugs or when upgrading ffmpeg.
     */
    private function regenerateAllThumbnails(array $albumMap, ThumbnailService $thumbnailService): void
    {
        if (empty($albumMap)) {
            $this->info('No albums synced; nothing to regenerate.');
            return;
        }

        $albumIds = array_map(fn (Album $a) => $a->id, array_filter($albumMap));

        if (empty($albumIds)) {
            $this->info('No albums in map; nothing to regenerate.');
            return;
        }

        $total = Media::whereIn('album_id', $albumIds)
            ->whereIn('file_type', ['image', 'video'])
            ->count();

        if ($total === 0) {
            $this->info('No image/video records to regenerate.');
            return;
        }

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        Media::whereIn('album_id', $albumIds)
            ->whereIn('file_type', ['image', 'video'])
            ->orderBy('id')
            ->chunkById(100, function ($mediaRecords) use ($thumbnailService, $bar) {
                foreach ($mediaRecords as $media) {
                    if (!$this->dryRun) {
                        try {
                            // Delete existing thumbnail from R2
                            if (!empty($media->thumbnail_path)) {
                                $thumbnailService->delete($media);
                                $media->refresh();
                            }

                            // Generate new thumbnail
                            $status = $thumbnailService->generateWithStatus($media);

                            if ($status === 'generated') {
                                $this->thumbRegenerated++;
                                if ($this->shouldSyncDimensionsFromThumbnail($media)) {
                                    $thumbnailService->syncDimensionsFromThumbnail($media);
                                }
                            } else {
                                $this->newLine();
                                $this->warn("  [FAILED] {$media->file_name}: {$status}");
                            }
                        } catch (\Throwable $e) {
                            $this->newLine();
                            $this->warn("  [ERROR] {$media->file_name}: {$e->getMessage()}");
                        }
                    } else {
                        $this->thumbRegenerated++;
                    }

                    $bar->advance();

                    unset($media);
                    gc_collect_cycles();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->info("Regenerated {$this->thumbRegenerated} thumbnail(s).");
    }
}
