<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;

class R2StorageService implements StorageServiceInterface
{
    protected $disk;

    public function __construct()
    {
        // Defaulting to the custom r2 disk that we'll configure
        $this->disk = 'r2';
    }

    public function uploadFile($file, $path)
    {
        $filename = uniqid() . '_' . $file->getClientOriginalName();
        $fullPath = rtrim($path, '/') . '/' . $filename;
        
        // Push the file directly to the R2 bucket
        Storage::disk($this->disk)->put($fullPath, file_get_contents($file));
        
        return $fullPath;
    }

    public function deleteFile($path)
    {
        if (Storage::disk($this->disk)->exists($path)) {
            return Storage::disk($this->disk)->delete($path);
        }
        return false;
    }

    public function getFileUrl($path)
    {
        return Storage::disk($this->disk)->url($path);
    }

    public function downloadFile($path)
    {
        return Storage::disk($this->disk)->download($path);
    }
}
