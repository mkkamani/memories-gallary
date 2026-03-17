<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class R2StorageService implements StorageServiceInterface
{
    protected string $disk = "r2";

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

        $result = Storage::disk($this->disk)->putFileAs(
            $directory,
            $file,
            $filename,
        );

        if ($result === false) {
            \Log::error("R2StorageService: failed to upload file.", [
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
     * Delete a file from the R2 bucket.
     */
    public function deleteFile(string $path): bool
    {
        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }

        return false;
    }

    /**
     * Get the public URL for a stored file.
     */
    public function getFileUrl(string $path): string
    {
        return Storage::disk($this->disk)->url($path);
    }

    /**
     * Return a streamed download response for the given file path.
     */
    public function downloadFile(
        string $path,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        return Storage::disk($this->disk)->download($path);
    }

    /**
     * Create a directory placeholder in R2.
     *
     * R2 (like S3) has no real directory concept, but uploading a zero-byte
     * object whose key ends with "/" is the conventional way to represent a
     * folder so that it appears in bucket browsers and other S3-compatible tools.
     */
    public function createDirectory(string $path): void
    {
        $dirKey = rtrim($path, "/") . "/";

        // Only create if it does not already exist
        if (!Storage::disk($this->disk)->exists($dirKey)) {
            $result = Storage::disk($this->disk)->put($dirKey, "");

            if ($result === false) {
                \Log::warning(
                    "R2StorageService: could not create directory placeholder.",
                    [
                        "path" => $dirKey,
                    ],
                );
            }
        }
    }
}
