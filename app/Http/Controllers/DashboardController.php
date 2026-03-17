<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\User;
use App\Models\Album;
use Inertia\Inertia;

class DashboardController extends Controller
{
    /**
     * Format a byte count into a human-readable string (B / KB / MB / GB / TB).
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = (int) floor(log($bytes, 1024));
        $i     = min($i, count($units) - 1); // guard against huge values

        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }

    public function index(Request $request)
    {
        $user = auth()->user();

        $totalUsers  = User::count();
        $totalAlbums = Album::count();
        $mediaAssets = Media::count();

        // ── Storage: sum file_size (bytes) stored at upload time ─────────────
        // We only count non-trashed files so soft-deleted items don't inflate
        // the figure shown to users.
        $storageTotalBytes = (int) Media::sum('file_size');
        $storageUsed       = $this->formatBytes($storageTotalBytes);

        // Per-user storage (used on the Member dashboard card)
        $myStorageBytes = (int) Media::where('user_id', $user->id)->sum('file_size');
        $myStorageUsed  = $this->formatBytes($myStorageBytes);
        // ─────────────────────────────────────────────────────────────────────

        // Recent Media
        $recentMedia = Media::with('user', 'album')->latest()->take(10)->get();

        // Team Updates (recent users)
        $recentUsers = User::latest()->take(5)->get();

        // Pinned/Recent Albums
        $recentAlbums = Album::with([
            'media' => function ($q) {
                $q->latest()->take(1);
            },
        ])
            ->latest()
            ->take(8)
            ->get()
            ->map(function ($album) {
                return [
                    'id'         => $album->id,
                    'name'       => $album->title,
                    'date'       => $album->created_at->format('Y-m-d'),
                    'photoCount' => $album->media()->count(),
                    'coverUrl'   => $album->media->first()
                        ? $album->media->first()->url
                        : null,
                ];
            });

        // Specifically for Manager
        $myAlbums   = Album::where('user_id', $user->id)->count();
        $newUploads = Media::where('created_at', '>=', now()->subDays(7))->count();

        // Specifically for Member
        $myUploadsCount  = Media::where('user_id', $user->id)->count();
        $myRecentUploads = Media::with('user', 'album')
            ->where('user_id', $user->id)
            ->latest()
            ->take(6)
            ->get();

        return Inertia::render('Dashboard', [
            'stats' => [
                'totalUsers'        => $totalUsers,
                'totalAlbums'       => $totalAlbums,
                'mediaAssets'       => $mediaAssets,
                'storageUsed'       => $storageUsed,
                'storageTotalBytes' => $storageTotalBytes,
                'myStorageUsed'     => $myStorageUsed,
                'myStorageBytes'    => $myStorageBytes,
                'myAlbums'          => $myAlbums,
                'newUploads'        => $newUploads,
                'myUploadsCount'    => $myUploadsCount,
            ],
            'recentMedia'    => $recentMedia,
            'recentUsers'    => $recentUsers,
            'recentAlbums'   => $recentAlbums,
            'myRecentUploads' => $myRecentUploads,
            'userRole'       => $user->role,
        ]);
    }
}
