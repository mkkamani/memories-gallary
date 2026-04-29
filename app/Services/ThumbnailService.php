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
     * Better thumbnail resolution for large group images
     */
    private const THUMB_SIZE = 1600;

    /**
     * JPEG quality
     */
    private const JPEG_QUALITY = 95;

    /**
     * Keep full image height for group photos
     */
    private const SMART_CROP_HEIGHT_RATIO = 0.90;

    /**
     * Reduce unnecessary brightness adjustment
     */
    private const FF_BRIGHTNESS = 0.01;

    /**
     * Natural contrast
     */
    private const FF_CONTRAST = 1.05;

    /**
     * Slight saturation boost
     */
    private const FF_SATURATION = 1.03;

    /**
     * Much softer sharpening
     */
    private const FF_UNSHARP = 'luma_msize_x=7:luma_msize_y=7:luma_amount=2.0';

    /** * Estimated bytes-per-pixel used by GD while decoding/resizing. */
    private const GD_BYTES_PER_PIXEL = 5;

    /** * Directory inside the public storage disk where thumbnails live. */
    private const THUMB_DIR = 'thumbnails';

    /** * Keep a safety margin so decode work stays below PHP memory limit. */
    private const MEMORY_BUDGET_RATIO = 0.65;

    // -------------------------------------------------------------------------

    private ImageManager $manager;
    private ?bool $ffmpegAvailable = null;
    private array $lastErrors = [];

    public function __construct()
    {
        $this->manager = new ImageManager(
            new Driver(),
            autoOrientation: true,
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
        $this->clearLastError($media->id);

        if ($media->file_type === 'image') {
            return $this->generateImageThumbnail($media);
        }

        if ($media->file_type === 'video') {
            return $this->generateVideoThumbnail($media);
        }

        return 'skipped';
    }

    public function getLastErrorForMedia(int $mediaId): ?string
    {
        return $this->lastErrors[$mediaId] ?? null;
    }

    // -------------------------------------------------------------------------
    // Image thumbnail
    // -------------------------------------------------------------------------

    private function generateImageThumbnail(Media $media): string
    {
        $sourcePath = null;

        try {
            $mediaDisk = (string) config('filesystems.media_disk', 'public');
            $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);

            if ($sourcePath === null) {
                $errorMessage = "Could not read source file from disk [{$mediaDisk}] path [{$media->file_path}]";
                $this->setLastError($media->id, $errorMessage);
                Log::warning('ThumbnailService: could not read source file.', [
                    'media_id'  => $media->id,
                    'disk'      => $mediaDisk,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            // FIX: Always route HEIC/HEIF through ffmpeg — GD cannot decode these formats.
            // Also route oversized images through ffmpeg to avoid PHP OOM.
            if ($this->shouldUseFfmpegForImage($media, $sourcePath)) {
                return $this->generateLargeImageThumbnailWithFfmpeg($media, $sourcePath);
            }

            $this->saveImageAsThumbnail($media, $sourcePath);
            $this->clearLastError($media->id);

            // Free memory immediately — images can be large.
            gc_collect_cycles();

            return 'generated';
        } catch (\Throwable $e) {
            $this->setLastError($media->id, $e->getMessage());
            Log::warning('ThumbnailService: failed to generate thumbnail.', [
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

    // -------------------------------------------------------------------------
    // Video thumbnail
    // -------------------------------------------------------------------------

    private function generateVideoThumbnail(Media $media): string
    {
        if (!$this->isFfmpegAvailable()) {
            $this->setLastError(
                $media->id,
                'ffmpeg binary is unavailable for video thumbnail generation'
            );
            Log::warning('ThumbnailService: ffmpeg is required for video thumbnail generation.', [
                'media_id' => $media->id,
                'binary'   => (string) config('services.ffmpeg.binary', 'ffmpeg'),
            ]);

            return 'skipped';
        }

        $sourcePath = null;
        $framePath  = null;

        try {
            $mediaDisk  = (string) config('filesystems.media_disk', 'public');
            $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);

            if ($sourcePath === null) {
                $this->setLastError(
                    $media->id,
                    "Could not read source video file from disk [{$mediaDisk}] path [{$media->file_path}]"
                );
                Log::warning('ThumbnailService: could not read source video file.', [
                    'media_id'  => $media->id,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            $framePath = $sourcePath . '_frame.jpg';

            $binary     = (string) config('services.ffmpeg.binary', 'ffmpeg');
            $seekAt     = (string) config('services.ffmpeg.thumbnail_seek', '00:00:01.000');
            // Video frame extraction for large files (e.g. 4K/8K) needs a safer timeout.
            $baseTimeout = (int) config('services.ffmpeg.timeout', 30);
            $timeout     = max(120, $baseTimeout);

            $processError = $this->extractVideoFrame($binary, $sourcePath, $framePath, $seekAt, $timeout);

            if ($processError !== null) {
                $this->setLastError($media->id, "ffmpeg frame extraction failed: {$processError}");
                Log::warning('ThumbnailService: ffmpeg frame extraction failed.', [
                    'media_id'  => $media->id,
                    'mime_type' => $media->mime_type,
                    'binary'    => $binary,
                    'error'     => $processError,
                ]);
                return 'failed';
            }

            // Frame is already scaled by ffmpeg, so avoid another full decode in GD.
            $this->storeGeneratedThumbnailFile($media, $framePath);
            $this->clearLastError($media->id);

            gc_collect_cycles();

            return 'generated';
        } catch (\Throwable $e) {
            $this->setLastError($media->id, $e->getMessage());
            Log::warning('ThumbnailService: failed to generate video thumbnail.', [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
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

    // -------------------------------------------------------------------------
    // HEIC / HEIF / oversized-image → ffmpeg fallback
    // -------------------------------------------------------------------------

    private function generateLargeImageThumbnailWithFfmpeg(Media $media, ?string $existingSourcePath = null): string
    {
        if (!$this->isFfmpegAvailable()) {
            $this->setLastError(
                $media->id,
                'ffmpeg binary is unavailable for HEIC/large-image fallback generation'
            );
            Log::warning('ThumbnailService: oversized image requires ffmpeg fallback but ffmpeg is unavailable.', [
                'media_id'     => $media->id,
                'width'        => $media->width,
                'height'       => $media->height,
                'memory_limit' => ini_get('memory_limit'),
                'binary'       => (string) config('services.ffmpeg.binary', 'ffmpeg'),
            ]);

            return 'skipped';
        }

        $sourcePath     = null;
        $framePath      = null;
        $ownsSourcePath = false;

        try {
            // Re-use a source file already downloaded by the caller when available.
            if (is_string($existingSourcePath) && $existingSourcePath !== '' && file_exists($existingSourcePath)) {
                $sourcePath = $existingSourcePath;
            } else {
                $mediaDisk  = (string) config('filesystems.media_disk', 'public');
                $sourcePath = $this->downloadToTemporaryFile($mediaDisk, $media->file_path);
                $ownsSourcePath = true;
            }

            if ($sourcePath === null) {
                $this->setLastError(
                    $media->id,
                    "Could not read oversized source image from disk path [{$media->file_path}]"
                );
                Log::warning('ThumbnailService: could not read oversized source image.', [
                    'media_id'  => $media->id,
                    'file_path' => $media->file_path,
                ]);
                return 'failed';
            }

            $framePath = $sourcePath . '_large.jpg';
            $binary    = (string) config('services.ffmpeg.binary', 'ffmpeg');
            $timeout   = max(30, (int) config('services.ffmpeg.timeout', 30));

            // Probe streams to find the best (non-auxiliary, largest, color) stream.
            $sourceStreamLabel = $this->resolvePreferredImageStreamLabel($binary, $sourcePath);

            // Probe rotation metadata and build a transpose filter chain when needed.
            // This replaces the invalid -autorotate flag (which ffmpeg rejects as an
            // "input option applied to output" in virtually all builds).
            $rotationFilter = $this->resolveRotationFilter($binary, $sourcePath);

            // Filter graph (applied in order):
            //  1. optional transpose      → correct rotation from EXIF/metadata
            //  2. format=rgb24            → force 3-channel colour (safety net vs gray aux streams)
            //  3. crop=iw:ih*RATIO:0:...  → smart vertical crop (remove excess sky/ground)
            //  4. scale=W:H:decrease      → fit within THUMB_SIZE, preserve aspect ratio
            //  5. eq=brightness:contrast:saturation → subtle enhancement so faces are visible
            //  6. unsharp                 → mild sharpening for crispness at small size
            //  7. format=yuv420p          → normalise pixel format for JPEG encoder
            $cropRatio    = self::SMART_CROP_HEIGHT_RATIO;
            // Crop Y offset: 45% from top so upper-body/face area is kept
            $cropYExpr    = sprintf('(ih-ih*%s)*0.45', rtrim(rtrim(number_format($cropRatio, 4), '0'), '.'));
            $cropFilter   = sprintf('crop=iw:ih*%s:0:%s', rtrim(rtrim(number_format($cropRatio, 4), '0'), '.'), $cropYExpr);
            $eqFilter     = sprintf(
                'eq=brightness=%s:contrast=%s:saturation=%s',
                rtrim(rtrim(number_format(self::FF_BRIGHTNESS, 3), '0'), '.'),
                rtrim(rtrim(number_format(self::FF_CONTRAST,    3), '0'), '.'),
                rtrim(rtrim(number_format(self::FF_SATURATION,  3), '0'), '.'),
            );
            $unsharpFilter = 'unsharp=' . self::FF_UNSHARP;

            $parts = array_filter([
                $rotationFilter,
                'format=rgb24',
                $cropFilter,
                sprintf('scale=%d:%d:force_original_aspect_ratio=decrease', self::THUMB_SIZE, self::THUMB_SIZE),
                $eqFilter,
                $unsharpFilter,
                'format=yuv420p',
            ]);
            $scaleFilter = sprintf('[%s]%s[vthumb]', $sourceStreamLabel, implode(',', $parts));

            $process = new Process([
                $binary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-threads',
                '1',
                '-i',
                $sourcePath,
                '-filter_complex',
                $scaleFilter,
                '-map',
                '[vthumb]',
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
                $this->setLastError($media->id, "ffmpeg process exception: {$e->getMessage()}");
                Log::warning('ThumbnailService: ffmpeg process exception during HEIC/image thumbnail.', [
                    'media_id' => $media->id,
                    'error'    => $e->getMessage(),
                ]);
                return 'failed';
            }

            // FIX: $isBlank is now properly evaluated before being used.
            $isBlank = !file_exists($framePath) || filesize($framePath) === 0;

            if (!$process->isSuccessful() || $isBlank) {
                $ffmpegError = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'unknown ffmpeg error');
                $this->setLastError($media->id, "ffmpeg HEIC/image extraction failed or blank: {$ffmpegError}");
                Log::warning('ThumbnailService: ffmpeg oversized-image thumbnail extraction failed or blank.', [
                    'media_id' => $media->id,
                    'binary'   => $binary,
                    'error'    => $ffmpegError,
                    'is_blank' => $isBlank,
                ]);
                return 'failed';
            }

            $thumbPath = $this->thumbPath($media);
            $thumbDisk = $this->thumbDisk();
            $read      = fopen($framePath, 'rb');

            if (!is_resource($read)) {
                $this->setLastError($media->id, 'Could not open generated frame file for storage.');
                return 'failed';
            }

            $this->removeExistingThumbnailFromStorage($media, $thumbPath);

            try {
                Storage::disk($thumbDisk)->put($thumbPath, $read);
            } finally {
                fclose($read);
            }

            $media->update(['thumbnail_path' => $thumbPath]);
            $this->clearLastError($media->id);

            return 'generated';
        } catch (\Throwable $e) {
            $this->setLastError($media->id, $e->getMessage());
            Log::warning('ThumbnailService: failed oversized-image fallback thumbnail generation.', [
                'media_id' => $media->id,
                'error'    => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            if (is_string($framePath) && $framePath !== '' && file_exists($framePath)) {
                @unlink($framePath);
            }
            // Only delete the source file if we downloaded it ourselves.
            if ($ownsSourcePath && is_string($sourcePath) && $sourcePath !== '' && file_exists($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Video frame extraction
    // -------------------------------------------------------------------------

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

            // format=yuv420p normalizes pixel format so the JPEG encoder never sees
            // unsupported chroma (yuvj420p, yuv422p, 10-bit, etc.) → no black frames.
            // Rotation is handled inside the filter graph via resolveRotationFilter()
            // (reads the file's `rotate` metadata tag via ffprobe and emits a transpose
            // chain).  The -autorotate flag is NOT used — ffmpeg rejects it as an
            // "input option applied to output" on virtually all builds.
            $rotationFilter = $this->resolveRotationFilter($binary, $sourcePath);

            // Same pipeline as the HEIC/image path: rgb24 safety net + smart crop
            // + scale + enhancement filters. See generateLargeImageThumbnailWithFfmpeg
            // for full explanation of each stage.
            $cropRatio     = self::SMART_CROP_HEIGHT_RATIO;
            $cropYExpr     = sprintf('(ih-ih*%s)*0.45', rtrim(rtrim(number_format($cropRatio, 4), '0'), '.'));
            $cropFilter    = sprintf('crop=iw:ih*%s:0:%s', rtrim(rtrim(number_format($cropRatio, 4), '0'), '.'), $cropYExpr);
            $eqFilter      = sprintf(
                'eq=brightness=%s:contrast=%s:saturation=%s',
                rtrim(rtrim(number_format(self::FF_BRIGHTNESS, 3), '0'), '.'),
                rtrim(rtrim(number_format(self::FF_CONTRAST,    3), '0'), '.'),
                rtrim(rtrim(number_format(self::FF_SATURATION,  3), '0'), '.'),
            );
            $unsharpFilter = 'unsharp=' . self::FF_UNSHARP;

            $parts = array_filter([
                $rotationFilter,
                'format=rgb24',
                $cropFilter,
                sprintf('scale=%1$d:%1$d:force_original_aspect_ratio=decrease', self::THUMB_SIZE),
                $eqFilter,
                $unsharpFilter,
                'format=yuv420p',
            ]);
            $scaleFilter = '[0:v:0]' . implode(',', $parts) . '[vthumb]';

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
                '-filter_complex',
                $scaleFilter,
                '-map',
                '[vthumb]',
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
                return null; // success
            }

            $errors[] = sprintf(
                'seek=%s %s',
                $seek,
                trim($process->getErrorOutput() ?: $process->getOutput() ?: 'unknown ffmpeg error'),
            );
        }

        return implode(' | ', $errors);
    }

    // -------------------------------------------------------------------------
    // GD-based image resize (standard formats: JPEG, PNG, GIF, WebP, etc.)
    // -------------------------------------------------------------------------

    private function saveImageAsThumbnail(Media $media, string $sourcePath): void
    {
        $image  = $this->manager->decodePath($sourcePath);
        $width  = $image->width();
        $height = $image->height();

        // Smart vertical crop: remove excess sky/ground so faces fill the frame.
        // We keep SMART_CROP_HEIGHT_RATIO of the total height from the vertical centre,
        // biased slightly upward (offset 0.45 instead of 0.5) because subjects in group
        // photos tend to sit in the upper-centre of the frame.
        if ($height > 0 && self::SMART_CROP_HEIGHT_RATIO < 1.0) {
            $cropHeight = (int) round($height * self::SMART_CROP_HEIGHT_RATIO);
            $cropY      = (int) round(($height - $cropHeight) * 0.45);
            $image->crop($width, $cropHeight, 0, $cropY);
        }

        if ($image->width() > self::THUMB_SIZE || $image->height() > self::THUMB_SIZE) {
            $image->scaleDown(self::THUMB_SIZE, self::THUMB_SIZE);
        }

        // Enhance: slight brightness lift, contrast boost, and sharpening so faces
        // remain clearly visible in a small thumbnail.
        $image->brightness((int) round(self::FF_BRIGHTNESS * 100)); // Intervention uses -100..100
        $image->contrast((int) round((self::FF_CONTRAST - 1.0) * 100));
        $image->sharpen(12); // 0–100 scale; 12 = subtle, avoids halos

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
        $read      = fopen($sourcePath, 'rb');

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
        $primaryDisk    = $this->thumbDisk();
        $candidateDisks = array_values(array_unique([$primaryDisk, 'public']));
        $paths          = array_values(array_filter(array_unique([
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
                        'disk'     => $disk,
                        'path'     => $path,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    // -------------------------------------------------------------------------
    // ffmpeg helpers
    // -------------------------------------------------------------------------

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
     * Use ffprobe to list video streams and return the label (e.g. "0:v:0") that
     * corresponds to the primary color image — i.e. the largest non-auxiliary stream.
     *
     * HEIC containers can embed multiple image streams: the main photo, a depth map,
     * a thumbnail, or a monochrome auxiliary image.  Without this selection the first
     * stream (index 0) is used, which is often the wrong one and causes gray/blank output.
     */
    private function resolvePreferredImageStreamLabel(string $ffmpegBinary, string $sourcePath): string
    {
        $streams = $this->probeVideoStreams($ffmpegBinary, $sourcePath);
        if (empty($streams)) {
            return '0:v:0';
        }

        // Only consider streams that are not auxiliary/depth/gray.
        $colorStreams = array_values(array_filter(
            $streams,
            static fn(array $stream): bool => !self::isLikelyAuxiliaryHeicStream($stream),
        ));

        // Fallback to all streams if no color stream found.
        $candidateStreams = !empty($colorStreams) ? $colorStreams : $streams;

        // Prefer the largest area, then the lowest index.
        usort($candidateStreams, static function (array $a, array $b): int {
            $areaA = ((int) ($a['width'] ?? 0)) * ((int) ($a['height'] ?? 0));
            $areaB = ((int) ($b['width'] ?? 0)) * ((int) ($b['height'] ?? 0));
            if ($areaA === $areaB) {
                return ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0));
            }
            return $areaB <=> $areaA;
        });

        $bestIndex = (int) ($candidateStreams[0]['index'] ?? 0);
        return "0:v:{$bestIndex}";
    }

    /**
     * FIX: Added `pix_fmt` and `profile` to show_entries so that
     * isLikelyAuxiliaryHeicStream() receives the data it needs.
     * In the original code these fields were missing from the ffprobe query,
     * so the auxiliary-stream filter always saw empty strings and could not
     * correctly identify depth/gray streams — leading to gray thumbnails.
     */
    private function probeVideoStreams(string $ffmpegBinary, string $sourcePath): array
    {
        $ffprobeBinary = $this->resolveFfprobeBinary($ffmpegBinary);

        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v',
            // FIX: pix_fmt and profile are now included so auxiliary streams can be detected.
            '-show_entries',
            'stream=index,width,height,pix_fmt,profile:stream_disposition=dependent',
            '-of',
            'json',
            $sourcePath,
        ]);
        $process->setTimeout(10);

        try {
            $process->run();
            if (!$process->isSuccessful()) {
                return [];
            }

            $decoded = json_decode($process->getOutput(), true);
            if (!is_array($decoded) || !isset($decoded['streams']) || !is_array($decoded['streams'])) {
                return [];
            }

            $streams = [];
            foreach ($decoded['streams'] as $stream) {
                if (!is_array($stream) || !array_key_exists('index', $stream)) {
                    continue;
                }

                // With show_entries 'stream=...:stream_disposition=dependent',
                // ffprobe returns disposition flags as a nested 'disposition' object
                // on each stream.  Default dependent=0 (not auxiliary) when absent.
                $disposition = is_array($stream['disposition'] ?? null) ? $stream['disposition'] : [];

                $streams[] = [
                    'index'     => (int) $stream['index'],
                    'width'     => (int) ($stream['width'] ?? 0),
                    'height'    => (int) ($stream['height'] ?? 0),
                    'dependent' => (int) ($disposition['dependent'] ?? 0),
                    'pix_fmt'   => strtolower((string) ($stream['pix_fmt'] ?? '')),
                    'profile'   => strtolower((string) ($stream['profile'] ?? '')),
                ];
            }

            return $streams;
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function resolveFfprobeBinary(string $ffmpegBinary): string
    {
        if (preg_match('/ffmpeg\.exe$/i', $ffmpegBinary) === 1) {
            return (string) preg_replace('/ffmpeg\.exe$/i', 'ffprobe.exe', $ffmpegBinary);
        }

        if (preg_match('/ffmpeg$/i', $ffmpegBinary) === 1) {
            return (string) preg_replace('/ffmpeg$/i', 'ffprobe', $ffmpegBinary);
        }

        return 'ffprobe';
    }

    /**
     * Probe the file's rotation metadata via ffprobe and return the ffmpeg filter
     * chain string (e.g. "transpose=1" or "transpose=2,transpose=2") needed to
     * produce an upright image.  Returns an empty string when no rotation is needed.
     *
     * This is the correct, cross-version replacement for the broken -autorotate flag.
     * ffmpeg's -autorotate is a decoder-level option that is accepted on some builds
     * only as an input option (-autorotate 0/1 immediately before -i), but many builds
     * reject it entirely with "cannot be applied to output file" — so we never use it.
     *
     * Rotation → transpose mapping (ffmpeg transpose values):
     *   0° / 360° → no filter
     *   90°        → transpose=1  (rotate 90° CW)
     *   180°       → hflip,vflip  (equivalent to 180° rotation)
     *   270°       → transpose=2  (rotate 90° CCW, i.e. 270° CW)
     */
    private function resolveRotationFilter(string $ffmpegBinary, string $sourcePath): string
    {
        $ffprobeBinary = $this->resolveFfprobeBinary($ffmpegBinary);

        $process = new Process([
            $ffprobeBinary,
            '-v',
            'error',
            '-select_streams',
            'v:0',
            '-show_entries',
            'stream_tags=rotate,side_data=rotation',
            '-of',
            'json',
            $sourcePath,
        ]);
        $process->setTimeout(10);

        try {
            $process->run();
            if (!$process->isSuccessful()) {
                return '';
            }

            $decoded = json_decode($process->getOutput(), true);
            if (!is_array($decoded)) {
                return '';
            }

            // ffprobe reports rotation in two places depending on container/codec.
            $rotation = null;

            // 1. stream_tags rotate (common in MOV/MP4/HEIC)
            $tags = $decoded['streams'][0]['tags'] ?? [];
            if (isset($tags['rotate'])) {
                $rotation = (int) $tags['rotate'];
            }

            // 2. side_data rotation (H.264/HEVC display matrix)
            if ($rotation === null && !empty($decoded['streams'][0]['side_data_list'])) {
                foreach ($decoded['streams'][0]['side_data_list'] as $sideData) {
                    if (isset($sideData['rotation'])) {
                        $rotation = (int) $sideData['rotation'];
                        break;
                    }
                }
            }

            if ($rotation === null) {
                return '';
            }

            // Normalise to 0–359.
            $rotation = ((int) $rotation % 360 + 360) % 360;

            return match ($rotation) {
                90      => 'transpose=1',
                180     => 'hflip,vflip',
                270     => 'transpose=2',
                default => '',
            };
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * Returns true when an ffprobe stream entry looks like an auxiliary / depth /
     * monochrome stream in a HEIC container rather than the primary color photo.
     *
     * Heuristics:
     *  - pix_fmt starts with "gray"           → monochrome depth map
     *  - profile == "rext" + empty/gray fmt   → Range Extension profile used by depth/aux
     *  - disposition.dependent == 1           → stream is marked dependent (auxiliary)
     */
    private static function isLikelyAuxiliaryHeicStream(array $stream): bool
    {
        $pixFmt = strtolower((string) ($stream['pix_fmt'] ?? ''));

        // Monochrome / depth streams.
        if ($pixFmt === 'gray' || str_starts_with($pixFmt, 'gray')) {
            return true;
        }

        // Streams marked dependent in the container disposition.
        if ((int) ($stream['dependent'] ?? 0) === 1) {
            return true;
        }

        // HEVC Range-Extension profile combined with a gray or missing pixel format
        // is a strong indicator of a depth/auxiliary image.
        $profile = strtolower((string) ($stream['profile'] ?? ''));
        if ($profile === 'rext' && ($pixFmt === '' || str_contains($pixFmt, 'gray'))) {
            return true;
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Routing helpers
    // -------------------------------------------------------------------------

    /**
     * Decide whether an image must be decoded via ffmpeg instead of GD.
     *
     * GD cannot decode HEIC/HEIF at all, and it will run out of memory on very
     * large images (e.g. 50 MP RAW exports).  Both cases are routed to ffmpeg.
     */
    private function shouldUseFfmpegForImage(Media $media, ?string $sourcePath = null): bool
    {
        $mime = strtolower((string) ($media->mime_type ?? ''));

        // FIX: HEIC and HEIF are always sent through ffmpeg — GD cannot handle them.
        if (
            str_contains($mime, 'heic') ||
            str_contains($mime, 'heif') ||
            $mime === 'image/heic' ||
            $mime === 'image/heif' ||
            $mime === 'image/heic-sequence' ||
            $mime === 'image/heif-sequence'
        ) {
            return true;
        }

        // Use actual file dimensions when available (more reliable than DB metadata).
        if ($sourcePath !== null && $this->wouldSourceImageLikelyExceedMemory($sourcePath)) {
            return true;
        }

        return $this->wouldLikelyExceedMemory($media);
    }

    /**
     * Estimate GD memory use from stored dimensions to avoid fatal OOM.
     */
    private function wouldLikelyExceedMemory(Media $media): bool
    {
        $width  = (int) ($media->width ?? 0);
        $height = (int) ($media->height ?? 0);

        // If dimensions are unknown, allow processing and rely on exception handling.
        if ($width <= 0 || $height <= 0) {
            return false;
        }

        $estimatedBytes = (int) ($width * $height * self::GD_BYTES_PER_PIXEL);
        $memoryLimit    = $this->memoryLimitBytes();

        if ($memoryLimit <= 0) {
            return false;
        }

        $budget = (int) floor($memoryLimit * self::MEMORY_BUDGET_RATIO);
        return $estimatedBytes > $budget;
    }

    private function wouldSourceImageLikelyExceedMemory(string $sourcePath): bool
    {
        try {
            $size = @getimagesize($sourcePath);
            if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                return false;
            }

            $width  = (int) $size[0];
            $height = (int) $size[1];
            if ($width <= 0 || $height <= 0) {
                return false;
            }

            $estimatedBytes = (int) ($width * $height * self::GD_BYTES_PER_PIXEL);
            $memoryLimit    = $this->memoryLimitBytes();
            if ($memoryLimit <= 0) {
                return false;
            }

            $budget = (int) floor($memoryLimit * self::MEMORY_BUDGET_RATIO);
            return $estimatedBytes > $budget;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function memoryLimitBytes(): int
    {
        $value = trim((string) ini_get('memory_limit'));
        if ($value === '' || $value === '-1') {
            return 0;
        }

        $unit   = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g'     => $number * 1024 * 1024 * 1024,
            'm'     => $number * 1024 * 1024,
            'k'     => $number * 1024,
            default => (int) $value,
        };
    }

    // -------------------------------------------------------------------------
    // Storage helpers
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
        $normalizedPath  = ltrim($path, '/');
        $candidatePaths  = array_values(array_unique([$normalizedPath, $path]));

        $readStream = null;
        foreach ($candidatePaths as $candidatePath) {
            $readStream = Storage::disk($disk)->readStream($candidatePath);
            if (is_resource($readStream)) {
                break;
            }
        }

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

    // -------------------------------------------------------------------------
    // Public utility methods
    // -------------------------------------------------------------------------

    /**
     * Delete the stored thumbnail for a media item and clear the DB path.
     * Safe to call even if no thumbnail exists.
     */
    public function delete(Media $media): void
    {
        if (empty($media->thumbnail_path)) {
            return;
        }

        $primaryDisk    = $this->thumbDisk();
        $candidateDisks = array_values(array_unique([$primaryDisk, 'public']));

        try {
            foreach ($candidateDisks as $disk) {
                Storage::disk($disk)->delete($media->thumbnail_path);
            }
        } catch (\Throwable $e) {
            Log::warning('ThumbnailService: failed to delete thumbnail.', [
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
                $size   = @getimagesizefromstring($binary);

                if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                    continue;
                }

                $width  = (int) $size[0];
                $height = (int) $size[1];

                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $media->update([
                    'width'  => $width,
                    'height' => $height,
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::warning('ThumbnailService: failed to sync dimensions from thumbnail.', [
                    'media_id'       => $media->id,
                    'disk'           => $disk,
                    'thumbnail_path' => $media->thumbnail_path,
                    'error'          => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // Error tracking
    // -------------------------------------------------------------------------

    private function setLastError(int $mediaId, string $message): void
    {
        $this->lastErrors[$mediaId] = $message;
    }

    private function clearLastError(int $mediaId): void
    {
        unset($this->lastErrors[$mediaId]);
    }
}
