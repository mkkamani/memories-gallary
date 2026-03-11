<?php

namespace App\Services;

interface StorageServiceInterface
{
    /**
     * Upload a file to the storage.
     *
     * @param \Illuminate\Http\UploadedFile|string $file
     * @param string $path
     * @return string The full path/identifier of the stored file.
     */
    public function uploadFile($file, $path);

    /**
     * Delete a file from the storage.
     *
     * @param string $path
     * @return bool
     */
    public function deleteFile($path);

    /**
     * Get the public URL for a file.
     *
     * @param string $path
     * @return string
     */
    public function getFileUrl($path);

    /**
     * Get a download response for the file.
     *
     * @param string $path
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function downloadFile($path);
}
