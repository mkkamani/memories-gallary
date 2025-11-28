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
        // Get user's regular albums
        $query = Album::with(['media' => function ($query) {
                $query->orderBy('created_at', 'asc')->limit(1);
            }])
            ->withCount('media')
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

        $albums = $query->latest()->get()->map(function ($album) {
            return [
                'id' => $album->id,
                'title' => $album->title,
                'description' => $album->description,
                'type' => $album->type,
                'event_date' => $album->event_date,
                'is_public' => $album->is_public,
                'user_id' => $album->user_id,
                'media_count' => $album->media_count,
                'thumbnail' => $album->media->first() ? '/storage/' . $album->media->first()->file_path : null,
                'is_system' => false,
            ];
        });

        // Create system albums
        $systemAlbums = collect();

        // Recent Album - Last 30 days
        $recentMedia = \App\Models\Media::where('user_id', auth()->id())
            ->where('created_at', '>=', now()->subDays(30))
            ->orderBy('created_at', 'desc')
            ->get();

        if ($recentMedia->count() > 0) {
            $systemAlbums->push([
                'id' => 'recent',
                'title' => 'Recent',
                'description' => 'Photos and videos from the last 30 days',
                'type' => 'system',
                'event_date' => null,
                'is_public' => false,
                'user_id' => auth()->id(),
                'media_count' => $recentMedia->count(),
                'thumbnail' => '/storage/' . $recentMedia->first()->file_path,
                'is_system' => true,
            ]);
        }

        // Today's Memories - Same day from previous years
        $todayMemories = \App\Models\Media::where('user_id', auth()->id())
            ->whereRaw('MONTH(created_at) = ?', [now()->month])
            ->whereRaw('DAY(created_at) = ?', [now()->day])
            ->whereRaw('YEAR(created_at) < ?', [now()->year])
            ->orderBy('created_at', 'desc')
            ->get();

        if ($todayMemories->count() > 0) {
            $systemAlbums->push([
                'id' => 'todays-memories',
                'title' => "Today's Memories",
                'description' => 'Photos from this day in previous years',
                'type' => 'system',
                'event_date' => null,
                'is_public' => false,
                'user_id' => auth()->id(),
                'media_count' => $todayMemories->count(),
                'thumbnail' => '/storage/' . $todayMemories->first()->file_path,
                'is_system' => true,
            ]);
        }

        // Merge system albums with regular albums
        $allAlbums = $systemAlbums->concat($albums);
            
        return Inertia::render('Albums/Index', [
            'albums' => $allAlbums,
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

    public function edit(Album $album)
    {
        $this->authorize('update', $album);
        return Inertia::render('Albums/Edit', ['album' => $album]);
    }

    public function update(Request $request, Album $album, AlbumService $albumService, ActivityLogService $logService)
    {
        $this->authorize('update', $album);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_public' => 'boolean',
        ]);

        $album = $albumService->update($album, $data);
        $logService->logAlbumUpdated($album);

        return redirect()->route('albums.show', $album)->with('success', 'Album updated successfully.');
    }

    public function destroy(Album $album, ActivityLogService $logService)
    {
        $this->authorize('delete', $album);
        
        $logService->logAlbumDeleted($album);
        $album->delete();

        return redirect()->route('albums.index')->with('success', 'Album deleted successfully.');
    }

    public function showSystemAlbum($type)
    {
        $album = null;
        $media = collect();

        if ($type === 'recent') {
            $media = \App\Models\Media::where('user_id', auth()->id())
                ->where('created_at', '>=', now()->subDays(30))
                ->orderBy('created_at', 'desc')
                ->get();

            $album = [
                'id' => 'recent',
                'title' => 'Recent',
                'description' => 'Photos and videos from the last 30 days',
                'type' => 'system',
                'is_system' => true,
                'media' => $media,
            ];
        } elseif ($type === 'todays-memories') {
            $media = \App\Models\Media::where('user_id', auth()->id())
                ->whereRaw('MONTH(created_at) = ?', [now()->month])
                ->whereRaw('DAY(created_at) = ?', [now()->day])
                ->whereRaw('YEAR(created_at) < ?', [now()->year])
                ->orderBy('created_at', 'desc')
                ->get();

            $album = [
                'id' => 'todays-memories',
                'title' => "Today's Memories",
                'description' => 'Photos from this day in previous years',
                'type' => 'system',
                'is_system' => true,
                'media' => $media,
            ];
        }

        if (!$album) {
            abort(404);
        }

        return Inertia::render('Albums/Show', ['album' => $album]);
    }
}
