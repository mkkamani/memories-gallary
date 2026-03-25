<?php

namespace App\Services;

use App\Models\User;
use App\Models\Album;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Interfaces\StorageServiceInterface;

class AlbumService
{
    public function __construct(
        protected StorageServiceInterface $storageService,
    ) {}

    /**
     * Create a new album, compute its R2 directory path, and create the
     * corresponding directory placeholder in the R2 bucket.
     */
    public function create(array $data, User $user): Album
    {
        $data["user_id"] = $user->id;
        $data["is_public"] = true; // albums are always public

        // Default type when not provided
        if (empty($data["type"])) {
            $data["type"] = "event";
        }

        // Validate parent if provided
        if (!empty($data["parent_id"])) {
            $this->validateParent($data["parent_id"], $user);

            // Inherit location from parent when caller did not supply one
            if (empty($data["location"])) {
                $parent = Album::find($data["parent_id"]);
                if ($parent) {
                    $data["location"] = $parent->location;
                }
            }
        }

        if (empty($data["slug"])) {
            $data["slug"] = $this->generateUniqueSlug($data["title"]);
        }

        if (empty($data['location'])) {
            $data['location'] = $user->location ?: 'Rajkot';
        }

        // Create the album row (r2_path is computed after we have the id)
        $album = Album::create($data);

        // Compute and persist the R2 directory path
        $r2Path = $this->computeR2Path($album);
        $album->update(["r2_path" => $r2Path]);

        // Create the directory placeholder in R2
        try {
            $this->storageService->createDirectory($r2Path);
        } catch (\Throwable $e) {
            Log::error("AlbumService: failed to create R2 directory.", [
                "album_id" => $album->id,
                "r2_path" => $r2Path,
                "error" => $e->getMessage(),
            ]);
        }

        return $album->fresh();
    }

    /**
     * Update an existing album.
     */
    public function update(Album $album, array $data): Album
    {
        // Always keep albums public
        $data["is_public"] = true;

        // Validate parent if being changed
        if (isset($data["parent_id"])) {
            if ($data["parent_id"] !== null) {
                $this->validateParent($data["parent_id"], $album->user, $album);
            }
        }

        if (isset($data["title"]) && $album->title !== $data["title"]) {
            $data["slug"] = $this->generateUniqueSlug($data["title"], $album->id);
        }

        $album->update($data);
        return $album;
    }

    /**
     * Soft-delete an album.
     */
    public function delete(Album $album): bool
    {
        return $album->delete();
    }

    /**
     * Force-delete an album including all its media and nested child albums.
     */
    public function forceDelete(Album $album, MediaService $mediaService): bool
    {
        // Permanently remove every media file in this album from R2 and the DB.
        // purge() is used instead of delete() so that files are always deleted
        // from R2 regardless of whether the individual media record is trashed.
        foreach ($album->media()->withTrashed()->get() as $media) {
            $mediaService->purge($media);
        }

        // Recursively handle nested child albums
        foreach ($album->children()->withTrashed()->get() as $child) {
            $this->forceDelete($child, $mediaService);
        }

        return $album->forceDelete();
    }

    /**
     * Move an album to a different parent (or to root level when $newParentId is null).
     */
    public function move(Album $album, ?int $newParentId): Album
    {
        if ($newParentId !== null) {
            $this->validateParent($newParentId, $album->user, $album);
        }

        $album->parent_id = $newParentId;
        $album->save();

        return $album;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Compute the R2 storage path for an album.
     *
        * Root album  → albums/{location}/{safe-title}_{id}
     * Child album → {parent_r2_path}/{safe-title}_{id}
     *
     * The parent's r2_path is used directly when available; otherwise we fall
     * back to re-computing it recursively so that legacy albums (created before
     * the r2_path column existed) are handled gracefully.
     */
    protected function computeR2Path(Album $album): string
    {
        $safeName = preg_replace(
            "/[^a-z0-9]+/",
            "_",
            strtolower($album->title),
        );
        $safeName = trim($safeName, "_");
        $segment = $safeName . "_" . $album->id;

        if ($album->parent_id) {
            $parent = Album::find($album->parent_id);

            if ($parent) {
                $parentPath = $parent->r2_path ?? $this->computeR2Path($parent);
                return $parentPath . "/" . $segment;
            }
        }

        $locationSlug = Str::slug((string) ($album->location ?: 'Rajkot'));
        if ($locationSlug === '') {
            $locationSlug = 'rajkot';
        }

        return "albums/" . $locationSlug . "/" . $segment;
    }

    /**
     * Validate that the given parent album is accessible to the user and does
     * not create a circular reference.
     */
    protected function validateParent(
        int $parentId,
        User $user,
        ?Album $currentAlbum = null,
    ): void {
        $parent = Album::find($parentId);

        if (!$parent) {
            throw new \InvalidArgumentException("Parent album not found.");
        }

        if ($parent->user_id !== $user->id && !$parent->is_public) {
            throw new \InvalidArgumentException(
                "You do not have access to this parent album.",
            );
        }

        if ($currentAlbum && $parent->isDescendantOf($currentAlbum)) {
            throw new \InvalidArgumentException(
                "Cannot set parent album: this would create a circular reference.",
            );
        }

        if ($currentAlbum && $parent->id === $currentAlbum->id) {
            throw new \InvalidArgumentException(
                "An album cannot be its own parent.",
            );
        }
    }

    protected function generateUniqueSlug(string $title, ?int $ignoreId = null): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            $base = 'album';
        }

        $slug = $base;
        $counter = 2;

        while (
            Album::query()
                ->when($ignoreId, fn($q) => $q->where('id', '!=', $ignoreId))
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base . '-' . $counter;
            $counter++;
        }

        return $slug;
    }
}
