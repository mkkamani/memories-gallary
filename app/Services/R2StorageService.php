<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use App\Interfaces\StorageServiceInterface;

class R2StorageService implements StorageServiceInterface
{
    protected string $disk;

    public function __construct()
    {
        $this->disk = (string) config('filesystems.media_disk', 'public');
    }

    protected function disk()
    {
        $config = config("filesystems.disks.{$this->disk}", []);

        if (($config['driver'] ?? null) === 's3' && empty($config['bucket'])) {
            throw new RuntimeException(sprintf(
                'The [%s] filesystem disk is missing its bucket configuration. Set MEDIA_DISK=public for local uploads or configure the required bucket env values for [%s].',
                $this->disk,
                $this->disk,
            ));
        }

        return Storage::disk($this->disk);
    }

    protected function diskDriver(): string
    {
        return (string) config("filesystems.disks.{$this->disk}.driver", 'local');
    }

    protected function cdnUrl(): string
    {
        return rtrim((string) config('filesystems.cdn_url', ''), '/');
    }

    protected function cdnPathUrl(string $path): string
    {
        return $this->cdnUrl() . '/' . ltrim($path, '/');
    }

    protected function shouldUseCdn(): bool
    {
        return $this->disk !== 'public' && $this->cdnUrl() !== '';
    }

    /**
     * Upload a file to the R2 bucket under the given directory path.
     * Returns the full stored key (path) of the uploaded file.
     */
    public function uploadFile($file, string $path): string
    {
        // Sanitize the original filename: replace any character that is not
        // alphanumeric, a dot, a hyphen, or an underscore with an underscore.
        // This prevents spaces and other shell/URL-unsafe characters from
        // appearing in the stored key and the generated presigned URL.
        $originalName = preg_replace(
            "/[^a-zA-Z0-9.\-_]/",
            "_",
            $file->getClientOriginalName(),
        );
        $filename = uniqid() . "_" . $originalName;
        $directory = rtrim($path, "/");
        $fullPath = $directory . "/" . $filename;

        $result = $this->disk()->putFileAs(
            $directory,
            $file,
            $filename,
        );

        if ($result === false) {
            Log::error("R2StorageService: failed to upload file.", [
                "directory" => $directory,
                "filename" => $filename,
            ]);
            throw new \RuntimeException(
                "Failed to upload file \"{$filename}\" to R2 storage path \"{$directory}\".",
            );
        }

        return $fullPath;
    }

    /**
     * Upload a file using the provided filename under the given directory.
     */
    public function uploadFileAs($file, string $path, string $filename): string
    {
        $safeFilename = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string) $filename);
        $directory = rtrim($path, '/');
        $fullPath = $directory . '/' . $safeFilename;

        $result = $this->disk()->putFileAs(
            $directory,
            $file,
            $safeFilename,
        );

        if ($result === false) {
            Log::error('R2StorageService: failed to upload file with explicit name.', [
                'directory' => $directory,
                'filename' => $safeFilename,
            ]);
            throw new \RuntimeException(
                "Failed to upload file \"{$safeFilename}\" to R2 storage path \"{$directory}\".",
            );
        }

        return $fullPath;
    }

    /**
     * Delete a file from the R2 bucket.
     */
    public function deleteFile(string $path): bool
    {
        if ($this->disk()->exists($path)) {
            return $this->disk()->delete($path);
        }

        return false;
    }

    /**
     * Get the public URL for a stored file.
     */
    public function getFileUrl(string $path): string
    {
        if ($this->disk === 'public') {
            return asset('storage/' . ltrim($path, '/'));
        }

        if ($this->shouldUseCdn()) {
            return $this->cdnPathUrl($path);
        }

        $storage = $this->disk();

        // Prefer presigned URLs for private/object storage disks (R2/S3).
        // This keeps cover image loading behavior consistent with Media model URLs.
        if ($storage instanceof FilesystemAdapter && method_exists($storage, 'temporaryUrl')) {
            try {
                return $storage->temporaryUrl(
                    $path,
                    now()->addHours(6),
                );
            } catch (\Throwable $e) {
                Log::warning('R2StorageService: failed to generate temporary URL, falling back to base URL.', [
                    'path' => $path,
                    'disk' => $this->disk,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $baseUrl = (string) config("filesystems.disks.{$this->disk}.url", '');

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
        }

        return asset('storage/' . ltrim($path, '/'));
    }

    /**
     * Return a streamed download response for the given file path.
     */
    public function downloadFile(
        string $path,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $stream = $this->disk()->readStream($path);

        if ($stream === false) {
            throw new RuntimeException("Failed to open a download stream for [{$path}].");
        }

        return response()->streamDownload(function () use ($stream): void {
            fpassthru($stream);

            if (is_resource($stream)) {
                fclose($stream);
            }
        }, basename($path));
    }

    /**
     * Create a directory on local disks.
     *
     * For R2/S3 we intentionally skip placeholder objects to avoid generating
     * 0 B `application/octet-stream` keys like "folder_name".
     */
    public function createDirectory(string $path): void
    {
        // R2/S3 prefixes are virtual and created automatically on first upload.
        if ($this->diskDriver() === 's3') {
            return;
        }

        $directory = rtrim($path, "/");

        if (!$this->disk()->exists($directory)) {
            $result = $this->disk()->makeDirectory($directory);

            if ($result === false) {
                Log::warning("R2StorageService: could not create directory.", [
                    "path" => $directory,
                    "disk" => $this->disk,
                ]);
            }
        }
    }
}
