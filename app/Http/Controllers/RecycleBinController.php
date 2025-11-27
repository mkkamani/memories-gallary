<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Media;
use App\Models\Album;
use App\Services\MediaService;
use Inertia\Inertia;

class RecycleBinController extends Controller
{
    public function index()
    {
        $media = Media::onlyTrashed()
            ->where('user_id', auth()->id())
            ->latest('deleted_at')
            ->get();

        $albums = Album::onlyTrashed()
            ->where('user_id', auth()->id())
            ->latest('deleted_at')
            ->get();

        return Inertia::render('RecycleBin/Index', [
            'media' => $media,
            'albums' => $albums,
        ]);
    }

    public function restoreMedia($id)
    {
        $media = Media::onlyTrashed()->where('user_id', auth()->id())->findOrFail($id);
        $media->restore();
        return back();
    }

    public function forceDeleteMedia($id, MediaService $service)
    {
        $media = Media::onlyTrashed()->where('user_id', auth()->id())->findOrFail($id);
        $service->delete($media); // This needs to handle force delete
        return back();
    }

    public function restoreAlbum($id)
    {
        $album = Album::onlyTrashed()->where('user_id', auth()->id())->findOrFail($id);
        $album->restore();
        return back();
    }

    public function forceDeleteAlbum($id)
    {
        $album = Album::onlyTrashed()->where('user_id', auth()->id())->findOrFail($id);
        $album->forceDelete();
        return back();
    }
}
