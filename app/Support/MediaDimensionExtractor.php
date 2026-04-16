<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaDimensionExtractor
{
    /**
     * Extract dimensions from an uploaded file before/after storage.
     *
     * @return array{0:int|null,1:int|null}
     */
    public static function fromUploadedFile(UploadedFile $file, ?string $mimeType = null): array
    {
        $mimeType = (string) ($mimeType ?: $file->getMimeType() ?: 'application/octet-stream');
        $path = $file->getRealPath();

        if (!$path || !is_file($path)) {
            return [null, null];
        }

        return self::fromLocalPath($path, $mimeType, $file->getClientOriginalName());
    }

    /**
     * Extract dimensions from a file that already exists on a storage disk.
     *
     * @return array{0:int|null,1:int|null}
     */
    public static function fromStorage(string $disk, string $path, ?string $mimeType = null): array
    {
        $storage = Storage::disk($disk);
        $mimeType = (string) ($mimeType ?: 'application/octet-stream');

        try {
            $localPath = $storage->path($path);
            if (is_string($localPath) && is_file($localPath)) {
                return self::fromLocalPath($localPath, $mimeType, basename($path));
            }
        } catch (\Throwable) {
            // Non-local disks may not provide a usable local path.
        }

        $stream = $storage->readStream($path);
        if ($stream === false) {
            return [null, null];
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'media-dim-');
        if ($tmpPath === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            return [null, null];
        }

        $tmpHandle = fopen($tmpPath, 'wb');
        if ($tmpHandle === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            @unlink($tmpPath);
            return [null, null];
        }

        try {
            stream_copy_to_stream($stream, $tmpHandle);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
            fclose($tmpHandle);
        }

        try {
            return self::fromLocalPath($tmpPath, $mimeType, basename($path));
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * Extract dimensions from in-memory binary content.
     *
     * @return array{0:int|null,1:int|null}
     */
    public static function fromBinary(string $contents, ?string $mimeType = null, ?string $filename = null): array
    {
        $mimeType = strtolower((string) ($mimeType ?: 'application/octet-stream'));
        $filename = (string) ($filename ?: '');

        if (str_starts_with($mimeType, 'image/')) {
            if ($mimeType !== 'image/svg+xml') {
                $size = @getimagesizefromstring($contents);
                if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                    return [(int) $size[0], (int) $size[1]];
                }
            }

            if (self::looksLikeHeic($mimeType, $filename)) {
                return self::extractHeicDimensionsFromBinary($contents);
            }

            return [null, null];
        }

        if (str_starts_with($mimeType, 'video/')) {
            return self::extractVideoDimensionsFromBinary($contents, $filename);
        }

        return [null, null];
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private static function fromLocalPath(string $path, string $mimeType, ?string $filename = null): array
    {
        $mimeType = strtolower($mimeType);
        $filename = (string) ($filename ?: basename($path));

        if (str_starts_with($mimeType, 'image/')) {
            if ($mimeType !== 'image/svg+xml') {
                $size = @getimagesize($path);
                if (is_array($size) && !empty($size[0]) && !empty($size[1])) {
                    return [(int) $size[0], (int) $size[1]];
                }
            }

            if (self::looksLikeHeic($mimeType, $filename)) {
                $contents = @file_get_contents($path);
                if ($contents !== false) {
                    return self::extractHeicDimensionsFromBinary($contents);
                }
            }

            return [null, null];
        }

        if (!str_starts_with($mimeType, 'video/')) {
            return [null, null];
        }

        return self::extractVideoDimensionsFromFile($path);
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private static function extractHeicDimensionsFromBinary(string $contents): array
    {
        $offset = 0;

        while (($pos = strpos($contents, 'ispe', $offset)) !== false) {
            if (strlen($contents) >= $pos + 16) {
                $width = unpack('N', substr($contents, $pos + 8, 4))[1] ?? 0;
                $height = unpack('N', substr($contents, $pos + 12, 4))[1] ?? 0;

                if ($width > 0 && $height > 0) {
                    return [(int) $width, (int) $height];
                }
            }

            $offset = $pos + 4;
        }

        return [null, null];
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private static function extractVideoDimensionsFromBinary(string $contents, ?string $filename = null): array
    {
        $extension = pathinfo((string) $filename, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.' . $extension : '.bin';
        $tmpPath = tempnam(sys_get_temp_dir(), 'video-dim-');
        if ($tmpPath === false) {
            return [null, null];
        }

        $tmpFile = $tmpPath . $suffix;
        @rename($tmpPath, $tmpFile);

        if (@file_put_contents($tmpFile, $contents) === false) {
            @unlink($tmpFile);
            return [null, null];
        }

        try {
            return self::extractVideoDimensionsFromFile($tmpFile);
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private static function extractVideoDimensionsFromFile(string $path): array
    {
        try {
            $analyzer = new \getID3();
            $info = $analyzer->analyze($path);
        } catch (\Throwable) {
            return [null, null];
        }

        $width = self::normalizeDimensionValue(
            $info['video']['resolution_x']
                ?? $info['quicktime']['video']['resolution_x']
                ?? null,
        );

        $height = self::normalizeDimensionValue(
            $info['video']['resolution_y']
                ?? $info['quicktime']['video']['resolution_y']
                ?? null,
        );

        if ($width <= 0 || $height <= 0) {
            return [null, null];
        }

        return [$width, $height];
    }

    /**
     * @return array{0:int|null,1:int|null}
     */
    private static function normalizeDimensionValue(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) || is_numeric($value)) {
            return (int) round((float) $value);
        }
        return 0;
    }

    private static function looksLikeHeic(string $mimeType, string $filename): bool
    {
        if (str_contains($mimeType, 'heic') || str_contains($mimeType, 'heif')) {
            return true;
        }

        return (bool) preg_match('/\.(heic|heif)$/i', $filename);
    }

}
