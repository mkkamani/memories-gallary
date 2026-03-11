<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Album;
use App\Models\User;
use Illuminate\Http\UploadedFile;

class MediaService
{
    protected $storageService;

    public function __construct(StorageServiceInterface $storageService)
    {
        $this->storageService = $storageService;
    }

    public function upload(UploadedFile $file, User $user, ?Album $album = null)
    {
        $path = $this->storageService->uploadFile($file, 'uploads');
        
        return Media::create([
            'user_id' => $user->id,
            'album_id' => $album?->id,
            'file_path' => $path,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => str_starts_with($file->getMimeType(), 'video') ? 'video' : 'image',
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'taken_at' => now(), // TODO: Extract from metadata
        ]);
    }

    public function delete(Media $media)
    {
        if ($media->trashed()) {
            $this->storageService->deleteFile($media->file_path);
            return $media->forceDelete();
        }
        return $media->delete();
    }
}
