<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Models\Album;
use App\Services\AlbumService;
use App\Services\ActivityLogService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Inertia\Inertia;

class AlbumController extends Controller
{
    use AuthorizesRequests;
    public function index(Request $request)
    {
        $query = Album::with('media')
            ->where(function ($q) {
                $q->where('user_id', auth()->id())
                  ->orWhere('is_public', true);
            });

        if ($request->search) {
            $query->where('title', 'like', '%' . $request->search . '%');
        }

        if ($request->type) {
            $query->where('type', $request->type);
        }

        $albums = $query->latest()->get();
            
        return Inertia::render('Albums/Index', [
            'albums' => $albums,
            'filters' => $request->only(['search', 'type']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Albums/Create');
    }

    public function store(Request $request, AlbumService $albumService, ActivityLogService $logService)
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        $album = $albumService->create($data, auth()->user());
        $logService->logAlbumCreated($album);

        return redirect()->route('albums.index');
    }

    public function show(Album $album)
    {
        $this->authorize('view', $album);
        $album->load('media');
        return Inertia::render('Albums/Show', ['album' => $album]);
    }
}
