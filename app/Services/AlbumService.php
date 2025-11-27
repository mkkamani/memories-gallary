<?php

namespace App\Services;

use App\Models\Album;
use App\Models\User;
use Illuminate\Support\Str;

class AlbumService
{
    public function create(array $data, User $user)
    {
        $data['user_id'] = $user->id;
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) . '-' . Str::random(6);
        }
        
        return Album::create($data);
    }

    public function update(Album $album, array $data)
    {
        if (isset($data['title']) && $album->title !== $data['title']) {
            $data['slug'] = Str::slug($data['title']) . '-' . Str::random(6);
        }
        
        $album->update($data);
        return $album;
    }

    public function delete(Album $album)
    {
        // Delete associated media files if needed, or rely on cascade
        return $album->delete();
    }
}
