<?php

namespace App\Services;

use App\Models\Media;
use App\Models\Album;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class MediaService
{
    public function upload(UploadedFile $file, User $user, ?Album $album = null)
    {
        $path = $file->store('uploads', 'public');
        
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
            Storage::disk('public')->delete($media->file_path);
            return $media->forceDelete();
        }
        return $media->delete();
    }
}
