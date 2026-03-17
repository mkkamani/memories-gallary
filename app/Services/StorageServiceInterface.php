<?php

namespace App\Services;

interface StorageServiceInterface
{
    /**
     * Upload a file to the storage.
     *
     * @param \Illuminate\Http\UploadedFile|string $file
     * @param string $path  The target directory path (without trailing slash).
     * @return string The full key / path of the stored file.
     */
    public function uploadFile($file, string $path): string;

    /**
     * Delete a file from the storage.
     *
     * @param string $path  The full key / path of the file to delete.
     * @return bool
     */
    public function deleteFile(string $path): bool;

    /**
     * Get the public URL for a stored file.
     *
     * @param string $path  The full key / path of the file.
     * @return string
     */
    public function getFileUrl(string $path): string;

    /**
     * Return a streamed download response for the given file.
     *
     * @param string $path  The full key / path of the file.
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadFile(
        string $path,
    ): \Symfony\Component\HttpFoundation\StreamedResponse;

    /**
     * Create a directory placeholder in the storage backend.
     *
     * @param string $path  The directory path to create (without trailing slash).
     * @return void
     */
    public function createDirectory(string $path): void;
}
