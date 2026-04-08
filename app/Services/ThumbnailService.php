<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Symfony\Component\Process\Process;

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
     * Directory inside the public storage disk where thumbnails live.
     */
    private const THUMB_DIR = 'thumbnails';

    // -------------------------------------------------------------------------

    private ImageManager $manager;
    private ?bool $ffmpegAvailable = null;

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
        * - skipped: unsupported or unsafe to process (e.g. very large image)
     * - failed: an operational error occurred
     */
    public function generateWithStatus(Media $media): string
    {
        if ($media->file_type === 'image') {
            return $this->generateImageThumbnail($media);
        }

        if ($media->file_type === 'video') {
            return $this->generateVideoThumbnail($media);
        }

        return 'skipped';
    }

    private function generateImageThumbnail(Media $media): string
    {
        if ($this->wouldLikelyExceedMemory($media)) {
            return $this->generateLargeImageThumbnailWithFfmpeg($media);
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

            $this->saveImageAsThumbnail($media, $sourcePath);

            // Free memory immediately — images can be large.
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

    private function generateVideoThumbnail(Media $media): string
    {
        if (!$this->isFfmpegAvailable()) {
            Log::warning('ThumbnailService: ffmpeg is required for video thumbnail generation.', [
                'media_id' => $media->id,
                'binary' => (string) config('services.ffmpeg.binary', 'ffmpeg'),
            ]);

            return 'skipped';
        }

        $sourcePath = null;
        $framePath = null;

        try {
            $mediaDisk = (string) config('filesystems.media_disk', 'public');
            $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);

            if ($sourcePath === null) {
                Log::warning('ThumbnailService: could not read source video file.', [
                    'media_id' => $media->id,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            $framePath = $sourcePath . '_frame.jpg';

            $binary = (string) config('services.ffmpeg.binary', 'ffmpeg');
            $seekAt = (string) config('services.ffmpeg.thumbnail_seek', '00:00:01.000');
            // Video frame extraction for large files (e.g. 4K/8K) needs a safer timeout.
            $baseTimeout = (int) config('services.ffmpeg.timeout', 30);
            $timeout = max(120, $baseTimeout);

            $processError = $this->extractVideoFrame($binary, $sourcePath, $framePath, $seekAt, $timeout);

            if ($processError !== null) {
                Log::warning('ThumbnailService: ffmpeg frame extraction failed.', [
                    'media_id' => $media->id,
                    'mime_type' => $media->mime_type,
                    'binary' => $binary,
                    'error' => $processError,
                ]);
                return 'failed';
            }

            // Frame is already scaled by ffmpeg, so avoid another full decode in GD.
            $this->storeGeneratedThumbnailFile($media, $framePath);

            gc_collect_cycles();

            return 'generated';
        } catch (\Throwable $e) {
            Log::warning('ThumbnailService: failed to generate video thumbnail.', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            if (is_string($framePath) && $framePath !== '' && file_exists($framePath)) {
                @unlink($framePath);
            }
            if (is_string($sourcePath) && $sourcePath !== '' && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    private function generateLargeImageThumbnailWithFfmpeg(Media $media): string
    {
        if (!$this->isFfmpegAvailable()) {
            Log::warning('ThumbnailService: oversized image requires ffmpeg fallback but ffmpeg is unavailable.', [
                'media_id' => $media->id,
                'width' => $media->width,
                'height' => $media->height,
                'memory_limit' => ini_get('memory_limit'),
                'binary' => (string) config('services.ffmpeg.binary', 'ffmpeg'),
            ]);

            return 'skipped';
        }

        $sourcePath = null;
        $framePath = null;

        try {
            $mediaDisk = (string) config('filesystems.media_disk', 'public');
            $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);

            if ($sourcePath === null) {
                Log::warning('ThumbnailService: could not read oversized source image.', [
                    'media_id' => $media->id,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            $framePath = $sourcePath . '_large.jpg';
            $binary = (string) config('services.ffmpeg.binary', 'ffmpeg');
            $timeout = max(30, (int) config('services.ffmpeg.timeout', 30));

            $scaleFilter = sprintf(
                'scale=%1$d:%1$d:force_original_aspect_ratio=decrease',
                self::THUMB_SIZE,
            );

            $process = new Process([
                $binary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $sourcePath,
                '-vf',
                $scaleFilter,
                '-frames:v',
                '1',
                '-q:v',
                '3',
                '-y',
                $framePath,
            ]);
            $process->setTimeout($timeout);
            $process->run();

            if (!$process->isSuccessful() || !file_exists($framePath) || filesize($framePath) === 0) {
                Log::warning('ThumbnailService: ffmpeg oversized-image thumbnail extraction failed.', [
                    'media_id' => $media->id,
                    'binary' => $binary,
                    'error' => trim($process->getErrorOutput() ?: $process->getOutput()),
                ]);
                return 'failed';
            }

            $thumbPath = $this->thumbPath($media);
            $thumbDisk = $this->thumbDisk();
            $read = fopen($framePath, 'rb');

            if (!is_resource($read)) {
                return 'failed';
            }

            $this->removeExistingThumbnailFromStorage($media, $thumbPath);

            try {
                Storage::disk($thumbDisk)->put($thumbPath, $read);
            } finally {
                fclose($read);
            }

            $media->update(['thumbnail_path' => $thumbPath]);

            return 'generated';
        } catch (\Throwable $e) {
            Log::warning('ThumbnailService: failed oversized-image fallback thumbnail generation.', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            if (is_string($framePath) && $framePath !== '' && file_exists($framePath)) {
                @unlink($framePath);
            }
            if (is_string($sourcePath) && $sourcePath !== '' && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    private function extractVideoFrame(
        string $binary,
        string $sourcePath,
        string $framePath,
        string $preferredSeek,
        int $timeout,
    ): ?string {
        $attempts = [];

        foreach ([$preferredSeek, '00:00:00.000', '00:00:00.200'] as $seek) {
            if (!in_array($seek, $attempts, true)) {
                $attempts[] = $seek;
            }
        }

        $errors = [];

        foreach ($attempts as $seek) {
            if (file_exists($framePath)) {
                @unlink($framePath);
            }

            $scaleFilter = sprintf(
                'scale=%1$d:%1$d:force_original_aspect_ratio=decrease',
                self::THUMB_SIZE,
            );

            $process = new Process([
                $binary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-threads',
                '1',
                '-ss',
                $seek,
                '-i',
                $sourcePath,
                '-vf',
                $scaleFilter,
                '-frames:v',
                '1',
                '-q:v',
                '3',
                '-y',
                $framePath,
            ]);
            $process->setTimeout($timeout > 0 ? $timeout : 30);

            try {
                $process->run();
            } catch (\Throwable $e) {
                $errors[] = sprintf('seek=%s process exception: %s', $seek, $e->getMessage());
                continue;
            }

            if ($process->isSuccessful() && file_exists($framePath) && filesize($framePath) > 0) {
                return null;
            }

            $errors[] = sprintf(
                'seek=%s %s',
                $seek,
                trim($process->getErrorOutput() ?: $process->getOutput() ?: 'unknown ffmpeg error'),
            );
        }

        return implode(' | ', $errors);
    }

    private function saveImageAsThumbnail(Media $media, string $sourcePath): void
    {
        $image = $this->manager->decodePath($sourcePath);

        $width = $image->width();
        $height = $image->height();

        if ($width > self::THUMB_SIZE || $height > self::THUMB_SIZE) {
            $image->scaleDown(self::THUMB_SIZE, self::THUMB_SIZE);
        }

        $jpeg = $image
            ->encode(new JpegEncoder(self::JPEG_QUALITY, progressive: true, strip: true))
            ->toString();

        $thumbPath = $this->thumbPath($media);
        $this->removeExistingThumbnailFromStorage($media, $thumbPath);
        Storage::disk($this->thumbDisk())->put($thumbPath, $jpeg);

        $media->update(['thumbnail_path' => $thumbPath]);

        unset($jpeg, $image);
    }

    private function storeGeneratedThumbnailFile(Media $media, string $sourcePath): void
    {
        $thumbPath = $this->thumbPath($media);
        $thumbDisk = $this->thumbDisk();
        $read = fopen($sourcePath, 'rb');

        if (!is_resource($read)) {
            throw new \RuntimeException('Could not open generated thumbnail file stream.');
        }

        $this->removeExistingThumbnailFromStorage($media, $thumbPath);

        try {
            Storage::disk($thumbDisk)->put($thumbPath, $read);
        } finally {
            fclose($read);
        }

        $media->update(['thumbnail_path' => $thumbPath]);
    }

    /**
     * Ensure stale thumbnail objects are removed before writing a new one.
     *
     * This covers both:
     * - the currently tracked DB thumbnail_path (legacy/custom paths), and
     * - the deterministic destination path used by current code.
     */
    private function removeExistingThumbnailFromStorage(Media $media, string $destinationPath): void
    {
        $primaryDisk = $this->thumbDisk();
        $candidateDisks = array_values(array_unique([$primaryDisk, 'public']));
        $paths = array_values(array_filter(array_unique([
            (string) ($media->thumbnail_path ?? ''),
            $destinationPath,
        ])));

        if (empty($paths)) {
            return;
        }

        foreach ($candidateDisks as $disk) {
            foreach ($paths as $path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    Log::warning('ThumbnailService: failed deleting existing thumbnail before replace.', [
                        'media_id' => $media->id,
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function isFfmpegAvailable(): bool
    {
        if ($this->ffmpegAvailable !== null) {
            return $this->ffmpegAvailable;
        }

        $binary = (string) config('services.ffmpeg.binary', 'ffmpeg');

        try {
            $check = new Process([$binary, '-version']);
            $check->setTimeout(5);
            $check->run();

            $this->ffmpegAvailable = $check->isSuccessful();
        } catch (\Throwable $e) {
            $this->ffmpegAvailable = false;
        }

        return $this->ffmpegAvailable;
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

        $primaryDisk = $this->thumbDisk();
        $candidateDisks = array_values(array_unique([$primaryDisk, 'public']));

        try {
            foreach ($candidateDisks as $disk) {
                Storage::disk($disk)->delete($media->thumbnail_path);
            }
        } catch (\Throwable $e) {
            Log::warning("ThumbnailService: failed to delete thumbnail.", [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
        }

        $media->update(['thumbnail_path' => null]);
    }

    /**
     * Update media width/height from the stored thumbnail file.
     *
     * Returns true when dimensions were successfully extracted and persisted.
     */
    public function syncDimensionsFromThumbnail(Media $media): bool
    {
        if (empty($media->thumbnail_path)) {
            return false;
        }

        $candidateDisks = array_values(array_unique([$this->thumbDisk(), 'public']));

        foreach ($candidateDisks as $disk) {
            try {
                if (!Storage::disk($disk)->exists($media->thumbnail_path)) {
                    continue;
                }

                $binary = Storage::disk($disk)->get($media->thumbnail_path);
                $size = @getimagesizefromstring($binary);

                if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                    continue;
                }

                $width = (int) $size[0];
                $height = (int) $size[1];

                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $media->update([
                    'width' => $width,
                    'height' => $height,
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::warning('ThumbnailService: failed to sync dimensions from thumbnail.', [
                    'media_id' => $media->id,
                    'disk' => $disk,
                    'thumbnail_path' => $media->thumbnail_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
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
     * Use the same disk as original media in production (R2),
     * and local public disk in local/public-only setups.
     */
    private function thumbDisk(): string
    {
        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        return $mediaDisk === '' ? 'public' : $mediaDisk;
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
