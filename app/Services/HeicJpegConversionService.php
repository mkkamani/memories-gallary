<?php

namespace App\Services;

use App\Models\Media;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class HeicJpegConversionService
{
    private const THUMB_DIR = 'thumbnails';
    private const PREVIEW_DIR = 'previews';
    private const OUTPUT_JPEG_QUALITY = '1';
    private const SAMPLE_JPEG_QUALITY = '12';
    private const MIN_TARGET_LUMA = 112.0;
    private const DISPLAY_MAX_DIMENSION = 2400;
    private const DISPLAY_WEBP_QUALITY = '92';
    private const SUPPORTED_HEIC_BRANDS = [
        'heic',
        'heix',
        'hevc',
        'hevx',
        'heim',
        'heis',
        'mif1',
        'msf1',
    ];

    private ?bool $ffmpegAvailable = null;
    private ?int $ffmpegMajorVersion = null;
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

        $sourcePath = $this->downloadSource((string) $media->file_path);
        if ($sourcePath === null) {
            $this->setLastError($media->id, "Could not read source HEIC path [{$media->file_path}]");
            return 'failed';
        }

        $sourceSignature = $this->detectSourceContainerSignature($sourcePath);
        if (!($sourceSignature['is_heic'] ?? false)) {
            $majorBrand = (string) ($sourceSignature['major_brand'] ?? '');
            $majorBrandLabel = $majorBrand !== '' ? $majorBrand : 'unknown';
            $compatible = (array) ($sourceSignature['compatible_brands'] ?? []);
            $compatibleLabel = !empty($compatible) ? implode(',', $compatible) : 'none';
            $headHex = (string) ($sourceSignature['head_hex'] ?? '');
            $headHexLabel = $headHex !== '' ? $headHex : 'n/a';
            $error = (string) ($sourceSignature['error'] ?? '');

            $message = "Source file is not HEIC by signature (major brand: {$majorBrandLabel}, compatible: {$compatibleLabel}, head: {$headHexLabel}).";
            if ($error !== '') {
                $message .= " {$error}";
            }

            $this->setLastError($media->id, $message);
            if (is_file($sourcePath)) {
                @unlink($sourcePath);
            }
            return 'failed';
        }

        if ($this->tryNodeApiConversion($media, $sourcePath)) {
            return 'generated';
        }

        $existingError = $this->getLastErrorForMedia($media->id);
        $this->setLastError(
            $media->id,
            $existingError !== null && $existingError !== ''
                ? "HEIC conversion failed via Node API: {$existingError}"
                : 'HEIC conversion failed: Node converter API did not return a successful response.'
        );

        if (is_file($sourcePath)) {
            @unlink($sourcePath);
        }

        return 'failed';
    }

    private function isNodeInputNotHeicError(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }

        $normalized = strtolower($error);

        return str_contains($normalized, 'not a heic image')
            || str_contains($normalized, 'not heic image')
            || str_contains($normalized, 'not a heif image')
            || str_contains($normalized, 'input buffer is not');
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
                'index' => null,
                'width' => 0,
                'height' => 0,
                'target_width' => 0,
                'target_height' => 0,
            ];
        }

        $color = array_values(array_filter($streams, static fn (array $s): bool => !self::isAuxiliaryStream($s)));
        $pool = !empty($color) ? $color : $streams;

        if ($this->looksLikeTiledHeic($pool)) {
            if ($this->supportsHeicTileAutoCompose($ffmpegBinary)) {
                return [
                    // ffmpeg >= 8 can auto-compose HEIC tile streams (xstack).
                    'index' => null,
                    'width' => 0,
                    'height' => 0,
                    'target_width' => 0,
                    'target_height' => 0,
                ];
            }

            $legacyFallback = $this->pickLegacyTiledStream($pool);
            if ($legacyFallback !== null) {
                return [
                    // ffmpeg 7 fallback: choose an independent color stream.
                    'index' => (int) ($legacyFallback['index'] ?? 0),
                    'width' => (int) ($legacyFallback['width'] ?? 0),
                    'height' => (int) ($legacyFallback['height'] ?? 0),
                    'target_width' => (int) ($legacyFallback['width'] ?? 0),
                    'target_height' => (int) ($legacyFallback['height'] ?? 0),
                ];
            }

            return [
                // Last resort for legacy ffmpeg builds: keep default stream selection.
                'index' => null,
                'width' => 0,
                'height' => 0,
                'target_width' => 0,
                'target_height' => 0,
            ];
        }

        $mainStill = array_values(array_filter($pool, static function (array $s): bool {
            $profile = strtolower((string) ($s['profile'] ?? ''));
            return str_contains($profile, 'main still picture');
        }));
        if (!empty($mainStill)) {
            $pool = $mainStill;
        }

        $maxArea = 1;
        $targetWidth = 0;
        $targetHeight = 0;
        foreach ($pool as $s) {
            $width = (int) ($s['width'] ?? 0);
            $height = (int) ($s['height'] ?? 0);
            $area = $width * $height;
            if ($area > $maxArea) {
                $maxArea = $area;
                $targetWidth = $width;
                $targetHeight = $height;
            }
        }

        // HEIC often carries small helper previews/depth maps.
        // Keep only near-native resolution streams to avoid blur.
        $minAreaForSharp = max(1, (int) floor($maxArea * 0.90));
        $candidates = array_values(array_filter($pool, static function (array $s) use ($minAreaForSharp): bool {
            $area = ((int) ($s['width'] ?? 0)) * ((int) ($s['height'] ?? 0));
            return $area >= $minAreaForSharp;
        }));
        if (empty($candidates)) {
            $candidates = $pool;
        }

        $best = $candidates[0];
        $bestScore = -INF;

        foreach ($candidates as $s) {
            $idx = (int) ($s['index'] ?? 0);
            $avgLuma = $this->sampleAverageLuma($ffmpegBinary, $sourcePath, $idx);
            $area = ((int) ($s['width'] ?? 0)) * ((int) ($s['height'] ?? 0));
            // Resolution has highest weight; luma/profile only break ties.
            $areaBonus = (($area / $maxArea) * 400.0);
            $profileBonus = str_contains(strtolower((string) ($s['profile'] ?? '')), 'main still picture') ? 6.0 : 0.0;
            $squarePenalty = ((int) ($s['width'] ?? 0) === (int) ($s['height'] ?? 0)) ? 4.0 : 0.0;
            $lumaBonus = (($avgLuma / 255.0) * 10.0);
            $score = $areaBonus + $lumaBonus + $profileBonus - $squarePenalty;

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

    private function looksLikeTiledHeic(array $streams): bool
    {
        if (count($streams) < 9) {
            return false;
        }

        $dimCounts = [];
        foreach ($streams as $s) {
            $w = (int) ($s['width'] ?? 0);
            $h = (int) ($s['height'] ?? 0);
            if ($w <= 0 || $h <= 0) {
                continue;
            }
            $key = "{$w}x{$h}";
            $dimCounts[$key] = ($dimCounts[$key] ?? 0) + 1;
        }

        if (empty($dimCounts)) {
            return false;
        }

        arsort($dimCounts);
        $topKey = (string) array_key_first($dimCounts);
        $topCount = (int) ($dimCounts[$topKey] ?? 0);
        [$w, $h] = array_map('intval', explode('x', $topKey));

        // HEIC tile sets usually expose many same-size color streams.
        return $topCount >= 9 && $w >= 256 && $h >= 256;
    }

    private function supportsHeicTileAutoCompose(string $ffmpegBinary): bool
    {
        $major = $this->getFfmpegMajorVersion($ffmpegBinary);

        return $major !== null && $major >= 8;
    }

    private function getFfmpegMajorVersion(string $ffmpegBinary): ?int
    {
        if ($this->ffmpegMajorVersion !== null) {
            return $this->ffmpegMajorVersion;
        }

        try {
            $process = new Process([$ffmpegBinary, '-version']);
            $process->setTimeout(5);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            $line = trim((string) strtok($process->getOutput(), "\n"));
            if (preg_match('/ffmpeg version\s+(\d+)/i', $line, $matches) === 1) {
                $this->ffmpegMajorVersion = (int) $matches[1];
                return $this->ffmpegMajorVersion;
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function pickLegacyTiledStream(array $streams): ?array
    {
        if (empty($streams)) {
            return null;
        }

        $independent = array_values(array_filter($streams, static function (array $s): bool {
            return ((int) ($s['dependent'] ?? 1)) === 0;
        }));
        $candidates = !empty($independent) ? $independent : $streams;

        $mainProfiles = array_values(array_filter($candidates, static function (array $s): bool {
            $profile = strtolower((string) ($s['profile'] ?? ''));
            return str_contains($profile, 'main 10') || str_contains($profile, 'main still picture');
        }));
        if (!empty($mainProfiles)) {
            $candidates = $mainProfiles;
        }

        usort($candidates, static function (array $a, array $b): int {
            $areaA = ((int) ($a['width'] ?? 0)) * ((int) ($a['height'] ?? 0));
            $areaB = ((int) ($b['width'] ?? 0)) * ((int) ($b['height'] ?? 0));

            if ($areaA !== $areaB) {
                return $areaB <=> $areaA;
            }

            $dependentA = (int) ($a['dependent'] ?? 1);
            $dependentB = (int) ($b['dependent'] ?? 1);
            if ($dependentA !== $dependentB) {
                return $dependentA <=> $dependentB;
            }

            return ((int) ($a['index'] ?? 0)) <=> ((int) ($b['index'] ?? 0));
        });

        return $candidates[0] ?? null;
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
            'stream=index,width,height,pix_fmt,profile,disposition',
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
                    'dependent' => (int) ((is_array($s['disposition'] ?? null) ? ($s['disposition']['dependent'] ?? 1) : 1)),
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

    private function detectSourceContainerSignature(string $sourcePath): array
    {
        $read = @fopen($sourcePath, 'rb');
        if (!is_resource($read)) {
            return [
                'is_heic' => false,
                'major_brand' => '',
                'compatible_brands' => [],
                'head_hex' => '',
                'error' => 'Failed to open source file for signature sniffing.',
            ];
        }

        try {
            $head = (string) fread($read, 64);
        } finally {
            fclose($read);
        }

        if (strlen($head) < 12) {
            return [
                'is_heic' => false,
                'major_brand' => '',
                'compatible_brands' => [],
                'head_hex' => bin2hex($head),
                'error' => 'File header is too short for ISO BMFF signature.',
            ];
        }

        $boxType = substr($head, 4, 4);
        if ($boxType !== 'ftyp') {
            return [
                'is_heic' => false,
                'major_brand' => '',
                'compatible_brands' => [],
                'head_hex' => bin2hex(substr($head, 0, 16)),
                'error' => 'Missing ftyp signature at expected header offset.',
            ];
        }

        $majorBrand = $this->sanitizeBrand(substr($head, 8, 4));
        $compatibleBrands = [];
        for ($i = 16; $i + 4 <= strlen($head); $i += 4) {
            $compatibleBrands[] = $this->sanitizeBrand(substr($head, $i, 4));
        }
        $compatibleBrands = array_values(array_filter(array_unique($compatibleBrands), static fn (string $brand): bool => $brand !== ''));

        $brandSet = array_values(array_unique(array_filter(array_map('strtolower', array_merge([$majorBrand], $compatibleBrands)))));
        $isHeic = !empty(array_intersect($brandSet, self::SUPPORTED_HEIC_BRANDS));

        return [
            'is_heic' => $isHeic,
            'major_brand' => $majorBrand,
            'compatible_brands' => $compatibleBrands,
            'head_hex' => bin2hex(substr($head, 0, 16)),
            'error' => '',
        ];
    }

    private function sanitizeBrand(string $brand): string
    {
        $trimmed = trim($brand);
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('/^[\x20-\x7E]{1,4}$/', $trimmed) === 1) {
            return $trimmed;
        }

        return '';
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
            ];
            if ($displayFilter !== null) {
                $webpCommand = array_merge($webpCommand, [
                    '-vf',
                    $displayFilter,
                    '-sws_flags',
                    'lanczos+accurate_rnd+full_chroma_int',
                ]);
            }

            $webpCommand = array_merge($webpCommand, [
                '-c:v',
                'libwebp',
                '-lossless',
                '0',
                '-quality',
                self::DISPLAY_WEBP_QUALITY,
                '-preset',
                'picture',
                '-compression_level',
                '6',
                '-color_range',
                'pc',
                '-y',
                $webpTemp,
            ]);

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

    private function tryNodeApiConversion(Media $media, string $sourcePath): bool
    {
        $baseUrl = $this->nodeApiBaseUrl();
        if ($baseUrl === '') {
            $this->setLastError(
                $media->id,
                'Node converter API URL is empty. Configure HEIC_CONVERTER_URL and clear Laravel config cache.'
            );
            return false;
        }

        $timeout = max(60, (int) config('services.heic_converter.timeout', 120));
        $connectTimeout = max(5, (int) config('services.heic_converter.connect_timeout', 20));
        $retries = max(1, (int) config('services.heic_converter.retries', 2));
        $retrySleepMs = max(500, (int) config('services.heic_converter.retry_sleep_ms', 2000));
        $includeAvif = (bool) config('services.heic_converter.include_avif', false);
        $mode = trim((string) config('services.heic_converter.mode', 'fast'));
        $previewMaxDimension = max(720, (int) config('services.heic_converter.preview_max_dimension', 1800));
        $useAsyncJobs = (bool) config('services.heic_converter.use_async_jobs', true);
        $pollIntervalMs = max(500, (int) config('services.heic_converter.poll_interval_ms', 2000));

        $this->warmupNodeApi($baseUrl);

        try {
            $request = Http::timeout($timeout)
                ->connectTimeout($connectTimeout)
                ->acceptJson()
                ->retry($retries, $retrySleepMs, null, false);

            $apiKey = trim((string) config('services.heic_converter.api_key', ''));
            if ($apiKey !== '') {
                $request = $request->withHeaders([
                    'X-Api-Key' => $apiKey,
                ]);
            }

            if ($useAsyncJobs) {
                $asyncResult = $this->startAndPollNodeJob(
                    $request,
                    $baseUrl,
                    $sourcePath,
                    $media,
                    $includeAvif,
                    $mode,
                    $previewMaxDimension,
                    $timeout,
                    $pollIntervalMs
                );

                if (($asyncResult['status'] ?? '') === 'done' && is_array($asyncResult['payload'] ?? null)) {
                    return $this->applyNodeConversionPayload($media, $asyncResult['payload']);
                }

                if (($asyncResult['status'] ?? '') === 'failed') {
                    // For decoder-specific failures, retry once with sync /convert
                    // in safer mode before giving up.
                    $asyncError = $this->getLastErrorForMedia($media->id);
                    if (!$this->isNodeInputNotHeicError($asyncError)) {
                        return false;
                    }

                    $this->setLastError(
                        $media->id,
                        'Node async job decoder failed; retrying sync /convert with safe mode.'
                    );
                }

                if (($asyncResult['status'] ?? '') === 'fallback') {
                    $this->setLastError(
                        $media->id,
                        'Node async job endpoints unavailable (jobs/jobs-url). Falling back to /convert.'
                    );
                }
            }

            try {
                $read = fopen($sourcePath, 'rb');
                if (!is_resource($read)) {
                    $this->setLastError($media->id, 'Node converter API: failed to open HEIC source stream.');
                    return false;
                }

                $syncMode = $mode;
                if ($this->isNodeInputNotHeicError($this->getLastErrorForMedia($media->id))) {
                    $syncMode = 'safe';
                }

                $response = $request
                    ->attach(
                        'file',
                        $read,
                        basename((string) ($media->file_name ?? ('media-' . $media->id . '.heic'))),
                        ['Content-Type' => 'image/heic']
                    )
                    ->post($baseUrl . '/convert', [
                        'quality' => (int) self::DISPLAY_WEBP_QUALITY,
                        'include_avif' => $includeAvif ? '1' : '0',
                        'mode' => $syncMode,
                        'preview_max_dimension' => (string) $previewMaxDimension,
                    ]);
            } finally {
                fclose($read);
            }
        } catch (\Throwable $e) {
            $this->setLastError($media->id, 'Node converter API request failed: ' . $e->getMessage());
            return false;
        }

        if (!$response->successful()) {
            $body = trim((string) $response->body());
            $this->setLastError(
                $media->id,
                "Node converter API HTTP {$response->status()} failure: " . ($body !== '' ? $body : 'empty response')
            );
            return false;
        }

        $payload = $response->json();
        return $this->applyNodeConversionPayload($media, $payload);
    }

    private function startAndPollNodeJob(
        \Illuminate\Http\Client\PendingRequest $request,
        string $baseUrl,
        string $sourcePath,
        Media $media,
        bool $includeAvif,
        string $mode,
        int $previewMaxDimension,
        int $timeout,
        int $pollIntervalMs
    ): array {
        try {
            $payload = [
                'file_name' => (string) ($media->file_name ?? ('media-' . $media->id . '.heic')),
                'quality' => (int) self::DISPLAY_WEBP_QUALITY,
                'include_avif' => $includeAvif ? '1' : '0',
                'mode' => $mode,
                'preview_max_dimension' => (string) $previewMaxDimension,
            ];

            $sourceUrl = $this->resolveNodeSourceUrl($media);
            if ($sourceUrl !== null) {
                $jobsUrlResponse = (clone $request)->asJson()->post($baseUrl . '/jobs-url', array_merge($payload, [
                    'source_url' => $sourceUrl,
                ]));

                if ($jobsUrlResponse->successful()) {
                    $jobsUrlPayload = $this->decodeNodeJsonPayload($jobsUrlResponse);
                    $jobId = $this->extractNodeJobId($jobsUrlPayload);
                    if ($jobId !== '') {
                        $result = $this->pollNodeJob($request, $baseUrl, $media, $jobId, $timeout, $pollIntervalMs);
                        if (($result['status'] ?? '') === 'done') {
                            return $result;
                        }

                        // Some deployments cannot fetch signed source_url correctly
                        // (proxy/CDN/auth payload instead of image bytes), which makes
                        // Node report "input buffer is not a HEIC image".
                        // In that case retry via multipart upload to the same Node API.
                        if (($result['status'] ?? '') === 'failed'
                            && $this->isNodeInputNotHeicError($this->getLastErrorForMedia($media->id))) {
                            $this->setLastError(
                                $media->id,
                                'Node jobs-url fetch returned non-HEIC bytes; retrying multipart /jobs upload.'
                            );
                        } else {
                            return $result;
                        }
                    }
                    // Some Node deployments return a successful jobs-url response
                    // with a non-standard payload shape. Do not fail hard here;
                    // fallback to multipart /jobs path below.
                    $this->setLastError($media->id, 'Node converter API: jobs-url response had no recognizable job id; retrying multipart /jobs upload.');
                }

                if (!$jobsUrlResponse->successful()) {
                    $jobsUrlStatus = $jobsUrlResponse->status();
                    if ($jobsUrlStatus !== 404 && $jobsUrlStatus !== 405) {
                        $body = trim((string) $jobsUrlResponse->body());
                        $this->setLastError(
                            $media->id,
                            "Node converter jobs-url failed HTTP {$jobsUrlStatus}: " . ($body !== '' ? $body : 'empty response')
                        );
                        return ['status' => 'failed'];
                    }
                }
            }

            $read = fopen($sourcePath, 'rb');
            if (!is_resource($read)) {
                $this->setLastError($media->id, 'Node converter API: failed to open HEIC source stream.');
                return ['status' => 'failed'];
            }

            try {
                $jobResponse = (clone $request)
                    ->attach('file', $read, $payload['file_name'])
                    ->post($baseUrl . '/jobs', $payload);
            } finally {
                fclose($read);
            }

            if (!$jobResponse->successful()) {
                $status = $jobResponse->status();
                // If async endpoints are unavailable, let sync /convert fallback run.
                if ($status === 404 || $status === 405) {
                    return ['status' => 'fallback'];
                }
                $body = trim((string) $jobResponse->body());
                $this->setLastError(
                    $media->id,
                    "Node converter job create failed HTTP {$status}: " . ($body !== '' ? $body : 'empty response')
                );
                return ['status' => 'failed'];
            }

            $jobPayload = $this->decodeNodeJsonPayload($jobResponse);
            $jobId = $this->extractNodeJobId($jobPayload);
            if ($jobId === '') {
                $this->setLastError($media->id, 'Node converter API: missing async job id.');
                return ['status' => 'failed'];
            }

            return $this->pollNodeJob($request, $baseUrl, $media, $jobId, $timeout, $pollIntervalMs);
        } catch (\Throwable $e) {
            $this->setLastError($media->id, 'Node converter API async job request failed: ' . $e->getMessage());
            return ['status' => 'failed'];
        }
    }

    private function pollNodeJob(
        \Illuminate\Http\Client\PendingRequest $request,
        string $baseUrl,
        Media $media,
        string $jobId,
        int $timeout,
        int $pollIntervalMs
    ): array {
        $deadline = microtime(true) + $timeout;
        while (microtime(true) < $deadline) {
            $statusResponse = $request->get($baseUrl . '/jobs/' . urlencode($jobId));
            if (!$statusResponse->successful()) {
                $status = $statusResponse->status();
                if ($status === 404) {
                    $this->setLastError($media->id, 'Node converter API: async job not found.');
                    return ['status' => 'failed'];
                }
                usleep($pollIntervalMs * 1000);
                continue;
            }

            $statusPayload = $statusResponse->json();
            if (!is_array($statusPayload)) {
                usleep($pollIntervalMs * 1000);
                continue;
            }

            $status = strtolower((string) ($statusPayload['status'] ?? ''));
            if ($status === 'done') {
                $result = $statusPayload['result'] ?? null;
                if (is_array($result)) {
                    return [
                        'status' => 'done',
                        'payload' => $result,
                    ];
                }
                $this->setLastError($media->id, 'Node converter API async job completed without payload.');
                return ['status' => 'failed'];
            }

            if ($status === 'failed') {
                $error = (string) ($statusPayload['error'] ?? 'conversion failed');
                $this->setLastError($media->id, 'Node converter API async job failed: ' . $error);
                return ['status' => 'failed'];
            }

            usleep($pollIntervalMs * 1000);
        }

        $this->setLastError($media->id, "Node converter API async job timed out after {$timeout}s.");
        return ['status' => 'failed'];
    }

    /**
     * Accept multiple possible Node API job id shapes:
     * - { job_id: "..." }
     * - { jobId: "..." }
     * - { id: "..." }
     * - { data: { job_id|jobId|id: "..." } }
     */
    private function extractNodeJobId(mixed $payload): string
    {
        if (!is_array($payload)) {
            return '';
        }

        $candidates = [
            $payload['job_id'] ?? null,
            $payload['jobId'] ?? null,
            $payload['id'] ?? null,
        ];

        if (isset($payload['data']) && is_array($payload['data'])) {
            $candidates[] = $payload['data']['job_id'] ?? null;
            $candidates[] = $payload['data']['jobId'] ?? null;
            $candidates[] = $payload['data']['id'] ?? null;
        }

        foreach ($candidates as $candidate) {
            $jobId = trim((string) ($candidate ?? ''));
            if ($jobId !== '') {
                return $jobId;
            }
        }

        return '';
    }

    /**
     * Node service can occasionally return JSON with a non-json content-type.
     * Parse body defensively so we still read job_id from HTTP 202 payloads.
     */
    private function decodeNodeJsonPayload(\Illuminate\Http\Client\Response $response): ?array
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            return $decoded;
        }

        $body = trim((string) $response->body());
        if ($body === '') {
            return null;
        }

        $fallback = json_decode($body, true);
        return is_array($fallback) ? $fallback : null;
    }

    private function resolveNodeSourceUrl(Media $media): ?string
    {
        $disk = (string) config('filesystems.media_disk', 'public');
        $path = ltrim((string) ($media->file_path ?? ''), '/');
        if ($path === '') {
            return null;
        }

        try {
            $storage = Storage::disk($disk);
            if (method_exists($storage, 'temporaryUrl')) {
                $candidatePaths = array_values(array_unique([$path, (string) ($media->file_path ?? '')]));
                foreach ($candidatePaths as $candidatePath) {
                    $candidatePath = (string) $candidatePath;
                    if ($candidatePath === '') {
                        continue;
                    }

                    try {
                        $url = (string) call_user_func([$storage, 'temporaryUrl'], $candidatePath, now()->addMinutes(20));
                        if ($url !== '') {
                            return $url;
                        }
                    } catch (\Throwable $e) {
                        // Try next candidate path.
                    }
                }
            }

            if ($disk === 'public') {
                return asset('storage/' . $path);
            }

            $baseUrl = trim((string) config("filesystems.disks.{$disk}.url", ''));
            if ($baseUrl !== '') {
                return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
            }
        } catch (\Throwable $e) {
            return null;
        }

        return null;
    }

    private function applyNodeConversionPayload(Media $media, mixed $payload): bool
    {
        if (!is_array($payload) || !($payload['ok'] ?? false)) {
            $this->setLastError($media->id, 'Node converter API returned invalid payload.');
            return false;
        }

        $webpBase64 = (string) ($payload['webp_base64'] ?? '');
        $thumbBase64 = (string) ($payload['thumbnail_jpeg_base64'] ?? '');
        $avifBase64 = (string) ($payload['avif_base64'] ?? '');

        $webpBinary = base64_decode($webpBase64, true);
        $thumbBinary = base64_decode($thumbBase64, true);
        $avifBinary = $avifBase64 !== '' ? base64_decode($avifBase64, true) : false;
        $width = (int) ($payload['width'] ?? 0);
        $height = (int) ($payload['height'] ?? 0);

        if (!is_string($webpBinary) || $webpBinary === '' || !is_string($thumbBinary) || $thumbBinary === '') {
            $this->setLastError($media->id, 'Node converter API response is missing webp/jpg binary data.');
            return false;
        }

        if ($width <= 0 || $height <= 0) {
            $this->setLastError($media->id, 'Node converter API response is missing original image width/height.');
            return false;
        }

        try {
            $thumbPath = $this->thumbPath($media);
            $this->removeExistingThumbnail($media, $thumbPath);
            Storage::disk($this->thumbDisk())->put($thumbPath, $thumbBinary);

            $this->storeDerivativeBinary($this->previewPath($media, 'webp'), $webpBinary);

            if (is_string($avifBinary) && $avifBinary !== '') {
                $this->storeDerivativeBinary($this->previewPath($media, 'avif'), $avifBinary);
            } else {
                $this->removeDerivativeFile($this->previewPath($media, 'avif'));
            }

            $updates = [
                'thumbnail_path' => $thumbPath,
                'width' => $width,
                'height' => $height,
            ];

            $media->update($updates);
            $this->clearLastError($media->id);

            return true;
        } catch (\Throwable $e) {
            $this->setLastError($media->id, 'Node converter API store failed: ' . $e->getMessage());
            return false;
        }
    }

    private function warmupNodeApi(string $baseUrl): void
    {
        try {
            $apiKey = trim((string) config('services.heic_converter.api_key', ''));
            $warmupTimeout = max(5, (int) config('services.heic_converter.warmup_timeout', 25));

            $request = Http::timeout($warmupTimeout)->connectTimeout($warmupTimeout);
            if ($apiKey !== '') {
                $request = $request->withHeaders([
                    'X-Api-Key' => $apiKey,
                ]);
            }

            $request->get($baseUrl . '/health');
        } catch (\Throwable $e) {
            // Best effort for cold-start reduction only.
        }
    }

    private function nodeApiBaseUrl(): string
    {
        return rtrim(trim((string) config('services.heic_converter.url', '')), '/');
    }

    private function removeDerivativeFile(string $destinationPath): void
    {
        foreach (array_values(array_unique([$this->thumbDisk(), 'public'])) as $disk) {
            try {
                Storage::disk($disk)->delete($destinationPath);
            } catch (\Throwable $e) {
                // best effort cleanup
            }
        }
    }

    private function storeDerivativeBinary(string $destinationPath, string $binary): void
    {
        $this->removeDerivativeFile($destinationPath);
        Storage::disk($this->thumbDisk())->put($destinationPath, $binary);
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
