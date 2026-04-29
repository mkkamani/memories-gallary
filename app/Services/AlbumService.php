<?php

namespace App\Services;

use App\Enums\AlbumLocation;
use App\Interfaces\StorageServiceInterface;
use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;

class AlbumService
{
    private const COVER_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif'];
    private const COVER_THUMBNAIL_SIZE = 480;
    private const COVER_THUMBNAIL_QUALITY = 82;

    public function __construct(
        protected StorageServiceInterface $storageService,
    ) {}

    /**
     * Create a new album, compute its R2 directory path, and create the
     * corresponding directory placeholder in the R2 bucket.
     */
    public function create(array $data, User $user): Album
    {
        $data["user_id"] = $user->id;
        $data["is_public"] = true; // albums are always public

        // Default type when not provided
        if (empty($data["type"])) {
            $data["type"] = "event";
        }

        // Validate parent if provided
        if (!empty($data["parent_id"])) {
            $this->validateParent($data["parent_id"], $user);

            // Inherit location from parent when caller did not supply one
            if (empty($data["location"])) {
                $parent = Album::find($data["parent_id"]);
                if ($parent) {
                    $data["location"] = $parent->location;
                }
            }
        }

        if (empty($data["slug"])) {
            $data["slug"] = $this->generateUniqueSlug($data["title"]);
        }

        if (empty($data['location'])) {
            $data['location'] = $user->location ?: AlbumLocation::Rajkot->value;
        }

        // Create the album row (r2_path is computed after we have the id)
        $album = Album::create($data);

        // Compute and persist the R2 directory path
        $r2Path = $this->computeR2Path($album);
        $album->update(["r2_path" => $r2Path]);

        // Create the directory placeholder in R2
        try {
            $this->storageService->createDirectory($r2Path);
        } catch (\Throwable $e) {
            Log::error("AlbumService: failed to create R2 directory.", [
                "album_id" => $album->id,
                "r2_path" => $r2Path,
                "error" => $e->getMessage(),
            ]);
        }

        return $album->fresh();
    }

    /**
     * Update an existing album.
     */
    public function update(Album $album, array $data): Album
    {
        // Always keep albums public
        $data["is_public"] = true;

        if (array_key_exists('title', $data)) {
            $data['title'] = trim((string) $data['title']);
        }

        // Validate parent if being changed
        if (isset($data["parent_id"])) {
            if ($data["parent_id"] !== null) {
                $this->validateParent($data["parent_id"], $album->user, $album);
            }
        }

        $titleChanged = isset($data["title"]) && $data["title"] !== '' && $album->title !== $data["title"];

        if ($titleChanged) {
            $data["slug"] = $this->generateUniqueSlug($data["title"], $album->id);
        }

        $newParentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $album->parent_id;
        $newLocation = array_key_exists('location', $data) ? $data['location'] : $album->location;
        $parentChanged = array_key_exists('parent_id', $data) && $newParentId !== $album->parent_id;
        $locationChanged = array_key_exists('location', $data) && $newLocation !== $album->location;
        $pathAffectingChange = $titleChanged || $parentChanged || $locationChanged;

        $pathProbe = clone $album;
        $pathProbe->parent_id = $newParentId;
        $pathProbe->location = $newLocation;
        if ($titleChanged) {
            $pathProbe->title = $data['title'];
        }

        $oldBasePath = (string) ($album->r2_path ?: '');
        $newBasePath = $pathAffectingChange
            ? $this->computeR2Path($pathProbe)
            : $oldBasePath;
        $shouldMigratePaths = $pathAffectingChange && $oldBasePath !== '' && $oldBasePath !== $newBasePath;

        $oldCoverPath = (string) ($album->cover_image ?? '');
        $newCoverStem = $this->getCoverImageStemForAlbumPath($newBasePath, $album->id, $album->title);

        if ($shouldMigratePaths) {
            $this->moveStoragePrefix($oldBasePath, $newBasePath);
            $this->rewriteDatabasePaths($album->id, $oldBasePath, $newBasePath);

            // When album naming/path changes, keep cover image filename aligned
            // with the new album segment if a new cover is not already provided.
            if ($oldCoverPath !== '' && ! array_key_exists('cover_image', $data)) {
                $coverExtension = strtolower((string) pathinfo($oldCoverPath, PATHINFO_EXTENSION));

                if ($coverExtension !== '') {
                    $newCoverPath = 'cover-images/' . $newCoverStem . '.' . $coverExtension;

                    if ($newCoverPath !== $oldCoverPath) {
                        $this->deleteCoverImageVariants($newCoverStem, $newCoverPath);
                        $this->moveStorageObject($oldCoverPath, $newCoverPath);
                        $data['cover_image'] = $newCoverPath;
                    }
                }
            }
        }

        if ($pathAffectingChange) {
            $data['r2_path'] = $newBasePath;
        }

        $album->update($data);
        return $album;
    }

    public function getPlannedCoverImagePathForAlbum(Album $album, array $incomingData, string $extension): string
    {
        $title = array_key_exists('title', $incomingData) && trim((string) $incomingData['title']) !== ''
            ? trim((string) $incomingData['title'])
            : (string) $album->title;
        $parentId = array_key_exists('parent_id', $incomingData)
            ? $incomingData['parent_id']
            : $album->parent_id;
        $location = array_key_exists('location', $incomingData)
            ? $incomingData['location']
            : $album->location;

        $probe = clone $album;
        $probe->title = $title;
        $probe->parent_id = $parentId;
        $probe->location = $location;

        return $this->buildCoverImagePath(
            $this->getCoverImageStemForAlbumPath($this->computeR2Path($probe), $album->id, $title),
            $extension,
        );
    }

    public function getCoverImagePathForAlbum(Album $album, string $extension): string
    {
        return $this->buildCoverImagePath(
            $this->getCoverImageStemForAlbum($album),
            $extension,
        );
    }

    public function getCoverImageStemForAlbum(Album $album): string
    {
        $basePath = (string) ($album->r2_path ?: $this->computeR2Path($album));
        return $this->getCoverImageStemForAlbumPath($basePath, $album->id, $album->title);
    }

    public function generateCoverThumbnailFromUpload(UploadedFile $file, string $coverPath): string
    {
        $disk = (string) config('filesystems.media_disk', 'public');
        $imageManager = new ImageManager(
            new Driver(),
            autoOrientation: true,
            decodeAnimation: false,
            strip: true,
        );

        $image = $imageManager->make($file->getPathname());
        $image->resize(self::COVER_THUMBNAIL_SIZE, self::COVER_THUMBNAIL_SIZE, function ($constraint) {
            $constraint->aspectRatio();
            $constraint->upsize();
        });

        $jpeg = $image->encode(new JpegEncoder(self::COVER_THUMBNAIL_QUALITY, progressive: true, strip: true))->toString();
        $coverPath = trim($coverPath, '/');
        Storage::disk($disk)->put($coverPath, $jpeg);

        return $coverPath;
    }

    public function deleteCoverImageVariants(string $coverStem, ?string $exceptPath = null): void
    {
        $except = $exceptPath ? trim($exceptPath, '/') : null;

        foreach (self::COVER_IMAGE_EXTENSIONS as $ext) {
            $path = 'cover-images/' . $coverStem . '.' . $ext;
            if ($except !== null && trim($path, '/') === $except) {
                continue;
            }

            try {
                $this->storageService->deleteFile($path);
            } catch (\Throwable $e) {
                Log::warning('AlbumService: failed deleting previous cover image variant.', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function getCoverImageStemForAlbumPath(string $albumPath, int $albumId, string $albumTitle): string
    {
        $segment = basename(trim($albumPath, '/'));

        if ($segment === '' || $segment === '.' || $segment === '/') {
            $safeName = preg_replace('/[^a-z0-9]+/', '_', strtolower($albumTitle));
            $safeName = trim((string) $safeName, '_');
            $segment = ($safeName !== '' ? $safeName : 'album') . '_' . $albumId;
        }

        return $segment;
    }

    private function buildCoverImagePath(string $coverStem, string $extension): string
    {
        $ext = strtolower(ltrim($extension, '.'));
        if ($ext === '') {
            $ext = 'jpg';
        }

        return 'cover-images/' . $coverStem . '.' . $ext;
    }

    private function mediaDisk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    private function moveStoragePrefix(string $oldPrefix, string $newPrefix): void
    {
        $disk = $this->mediaDisk();
        $storage = Storage::disk($disk);

        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');

        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return;
        }

        $prefixWithSlash = $oldPrefix . '/';

        $mediaPaths = Media::withTrashed()
            ->where('file_path', 'like', $prefixWithSlash . '%')
            ->pluck('file_path')
            ->filter()
            ->values()
            ->all();

        $coverPaths = Album::withTrashed()
            ->where('cover_image', 'like', $prefixWithSlash . '%')
            ->pluck('cover_image')
            ->filter()
            ->values()
            ->all();

        $files = array_values(array_unique(array_merge($mediaPaths, $coverPaths)));

        // Fallback to a storage listing only when DB has no known objects.
        if ($files === []) {
            $files = $storage->allFiles($oldPrefix);
        }

        // Ensure the destination prefix exists on local disks; no-op on R2/S3.
        $this->storageService->createDirectory($newPrefix);

        foreach ($files as $oldFilePath) {
            $suffix = ltrim(substr($oldFilePath, strlen($oldPrefix)), '/');
            $newFilePath = $newPrefix . '/' . $suffix;

            $moved = $storage->move($oldFilePath, $newFilePath);

            if ($moved === false) {
                // Fallback for adapters that do not support native move.
                $stream = $storage->readStream($oldFilePath);

                if ($stream === false) {
                    throw new \RuntimeException("Failed to read storage object [{$oldFilePath}] while migrating album path.");
                }

                try {
                    $written = $storage->writeStream($newFilePath, $stream);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }

                if ($written === false) {
                    throw new \RuntimeException("Failed to write storage object [{$newFilePath}] while migrating album path.");
                }

                $storage->delete($oldFilePath);
            }
        }
    }

    private function moveStorageObject(string $oldPath, string $newPath): void
    {
        $storage = Storage::disk($this->mediaDisk());
        $oldPath = trim($oldPath, '/');
        $newPath = trim($newPath, '/');

        if ($oldPath === '' || $newPath === '' || $oldPath === $newPath) {
            return;
        }

        $moved = $storage->move($oldPath, $newPath);

        if ($moved !== false) {
            return;
        }

        $stream = $storage->readStream($oldPath);

        if ($stream === false) {
            throw new \RuntimeException("Failed to read storage object [{$oldPath}] while moving cover image.");
        }

        try {
            $written = $storage->writeStream($newPath, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($written === false) {
            throw new \RuntimeException("Failed to write storage object [{$newPath}] while moving cover image.");
        }

        $storage->delete($oldPath);
    }

    private function rewriteDatabasePaths(int $rootAlbumId, string $oldPrefix, string $newPrefix): void
    {
        $oldPrefix = trim($oldPrefix, '/');
        $newPrefix = trim($newPrefix, '/');

        if ($oldPrefix === '' || $newPrefix === '' || $oldPrefix === $newPrefix) {
            return;
        }

        $prefixWithSlash = $oldPrefix . '/';

        $affectedAlbums = Album::withTrashed()
            ->where(function ($query) use ($rootAlbumId, $prefixWithSlash) {
                $query
                    ->where('id', $rootAlbumId)
                    ->orWhere('r2_path', 'like', $prefixWithSlash . '%');
            })
            ->get();

        foreach ($affectedAlbums as $affectedAlbum) {
            if (! $affectedAlbum instanceof Album) {
                continue;
            }

            $updates = [];

            if (! empty($affectedAlbum->r2_path) && str_starts_with($affectedAlbum->r2_path, $oldPrefix)) {
                $updates['r2_path'] = $newPrefix . substr($affectedAlbum->r2_path, strlen($oldPrefix));
            }

            // Handle legacy cover paths that may still live under the album folder tree.
            if (! empty($affectedAlbum->cover_image) && str_starts_with($affectedAlbum->cover_image, $oldPrefix)) {
                $updates['cover_image'] = $newPrefix . substr($affectedAlbum->cover_image, strlen($oldPrefix));
            }

            if ($updates !== []) {
                $affectedAlbum->update($updates);
            }
        }

        $affectedMedia = Media::withTrashed()
            ->where('file_path', 'like', $prefixWithSlash . '%')
            ->get();

        foreach ($affectedMedia as $media) {
            $media->update([
                'file_path' => $newPrefix . substr($media->file_path, strlen($oldPrefix)),
            ]);
        }
    }

    /**
     * Soft-delete an album.
     */
    public function delete(Album $album): bool
    {
        return $album->delete();
    }

    /**
     * Force-delete an album including all its media and nested child albums.
     */
    public function forceDelete(Album $album, MediaService $mediaService): bool
    {
        if (! empty($album->cover_image)) {
            try {
                $this->storageService->deleteFile($album->cover_image);
            } catch (\Throwable $e) {
                Log::warning('AlbumService::forceDelete – failed to delete cover image object.', [
                    'album_id' => $album->id,
                    'cover_image' => $album->cover_image,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Permanently remove every media file in this album from R2 and the DB.
        // purge() is used instead of delete() so that files are always deleted
        // from R2 regardless of whether the individual media record is trashed.
        foreach ($album->media()->withTrashed()->get() as $media) {
            $mediaService->purge($media);
        }

        // Recursively handle nested child albums
        foreach ($album->children()->withTrashed()->get() as $child) {
            $this->forceDelete($child, $mediaService);
        }

        return $album->forceDelete();
    }

    /**
     * Move an album to a different parent (or to root level when $newParentId is null).
     */
    public function move(Album $album, ?int $newParentId): Album
    {
        if ($newParentId !== null) {
            $this->validateParent($newParentId, $album->user, $album);
        }

        $album->parent_id = $newParentId;
        $album->save();

        return $album;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the R2 storage path for an album.
     *
        * Root album  → albums/{location}/{safe-title}_{id}
     * Child album → {parent_r2_path}/{safe-title}_{id}
     *
     * The parent's r2_path is used directly when available; otherwise we fall
     * back to re-computing it recursively so that legacy albums (created before
     * the r2_path column existed) are handled gracefully.
     */
    protected function computeR2Path(Album $album): string
    {
        $safeName = preg_replace(
            "/[^a-z0-9]+/",
            "_",
            strtolower($album->title),
        );
        $safeName = trim($safeName, "_");
        $segment = $safeName . "_" . $album->id;

        if ($album->parent_id) {
            $parent = Album::find($album->parent_id);

            if ($parent) {
                $parentPath = $parent->r2_path ?? $this->computeR2Path($parent);
                return $parentPath . "/" . $segment;
            }
        }

        $locationSlug = Str::slug((string) ($album->location ?: AlbumLocation::Rajkot->value));
        if ($locationSlug === '') {
            $locationSlug = 'rajkot';
        }

        return "albums/" . $locationSlug . "/" . $segment;
    }

    /**
     * Validate that the given parent album is accessible to the user and does
     * not create a circular reference.
     */
    protected function validateParent(
        int $parentId,
        User $user,
        ?Album $currentAlbum = null,
    ): void {
        $parent = Album::find($parentId);

        if (!$parent) {
            throw new \InvalidArgumentException("Parent album not found.");
        }

        if ($parent->user_id !== $user->id && !$parent->is_public) {
            throw new \InvalidArgumentException(
                "You do not have access to this parent album.",
            );
        }

        if ($currentAlbum && $parent->isDescendantOf($currentAlbum)) {
            throw new \InvalidArgumentException(
                "Cannot set parent album: this would create a circular reference.",
            );
        }

        if ($currentAlbum && $parent->id === $currentAlbum->id) {
            throw new \InvalidArgumentException(
                "An album cannot be its own parent.",
            );
        }
    }

    protected function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'album';
        }

        $slug = $base;
        $counter = 2;

        while (
            Album::query()
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
