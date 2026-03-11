<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\User;
use App\Models\Album;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();
        
        $totalUsers = User::count();
        $totalAlbums = Album::count();
        $mediaAssets = Media::count();
        
        // Mock storage used for now, as calculating size of all files might be heavy
        // or we can sum a size column if it exists. Since file size might not be stored, we'll return a static string
        $storageUsed = "1.2 TB";
        
        // Recent Media
        $recentMedia = Media::with('user', 'album')
            ->latest()
            ->take(10)
            ->get();
            
        // Team Updates (recent users)
        $recentUsers = User::latest()->take(5)->get();
        
        // Pinned/Recent Albums
        $recentAlbums = Album::with(['media' => function($q) {
            $q->latest()->take(1);
        }])->latest()->take(8)->get()->map(function($album) {
            return [
                'id' => $album->id,
                'name' => $album->title,
                'date' => $album->created_at->format('Y-m-d'),
                'photoCount' => $album->media()->count(),
                'coverUrl' => $album->media->first() ? '/storage/' . $album->media->first()->file_path : 'https://picsum.photos/seed/album-'.$album->id.'/400/300',
            ];
        });
        
        // Specifically for Manager
        $myAlbums = Album::where('user_id', $user->id)->count();
        $newUploads = Media::where('created_at', '>=', now()->subDays(7))->count();
        
        // Specifically for Member
        $myUploadsCount = Media::where('user_id', $user->id)->count();
        $myRecentUploads = Media::with('user', 'album')
            ->where('user_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalUsers' => $totalUsers,
                'totalAlbums' => $totalAlbums,
                'mediaAssets' => $mediaAssets,
                'storageUsed' => $storageUsed,
                'myAlbums' => $myAlbums,
                'newUploads' => $newUploads,
                'myUploadsCount' => $myUploadsCount,
            ],
            'recentMedia' => $recentMedia,
            'recentUsers' => $recentUsers,
            'recentAlbums' => $recentAlbums,
            'myRecentUploads' => $myRecentUploads,
            'userRole' => $user->role,
        ]);
    }
}
