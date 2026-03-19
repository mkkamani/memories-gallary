<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\Album;
use App\Services\MediaService;
use App\Services\AlbumService;
use Inertia\Inertia;

class RecycleBinController extends Controller
{
    /**
     * Abort with 403 if the authenticated user is not an admin.
     */
    private function authorizeAdmin(): void
    {
        if (auth()->user()?->role !== "admin") {
            abort(403, "Only administrators can access the Recycle Bin.");
        }
    }

    /**
     * List ALL soft-deleted media and albums across every user.
     */
    public function index()
    {
        $this->authorizeAdmin();

        $media = Media::onlyTrashed()
            ->with("user", "album")
            ->latest("deleted_at")
            ->get();

        $albums = Album::onlyTrashed()
            ->with("user")
            ->latest("deleted_at")
            ->get();

        return Inertia::render("RecycleBin/Index", [
            "media" => $media,
            "albums" => $albums,
        ]);
    }

    /**
     * Restore a soft-deleted media item (any user's).
     */
    public function restoreMedia($id)
    {
        $this->authorizeAdmin();

        $media = Media::onlyTrashed()->findOrFail($id);
        $media->restore();

        return back()->with(
            "success",
            "\"{$media->file_name}\" has been restored and is back in its album.",
        );
    }

    /**
     * Permanently delete a media item (any user's).
     */
    public function forceDeleteMedia($id, MediaService $service)
    {
        $this->authorizeAdmin();

        $media = Media::onlyTrashed()->findOrFail($id);
        $fileName = $media->file_name;

        try {
            $service->purge($media);
        } catch (\Throwable $e) {
            \Log::error("RecycleBinController::forceDeleteMedia – failed.", [
                "media_id" => $id,
                "error" => $e->getMessage(),
            ]);

            return back()->with(
                "error",
                "Failed to permanently delete \"{$fileName}\". Please try again.",
            );
        }

        return back()->with(
            "success",
            "\"{$fileName}\" has been permanently deleted and removed from R2 storage.",
        );
    }

    /**
     * Restore a soft-deleted album (any user's).
     */
    public function restoreAlbum($id)
    {
        $this->authorizeAdmin();

        $album = Album::onlyTrashed()->findOrFail($id);
        $album->restore();

        return back()->with(
            "success",
            "Album \"{$album->title}\" has been restored and is visible in Albums.",
        );
    }

    /**
     * Permanently delete an album and all its nested media (any user's).
     */
    public function forceDeleteAlbum(
        $id,
        AlbumService $albumService,
        MediaService $mediaService,
    ) {
        $this->authorizeAdmin();

        $album = Album::onlyTrashed()->findOrFail($id);
        $albumTitle = $album->title;

        try {
            $albumService->forceDelete($album, $mediaService);
        } catch (\Throwable $e) {
            \Log::error("RecycleBinController::forceDeleteAlbum – failed.", [
                "album_id" => $id,
                "error" => $e->getMessage(),
            ]);

            return back()->with(
                "error",
                "Failed to permanently delete \"{$albumTitle}\". Please try again.",
            );
        }

        return back()->with(
            "success",
            "Album \"{$albumTitle}\" and all its contents have been permanently deleted from R2 storage.",
        );
    }
}
