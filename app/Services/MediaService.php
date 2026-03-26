<?php

namespace App\Services;

use App\Models\Album;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Interfaces\StorageServiceInterface;

class MediaService
{
    protected $storageService;

    public function __construct(StorageServiceInterface $storageService)
    {
        $this->storageService = $storageService;
    }

    public function upload(UploadedFile $file, User $user, ?Album $album = null)
    {
        $albumPath = $this->getAlbumUploadPath($album);

        $path = $this->storageService->uploadFile($file, $albumPath);
        $mimeType = (string) ($file->getMimeType() ?: 'application/octet-stream');
        $fileType = str_starts_with($mimeType, 'video') ? 'video' : 'image';
        [$width, $height] = $this->extractImageDimensions($file, $mimeType);

        return Media::create([
            "user_id"   => $user->id,
            "album_id"  => $album?->id,
            "file_path" => $path,
            "file_name" => $file->getClientOriginalName(),
            "file_type" => $fileType,
            "file_size" => $file->getSize(),
            "mime_type" => $mimeType,
            "width"     => $width,
            "height"    => $height,
            "taken_at"  => now(),
        ]);
    }

    public function getAlbumUploadPath(?Album $album): string
    {
        if ($album && $album->r2_path) {
            return $album->r2_path;
        }

        if ($album) {
            // Fallback for albums that were created before r2_path was introduced.
            $locationSlug = Str::slug((string) ($album->location ?: 'Rajkot'));
            if ($locationSlug === '') {
                $locationSlug = 'rajkot';
            }

            return "albums/{$locationSlug}/" . str_replace(" ", "_", strtolower($album->title)) . "_" . $album->id;
        }

        return "uploads";
    }

    public function uploadFileOnly(UploadedFile $file, ?Album $album = null): string
    {
        $albumPath = $this->getAlbumUploadPath($album);
        return $this->storageService->uploadFile($file, $albumPath);
    }

    /**
     * Read intrinsic dimensions for uploaded image files.
     *
     * Returns [null, null] when dimensions cannot be determined.
     * SVG is intentionally skipped because raster pixel dimensions are optional.
     *
     * @return array{0:int|null,1:int|null}
     */
    private function extractImageDimensions(UploadedFile $file, string $mimeType): array
    {
        if (!str_starts_with($mimeType, 'image/') || $mimeType === 'image/svg+xml') {
            return [null, null];
        }

        $realPath = $file->getRealPath();
        if (!$realPath) {
            return [null, null];
        }

        $size = @getimagesize($realPath);
        if (!is_array($size) || empty($size[0]) || empty($size[1])) {
            return [null, null];
        }

        return [(int) $size[0], (int) $size[1]];
    }

    /**
     * Soft-delete a live media record, or permanently purge it when it is
     * already in the trash.
     *
     * - Live record  → soft-delete only (file stays in R2 so it can be restored).
     * - Trashed record → delegates to purge() which removes the R2 file and
     *   hard-deletes the DB row in one step.
     */
    public function delete(Media $media): bool
    {
        if ($media->trashed()) {
            return $this->purge($media);
        }

        return (bool) $media->delete();
    }

    /**
     * Unconditionally and permanently remove a media item:
     *   1. Delete the underlying file from the R2 bucket.
     *   2. Hard-delete (forceDelete) the database record.
     *
     * This method is the single authoritative entry-point for permanent removal.
     * It is safe to call on both live and already-trashed records because it
     * bypasses the soft-delete check entirely.
     *
     * Use cases:
     *   - Admin "Permanently Delete" action from the Recycle Bin.
     *   - Cascading delete when a parent album is force-deleted.
     *   - Scheduled purge of items that have been in the trash for > 7 days.
     */
    public function purge(Media $media): bool
    {
        try {
            $this->storageService->deleteFile($media->file_path);
        } catch (\Throwable $e) {
            // Log the R2 error but still remove the DB record so orphaned rows
            // do not accumulate. The file may have already been deleted manually
            // or may never have existed (e.g. failed upload that was rolled back).
            Log::warning('MediaService::purge – could not delete R2 file; proceeding with DB delete.', [
                'media_id'  => $media->id,
                'file_path' => $media->file_path,
                'error'     => $e->getMessage(),
            ]);
        }

        return (bool) $media->forceDelete();
    }
}
