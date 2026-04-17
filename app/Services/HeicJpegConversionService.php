<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class HeicJpegConversionService
{
    private const THUMB_DIR = 'thumbnails';
    private const PREVIEW_DIR = 'previews';
    private const OUTPUT_JPEG_QUALITY = '2';
    private const SAMPLE_JPEG_QUALITY = '12';
    private const MIN_TARGET_LUMA = 112.0;
    private const DISPLAY_MAX_DIMENSION = 2400;
    private const DISPLAY_WEBP_QUALITY = '100';

    private ?bool $ffmpegAvailable = null;
    private array $lastErrors = [];

    public function generateWithStatus(Media $media): string
    {
        $this->clearLastError($media->id);

        if ($media->file_type !== 'image') {
            return 'skipped';
        }

        $mime = strtolower((string) ($media->mime_type ?? ''));
        if (!str_contains($mime, 'heic') && !str_contains($mime, 'heif')) {
            return 'skipped';
        }

        if (!$this->isFfmpegAvailable()) {
            $this->setLastError($media->id, 'ffmpeg binary is unavailable for HEIC conversion');
            return 'failed';
        }

        $sourcePath = $this->downloadSource((string) $media->file_path);
        if ($sourcePath === null) {
            $this->setLastError($media->id, "Could not read source HEIC path [{$media->file_path}]");
            return 'failed';
        }

        $convertedPath = $sourcePath . '_heic.jpg';

        try {
            $binary = (string) config('services.ffmpeg.binary', 'ffmpeg');
            $stream = $this->resolvePreferredHeicStream($binary, $sourcePath);
            $streamIndex = $stream['index'];

            $command = [
                $binary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $sourcePath,
                '-map',
                "0:v:{$streamIndex}",
                '-frames:v',
                '1',
            ];

            $upscaleFilter = $this->buildUpscaleFilter($stream);
            if ($upscaleFilter !== null) {
                $command[] = '-vf';
                $command[] = $upscaleFilter;
            }

            $command = array_merge($command, [
                '-pix_fmt',
                'yuvj420p',
                '-q:v',
                self::OUTPUT_JPEG_QUALITY,
                '-y',
                $convertedPath,
            ]);

            $process = new Process($command);
            $process->setTimeout(max(60, (int) config('services.ffmpeg.timeout', 30)));
            $process->run();

            if (!$process->isSuccessful() || !is_file($convertedPath) || filesize($convertedPath) === 0) {
                $error = trim($process->getErrorOutput() ?: $process->getOutput() ?: 'unknown ffmpeg error');
                $this->setLastError($media->id, "ffmpeg HEIC->JPG conversion failed: {$error}");
                return 'failed';
            }

            // Keep an unmodified conversion copy for preview derivatives.
            // Tone compensation can improve tiny thumbnails but may introduce
            // a washed/hazy look in full preview WebP for some HEIC files.
            $previewSourcePath = $convertedPath . '.preview-source.jpg';
            if (!@copy($convertedPath, $previewSourcePath)) {
                $previewSourcePath = $convertedPath;
            }

            $this->compensateIfDark($binary, $convertedPath);

            $thumbPath = $this->thumbPath($media);
            $this->removeExistingThumbnail($media, $thumbPath);

            $read = fopen($convertedPath, 'rb');
            if (!is_resource($read)) {
                $this->setLastError($media->id, 'Failed to open converted JPG stream.');
                return 'failed';
            }

            try {
                Storage::disk($this->thumbDisk())->put($thumbPath, $read);
            } finally {
                fclose($read);
            }

            $this->generatePreviewDerivatives($binary, $previewSourcePath, $media);

            $media->update(['thumbnail_path' => $thumbPath]);
            $this->clearLastError($media->id);

            return 'generated';
        } catch (\Throwable $e) {
            $this->setLastError($media->id, $e->getMessage());
            Log::warning('HeicJpegConversionService: failed conversion.', [
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            return 'failed';
        } finally {
            if (isset($previewSourcePath) && is_string($previewSourcePath) && $previewSourcePath !== $convertedPath && is_file($previewSourcePath)) {
                @unlink($previewSourcePath);
            }
            if (is_file($convertedPath)) {
                @unlink($convertedPath);
            }
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
        }
    }

    public function getLastErrorForMedia(int $mediaId): ?string
    {
        return $this->lastErrors[$mediaId] ?? null;
    }

    public function delete(Media $media): void
    {
        $paths = array_values(array_filter([
            (string) ($media->thumbnail_path ?? ''),
            $this->previewPath($media, 'avif'),
            $this->previewPath($media, 'webp'),
        ]));

        foreach ($paths as $path) {
            foreach (array_values(array_unique([$this->thumbDisk(), 'public'])) as $disk) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    Log::warning('HeicJpegConversionService: failed deleting derivative.', [
                        'media_id' => $media->id,
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        $media->update(['thumbnail_path' => null]);
    }

    public function syncDimensionsFromThumbnail(Media $media): bool
    {
        if (empty($media->thumbnail_path)) {
            return false;
        }

        foreach (array_values(array_unique([$this->thumbDisk(), 'public'])) as $disk) {
            try {
                if (!Storage::disk($disk)->exists((string) $media->thumbnail_path)) {
                    continue;
                }

                $binary = Storage::disk($disk)->get((string) $media->thumbnail_path);
                $size = @getimagesizefromstring($binary);
                if (!is_array($size) || empty($size[0]) || empty($size[1])) {
                    continue;
                }

                $media->update([
                    'width' => (int) $size[0],
                    'height' => (int) $size[1],
                ]);

                return true;
            } catch (\Throwable $e) {
                Log::warning('HeicJpegConversionService: failed syncing dimensions.', [
                    'media_id' => $media->id,
                    'disk' => $disk,
                    'path' => $media->thumbnail_path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return false;
    }

    private function resolvePreferredHeicStream(string $ffmpegBinary, string $sourcePath): array
    {
        $streams = $this->probeStreams($ffmpegBinary, $sourcePath);
        if (empty($streams)) {
            return [
                'index' => 0,
                'width' => 0,
                'height' => 0,
                'target_width' => 0,
                'target_height' => 0,
            ];
        }

        $color = array_values(array_filter($streams, static fn (array $s): bool => !self::isAuxiliaryStream($s)));
        $pool = !empty($color) ? $color : $streams;

        $dimCounts = [];
        foreach ($pool as $s) {
            $key = ((int) ($s['width'] ?? 0)) . 'x' . ((int) ($s['height'] ?? 0));
            $dimCounts[$key] = ($dimCounts[$key] ?? 0) + 1;
        }

        $uniqueDims = array_values(array_filter($pool, static function (array $s) use ($dimCounts): bool {
            $key = ((int) ($s['width'] ?? 0)) . 'x' . ((int) ($s['height'] ?? 0));
            return ($dimCounts[$key] ?? 0) === 1;
        }));

        $candidates = !empty($uniqueDims) ? $uniqueDims : $pool;

        $maxArea = 1;
        $targetWidth = 0;
        $targetHeight = 0;
        foreach ($candidates as $s) {
            $width = (int) ($s['width'] ?? 0);
            $height = (int) ($s['height'] ?? 0);
            $area = $width * $height;
            if ($area > $maxArea) {
                $maxArea = $area;
                $targetWidth = $width;
                $targetHeight = $height;
            }
        }

        // Prevent blurry preview selection: HEIC files often contain smaller
        // embedded preview streams. Prefer candidates near the largest area.
        $minAreaForSharp = max(1, (int) floor($maxArea * 0.65));
        $sharpCandidates = array_values(array_filter($candidates, static function (array $s) use ($minAreaForSharp): bool {
            $area = ((int) ($s['width'] ?? 0)) * ((int) ($s['height'] ?? 0));
            return $area >= $minAreaForSharp;
        }));
        $candidates = !empty($sharpCandidates) ? $sharpCandidates : $candidates;

        $best = $candidates[0];
        $bestScore = -INF;

        foreach ($candidates as $s) {
            $idx = (int) ($s['index'] ?? 0);
            $avgLuma = $this->sampleAverageLuma($ffmpegBinary, $sourcePath, $idx);
            $area = ((int) ($s['width'] ?? 0)) * ((int) ($s['height'] ?? 0));
            // Resolution first (to avoid blurry embedded previews), then brightness.
            $areaBonus = (($area / $maxArea) * 120.0);
            $profileBonus = str_contains(strtolower((string) ($s['profile'] ?? '')), 'main still picture') ? 6.0 : 0.0;
            $squarePenalty = ((int) ($s['width'] ?? 0) === (int) ($s['height'] ?? 0)) ? 4.0 : 0.0;
            $dimKey = ((int) ($s['width'] ?? 0)) . 'x' . ((int) ($s['height'] ?? 0));
            $repeatPenalty = (($dimCounts[$dimKey] ?? 0) > 8) ? 40.0 : 0.0;
            $lumaBonus = (($avgLuma / 255.0) * 10.0);
            $score = $areaBonus + $lumaBonus + $profileBonus - $squarePenalty - $repeatPenalty;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $s;
            }
        }

        return [
            'index' => (int) ($best['index'] ?? 0),
            'width' => (int) ($best['width'] ?? 0),
            'height' => (int) ($best['height'] ?? 0),
            'target_width' => $targetWidth,
            'target_height' => $targetHeight,
        ];
    }

    private function buildUpscaleFilter(array $stream): ?string
    {
        $width = (int) ($stream['width'] ?? 0);
        $height = (int) ($stream['height'] ?? 0);
        $targetWidth = (int) ($stream['target_width'] ?? 0);
        $targetHeight = (int) ($stream['target_height'] ?? 0);

        if ($width <= 0 || $height <= 0 || $targetWidth <= 0 || $targetHeight <= 0) {
            return null;
        }

        $area = $width * $height;
        $targetArea = $targetWidth * $targetHeight;
        if ($targetArea <= 0) {
            return null;
        }

        // Upscale only when selected stream is significantly smaller than the max stream.
        if ($area >= (int) floor($targetArea * 0.55)) {
            return null;
        }

        return "scale={$targetWidth}:{$targetHeight}:flags=lanczos,unsharp=5:5:0.55:5:5:0.0";
    }

    private function sampleAverageLuma(string $ffmpegBinary, string $sourcePath, int $streamIndex): float
    {
        $samplePath = tempnam(sys_get_temp_dir(), 'heic_sample_');
        if ($samplePath === false) {
            return 0.0;
        }
        $samplePath .= '.jpg';

        try {
            $process = new Process([
                $ffmpegBinary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $sourcePath,
                '-map',
                "0:v:{$streamIndex}",
                '-frames:v',
                '1',
                '-q:v',
                self::SAMPLE_JPEG_QUALITY,
                '-y',
                $samplePath,
            ]);
            $process->setTimeout(15);
            $process->run();

            if (!$process->isSuccessful() || !is_file($samplePath) || filesize($samplePath) === 0) {
                return 0.0;
            }

            $img = @imagecreatefromjpeg($samplePath);
            if (!$img) {
                return 0.0;
            }

            $w = imagesx($img);
            $h = imagesy($img);
            if ($w <= 0 || $h <= 0) {
                imagedestroy($img);
                return 0.0;
            }

            $stepX = max(1, intdiv($w, 80));
            $stepY = max(1, intdiv($h, 80));
            $sum = 0.0;
            $count = 0;

            for ($y = 0; $y < $h; $y += $stepY) {
                for ($x = 0; $x < $w; $x += $stepX) {
                    $rgb = imagecolorat($img, $x, $y);
                    $r = ($rgb >> 16) & 0xFF;
                    $g = ($rgb >> 8) & 0xFF;
                    $b = $rgb & 0xFF;
                    $sum += (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                    $count++;
                }
            }

            imagedestroy($img);

            return $count > 0 ? ($sum / $count) : 0.0;
        } catch (\Throwable $e) {
            return 0.0;
        } finally {
            if (is_file($samplePath)) {
                @unlink($samplePath);
            }
        }
    }

    private function compensateIfDark(string $ffmpegBinary, string $jpegPath): void
    {
        $avgLuma = $this->measureAverageLumaFromJpegPath($jpegPath);
        if ($avgLuma <= 0 || $avgLuma >= self::MIN_TARGET_LUMA) {
            return;
        }

        $delta = self::MIN_TARGET_LUMA - $avgLuma;
        $brightness = min(0.08, 0.01 + ($delta / 380.0));
        $gamma = min(1.22, 1.03 + ($delta / 180.0));
        $contrast = min(1.10, 1.01 + ($delta / 520.0));
        $saturation = min(1.10, 1.01 + ($delta / 520.0));

        $adjustedPath = $jpegPath . '.adj.jpg';

        try {
            $process = new Process([
                $ffmpegBinary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $jpegPath,
                '-vf',
                sprintf(
                    'eq=brightness=%s:contrast=%s:saturation=%s:gamma=%s',
                    number_format($brightness, 3, '.', ''),
                    number_format($contrast, 3, '.', ''),
                    number_format($saturation, 3, '.', ''),
                    number_format($gamma, 3, '.', '')
                ),
                '-q:v',
                self::OUTPUT_JPEG_QUALITY,
                '-y',
                $adjustedPath,
            ]);
            $process->setTimeout(20);
            $process->run();

            if ($process->isSuccessful() && is_file($adjustedPath) && filesize($adjustedPath) > 0) {
                @rename($adjustedPath, $jpegPath);

                // Intentionally avoid multi-pass boosts to prevent white haze.
            }
        } catch (\Throwable $e) {
            // Brightness compensation is best-effort; keep original conversion on failure.
        } finally {
            if (is_file($adjustedPath)) {
                @unlink($adjustedPath);
            }
        }
    }

    private function measureAverageLumaFromJpegPath(string $path): float
    {
        if (!is_file($path) || filesize($path) === 0) {
            return 0.0;
        }

        $img = @imagecreatefromjpeg($path);
        if (!$img) {
            return 0.0;
        }

        $w = imagesx($img);
        $h = imagesy($img);
        if ($w <= 0 || $h <= 0) {
            imagedestroy($img);
            return 0.0;
        }

        $stepX = max(1, intdiv($w, 80));
        $stepY = max(1, intdiv($h, 80));
        $sum = 0.0;
        $count = 0;

        for ($y = 0; $y < $h; $y += $stepY) {
            for ($x = 0; $x < $w; $x += $stepX) {
                $rgb = imagecolorat($img, $x, $y);
                $r = ($rgb >> 16) & 0xFF;
                $g = ($rgb >> 8) & 0xFF;
                $b = $rgb & 0xFF;
                $sum += (0.2126 * $r) + (0.7152 * $g) + (0.0722 * $b);
                $count++;
            }
        }

        imagedestroy($img);

        return $count > 0 ? ($sum / $count) : 0.0;
    }

    private function probeStreams(string $ffmpegBinary, string $sourcePath): array
    {
        $process = new Process([
            $this->resolveFfprobeBinary($ffmpegBinary),
            '-v',
            'error',
            '-select_streams',
            'v',
            '-show_entries',
            'stream=index,width,height,pix_fmt,profile',
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

            return array_values(array_map(static function (array $s): array {
                return [
                    'index' => (int) ($s['index'] ?? 0),
                    'width' => (int) ($s['width'] ?? 0),
                    'height' => (int) ($s['height'] ?? 0),
                    'pix_fmt' => strtolower((string) ($s['pix_fmt'] ?? '')),
                    'profile' => strtolower((string) ($s['profile'] ?? '')),
                ];
            }, $decoded['streams']));
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function isAuxiliaryStream(array $stream): bool
    {
        $pixFmt = strtolower((string) ($stream['pix_fmt'] ?? ''));
        if ($pixFmt === 'gray' || str_starts_with($pixFmt, 'gray')) {
            return true;
        }

        $profile = strtolower((string) ($stream['profile'] ?? ''));
        return $profile === 'rext' && ($pixFmt === '' || str_contains($pixFmt, 'gray'));
    }

    private function downloadSource(string $path): ?string
    {
        $disk = (string) config('filesystems.media_disk', 'public');
        $normalizedPath = ltrim($path, '/');
        $candidatePaths = array_values(array_unique([$normalizedPath, $path]));

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

        $tmpPath = tempnam(sys_get_temp_dir(), 'heic_src_');
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

    private function resolveFfprobeBinary(string $ffmpegBinary): string
    {
        if (preg_match('/ffmpeg\\.exe$/i', $ffmpegBinary) === 1) {
            return (string) preg_replace('/ffmpeg\\.exe$/i', 'ffprobe.exe', $ffmpegBinary);
        }

        if (preg_match('/ffmpeg$/i', $ffmpegBinary) === 1) {
            return (string) preg_replace('/ffmpeg$/i', 'ffprobe', $ffmpegBinary);
        }

        return 'ffprobe';
    }

    private function isFfmpegAvailable(): bool
    {
        if ($this->ffmpegAvailable !== null) {
            return $this->ffmpegAvailable;
        }

        try {
            $process = new Process([(string) config('services.ffmpeg.binary', 'ffmpeg'), '-version']);
            $process->setTimeout(5);
            $process->run();
            $this->ffmpegAvailable = $process->isSuccessful();
        } catch (\Throwable $e) {
            $this->ffmpegAvailable = false;
        }

        return $this->ffmpegAvailable;
    }

    private function thumbPath(Media $media): string
    {
        return self::THUMB_DIR . '/' . ($media->album_id ?? 0) . '/' . $media->id . '.jpg';
    }

    private function previewPath(Media $media, string $ext): string
    {
        return self::PREVIEW_DIR . '/' . ($media->album_id ?? 0) . '/' . $media->id . '.' . ltrim(strtolower($ext), '.');
    }

    private function thumbDisk(): string
    {
        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        return $mediaDisk === '' ? 'public' : $mediaDisk;
    }

    private function removeExistingThumbnail(Media $media, string $destinationPath): void
    {
        $candidateDisks = array_values(array_unique([$this->thumbDisk(), 'public']));
        $paths = array_values(array_filter(array_unique([
            (string) ($media->thumbnail_path ?? ''),
            $destinationPath,
        ])));

        foreach ($candidateDisks as $disk) {
            foreach ($paths as $path) {
                try {
                    Storage::disk($disk)->delete($path);
                } catch (\Throwable $e) {
                    Log::warning('HeicJpegConversionService: delete-before-write failed.', [
                        'media_id' => $media->id,
                        'disk' => $disk,
                        'path' => $path,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    private function generatePreviewDerivatives(
        string $ffmpegBinary,
        string $jpegPath,
        Media $media
    ): void
    {
        $webpTemp = $jpegPath . '.preview.webp';
        $avifTemp = $jpegPath . '.preview.avif';
        $displayScaleFilter = sprintf(
            "scale='if(gt(max(iw\\,ih)\\,%d),if(gt(iw\\,ih)\\,%d,-2),iw)':'if(gt(max(iw\\,ih)\\,%d),if(gt(ih\\,iw)\\,%d,-2),ih)':flags=lanczos",
            self::DISPLAY_MAX_DIMENSION,
            self::DISPLAY_MAX_DIMENSION,
            self::DISPLAY_MAX_DIMENSION,
            self::DISPLAY_MAX_DIMENSION,
        );
        $jpegSize = @getimagesize($jpegPath);
        $jpegWidth = (int) ($jpegSize[0] ?? 0);
        $jpegHeight = (int) ($jpegSize[1] ?? 0);
        $needsDownscale = max($jpegWidth, $jpegHeight) > self::DISPLAY_MAX_DIMENSION;
        $displayFilter = $needsDownscale
            ? ($displayScaleFilter . ',unsharp=5:5:0.45:5:5:0.0')
            : null;

        try {
            $webpCommand = [
                $ffmpegBinary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $jpegPath,
                '-frames:v',
                '1',
                '-c:v',
                'libwebp',
                '-lossless',
                '1',
                '-quality',
                self::DISPLAY_WEBP_QUALITY,
                '-preset',
                'picture',
                '-compression_level',
                '6',
                '-y',
                $webpTemp,
            ];
            if ($displayFilter !== null) {
                array_splice($webpCommand, 10, 0, [
                    '-vf',
                    $displayFilter,
                    '-sws_flags',
                    'lanczos+accurate_rnd+full_chroma_int',
                ]);
            }

            $webp = new Process($webpCommand);
            $webp->setTimeout(40);
            $webp->run();

            if ($webp->isSuccessful() && is_file($webpTemp) && filesize($webpTemp) > 0) {
                $this->storeDerivativeFile($this->previewPath($media, 'webp'), $webpTemp);
            }
        } catch (\Throwable $e) {
            // keep JPG thumbnail even if WEBP generation fails
        }

        try {
            $avifCommand = [
                $ffmpegBinary,
                '-hide_banner',
                '-loglevel',
                'error',
                '-i',
                $jpegPath,
                '-frames:v',
                '1',
                '-c:v',
                'libaom-av1',
                '-crf',
                '33',
                '-b:v',
                '0',
                '-cpu-used',
                '6',
                '-row-mt',
                '1',
                '-still-picture',
                '1',
                '-pix_fmt',
                'yuv420p',
                '-y',
                $avifTemp,
            ];
            if ($displayFilter !== null) {
                array_splice($avifCommand, 10, 0, [
                    '-vf',
                    $displayFilter,
                    '-sws_flags',
                    'lanczos+accurate_rnd+full_chroma_int',
                ]);
            }

            $avif = new Process($avifCommand);
            $avif->setTimeout(60);
            $avif->run();

            if ($avif->isSuccessful() && is_file($avifTemp) && filesize($avifTemp) > 0) {
                $this->storeDerivativeFile($this->previewPath($media, 'avif'), $avifTemp);
            }
        } catch (\Throwable $e) {
            // keep JPG/WEBP outputs when AVIF generation fails
        } finally {
            if (is_file($webpTemp)) {
                @unlink($webpTemp);
            }
            if (is_file($avifTemp)) {
                @unlink($avifTemp);
            }
        }
    }

    private function storeDerivativeFile(string $destinationPath, string $sourceTempPath): void
    {
        foreach (array_values(array_unique([$this->thumbDisk(), 'public'])) as $disk) {
            try {
                Storage::disk($disk)->delete($destinationPath);
            } catch (\Throwable $e) {
                // best effort cleanup
            }
        }

        $read = fopen($sourceTempPath, 'rb');
        if (!is_resource($read)) {
            return;
        }

        try {
            Storage::disk($this->thumbDisk())->put($destinationPath, $read);
        } finally {
            fclose($read);
        }
    }

    private function setLastError(int $mediaId, string $message): void
    {
        $this->lastErrors[$mediaId] = $message;
    }

    private function clearLastError(int $mediaId): void
    {
        unset($this->lastErrors[$mediaId]);
    }
}
