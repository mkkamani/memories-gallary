<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;

class ThumbnailService
{
    /**
     * Maximum width / height of generated thumbnails (maintains aspect ratio).
     */
    private const THUMB_SIZE = 480;

    /**
     * Estimated bytes-per-pixel used by GD while decoding/resizing.
     */
    private const GD_BYTES_PER_PIXEL = 5;

    /**
     * Keep a safety margin so decode work stays below PHP memory limit.
     */
    private const MEMORY_BUDGET_RATIO = 0.65;

    /**
     * JPEG quality for thumbnail output (0–100).
     */
    private const JPEG_QUALITY = 82;

    /**
     * Disk on which thumbnails are stored.  Must be publicly accessible
     * so the URL can be served without presigned-URL generation.
     */
    private const THUMB_DISK = 'public';

    /**
     * Directory inside the public storage disk where thumbnails live.
     */
    private const THUMB_DIR = 'thumbnails';

    // -------------------------------------------------------------------------

    private ImageManager $manager;

    public function __construct()
    {
        $this->manager = new ImageManager(
            new Driver(),
            autoOrientation: false,
            decodeAnimation: false,
            strip: true,
        );
    }

    // -------------------------------------------------------------------------

    /**
     * Generate a thumbnail for the given media item and persist the path.
     *
     * - Downloads the original file from the media disk (R2/S3/local).
     * - Resizes to fit within THUMB_SIZE × THUMB_SIZE, preserving aspect ratio.
     * - Encodes to JPEG at JPEG_QUALITY.
     * - Stores to the public disk under thumbnails/{albumId}/{id}.jpg.
     * - Updates media.thumbnail_path in place.
     *
     * Returns true on success, false on any error (the original is unaffected).
     */
    public function generate(Media $media): bool
    {
        return $this->generateWithStatus($media) === 'generated';
    }

    /**
     * Generate a thumbnail and return a status string:
     * - generated: thumbnail was created/updated
     * - skipped: unsupported or unsafe to process (e.g. HEIC, very large image)
     * - failed: an operational error occurred
     */
    public function generateWithStatus(Media $media): string
    {
        // Only generate thumbnails for images, not videos.
        if ($media->file_type !== 'image') {
            return 'skipped';
        }

        // Skip HEIC/HEIF — GD cannot decode them, and we have a separate
        // proxy-based conversion flow in the front-end for those.
        $ext = strtolower(pathinfo((string) $media->file_name, PATHINFO_EXTENSION));
        if (in_array($ext, ['heic', 'heif'], true)) {
            return 'skipped';
        }

        if ($this->wouldLikelyExceedMemory($media)) {
            Log::warning('ThumbnailService: skipped oversized image for current PHP memory limit.', [
                'media_id' => $media->id,
                'width' => $media->width,
                'height' => $media->height,
                'memory_limit' => ini_get('memory_limit'),
            ]);
            return 'skipped';
        }

        $sourcePath = null;

        try {
            $mediaDisk = (string) config('filesystems.media_disk', 'public');
            $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);

            if ($sourcePath === null) {
                Log::warning("ThumbnailService: could not read source file.", [
                    'media_id'  => $media->id,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            $image = $this->manager->decodePath($sourcePath);

            // Resize so neither dimension exceeds THUMB_SIZE, never upscale.
            $width  = $image->width();
            $height = $image->height();

            if ($width > self::THUMB_SIZE || $height > self::THUMB_SIZE) {
                $image->scaleDown(self::THUMB_SIZE, self::THUMB_SIZE);
            }

            $jpeg = $image
                ->encode(new JpegEncoder(self::JPEG_QUALITY, progressive: true, strip: true))
                ->toString();

            $thumbPath = $this->thumbPath($media);
            Storage::disk(self::THUMB_DISK)->put($thumbPath, $jpeg);

            $media->update(['thumbnail_path' => $thumbPath]);

            // Free memory immediately — images can be large.
            unset($jpeg, $image);
            gc_collect_cycles();

            return 'generated';
        } catch (\Throwable $e) {
            Log::warning("ThumbnailService: failed to generate thumbnail.", [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            if (is_string($sourcePath) && $sourcePath !== '' && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    /**
     * Estimate GD memory use from stored dimensions to avoid fatal OOM.
     */
    private function wouldLikelyExceedMemory(Media $media): bool
    {
        $width = (int) ($media->width ?? 0);
        $height = (int) ($media->height ?? 0);

        // If dimensions are unknown, allow processing and rely on exception handling.
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $estimatedBytes = (int) ($width * $height * self::GD_BYTES_PER_PIXEL);
        $memoryLimit = $this->memoryLimitBytes();

        if ($memoryLimit <= 0) {
            return false;
        }

        $budget = (int) floor($memoryLimit * self::MEMORY_BUDGET_RATIO);
        return $estimatedBytes > $budget;
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }

    /**
     * Delete the stored thumbnail for a media item and clear the DB path.
     * Safe to call even if no thumbnail exists.
     */
    public function delete(Media $media): void
    {
        if (empty($media->thumbnail_path)) {
            return;
        }

        try {
            Storage::disk(self::THUMB_DISK)->delete($media->thumbnail_path);
        } catch (\Throwable $e) {
            Log::warning("ThumbnailService: failed to delete thumbnail.", [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
        }

        $media->update(['thumbnail_path' => null]);
    }

    /**
     * Return the public URL for the thumbnail, or null if none has been generated.
     */
    public function url(Media $media): ?string
    {
        if (empty($media->thumbnail_path)) {
            return null;
        }

        return Storage::disk(self::THUMB_DISK)->url($media->thumbnail_path);
    }

    // -------------------------------------------------------------------------

    /**
     * Deterministic storage path for a thumbnail.
     * Pattern: thumbnails/{album_id|0}/{media_id}.jpg
     */
    private function thumbPath(Media $media): string
    {
        $albumSegment = $media->album_id ?? 0;
        return self::THUMB_DIR . '/' . $albumSegment . '/' . $media->id . '.jpg';
    }

    /**
     * Stream the remote/original object to a local temp file and return path.
     * This avoids duplicating large image binaries in PHP memory.
     */
    private function downloadToTemporaryFile(string $disk, string $path): ?string
    {
        $readStream = Storage::disk($disk)->readStream($path);
        if (!is_resource($readStream)) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'thumb_');
        if ($tmpPath === false) {
            fclose($readStream);
            return null;
        }

        $writeStream = fopen($tmpPath, 'wb');
        if (!is_resource($writeStream)) {
            fclose($readStream);
            @unlink($tmpPath);
            return null;
        }

        stream_copy_to_stream($readStream, $writeStream);
        fclose($readStream);
        fclose($writeStream);

        return $tmpPath;
    }
}
