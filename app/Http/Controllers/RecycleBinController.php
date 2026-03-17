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
            "Media \"{$media->file_name}\" has been restored.",
        );
    }

    /**
     * Permanently delete a media item (any user's).
     */
    public function forceDeleteMedia($id, MediaService $service)
    {
        $this->authorizeAdmin();

        $media = Media::onlyTrashed()->findOrFail($id);
        $service->purge($media);

        return back()->with("success", "Media item permanently deleted.");
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
            "Album \"{$album->title}\" has been restored.",
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
        $albumService->forceDelete($album, $mediaService);

        return back()->with("success", "Album permanently deleted.");
    }
}
