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
        
        // Validate parent if provided
        if (!empty($data['parent_id'])) {
            $this->validateParent($data['parent_id'], $user);
        }
        
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['title']) . '-' . Str::random(6);
        }
        
        return Album::create($data);
    }

    public function update(Album $album, array $data)
    {
        // Validate parent if being changed
        if (isset($data['parent_id'])) {
            if ($data['parent_id'] !== null) {
                $this->validateParent($data['parent_id'], $album->user, $album);
            }
        }
        
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

    /**
     * Validate that the parent album is valid for this user and doesn't create circular reference
     */
    protected function validateParent($parentId, User $user, ?Album $currentAlbum = null)
    {
        $parent = Album::find($parentId);

        if (!$parent) {
            throw new \InvalidArgumentException('Parent album not found.');
        }

        // Check if user has access to parent album
        if ($parent->user_id !== $user->id && !$parent->is_public) {
            throw new \InvalidArgumentException('You do not have access to this parent album.');
        }

        // Check for circular reference (prevent album from being its own ancestor)
        if ($currentAlbum && $parent->isDescendantOf($currentAlbum)) {
            throw new \InvalidArgumentException('Cannot set parent album: this would create a circular reference.');
        }

        // Also check if trying to set self as parent
        if ($currentAlbum && $parent->id === $currentAlbum->id) {
            throw new \InvalidArgumentException('An album cannot be its own parent.');
        }
    }

    /**
     * Move an album to a different parent
     */
    public function move(Album $album, ?int $newParentId)
    {
        if ($newParentId !== null) {
            $this->validateParent($newParentId, $album->user, $album);
        }

        $album->parent_id = $newParentId;
        $album->save();

        return $album;
    }
}
