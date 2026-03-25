<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\User;
use App\Models\Album;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use League\Flysystem\FileAttributes;

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

        // Cloudflare R2 bucket size is displayed with decimal (SI) units.
        $base  = 1000;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = (int) floor(log($bytes, $base));
        $i     = min($i, count($units) - 1); // guard against huge values

        return number_format($bytes / pow($base, $i), 2, '.', '') . ' ' . $units[$i];
    }

    /**
     * Read actual object usage from the configured media disk (R2/S3).
     *
     * The result is cached briefly to avoid scanning the entire bucket on every
     * dashboard request while still staying near real-time.
     *
     * @return array{bytes:int, objects:int}
     */
    private function getBucketUsageStats(): array
    {
        $disk = (string) config('filesystems.media_disk', 'public');

        return Cache::remember("dashboard:r2-usage:{$disk}", now()->addMinutes(2), function () use ($disk) {
            $bytes = 0;
            $objects = 0;

            $listing = Storage::disk($disk)
                ->getDriver()
                ->listContents('', true);

            foreach ($listing as $item) {
                if (! ($item instanceof FileAttributes)) {
                    continue;
                }

                $objects++;
                $bytes += (int) ($item->fileSize() ?? 0);
            }

            return [
                'bytes' => $bytes,
                'objects' => $objects,
            ];
        });
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $totalUsers  = User::count();
        $albumQuery = Album::query()->whereNull('parent_id')->whereNull('deleted_at');

        if ($user->role !== 'admin' && !empty($user->location)) {
            $albumQuery->where('location', $user->location);
        }

        $totalAlbums = (clone $albumQuery)->count();
        // Live bucket usage (actual R2 bytes/object count).
        // Fallback to DB-derived values if remote listing fails.
        try {
            $bucketStats = $this->getBucketUsageStats();
            $storageTotalBytes = $bucketStats['bytes'];
            $mediaAssets = $bucketStats['objects'];
        } catch (\Throwable $e) {
            $storageTotalBytes = (int) Media::sum('file_size');
            $mediaAssets = Media::count();
        }

        $storageUsed       = $this->formatBytes($storageTotalBytes);

        // Per-user storage (used on the Member dashboard card)
        $myStorageBytes = (int) Media::where('user_id', $user->id)->sum('file_size');
        $myStorageUsed  = $this->formatBytes($myStorageBytes);
        // ─────────────────────────────────────────────────────────────────────

        // Recent Media
        $recentMedia = Media::with('user', 'album')->latest()->take(10)->get();

        // Team Updates (recent users)
        $recentUsers = User::latest()->take(5)->get();

        // Pinned Albums (scoped to current user)
        $recentAlbums = $user
            ->pinnedAlbums()
            ->with([
                'media' => function ($q) {
                    $q->latest()->take(1);
                },
            ])
            ->withCount('media')
            ->orderByDesc('pinned_albums.created_at')
            ->take(8)
            ->get()
            ->map(function ($album) {
                $coverMedia = $album->media->first();

                return [
                    'id'         => $album->id,
                    'slug'       => $album->slug,
                    'path'       => $album->path,
                    'name'       => $album->title,
                    'date'       => optional($album->updated_at)->format('Y-m-d'),
                    'photoCount' => $album->media_count,
                    'coverUrl'   => $coverMedia
                        ? $coverMedia->url
                        : null,
                    'coverMedia' => $coverMedia
                        ? [
                            'url' => $coverMedia->url,
                            'file_type' => $coverMedia->file_type,
                            'file_name' => $coverMedia->file_name,
                            'mime_type' => $coverMedia->mime_type,
                        ]
                        : null,
                ];
            });

        // Specifically for Manager
        $myAlbums   = (clone $albumQuery)->where('user_id', $user->id)->count();
        $newUploads = Media::where('created_at', '>=', now()->subDays(7))->count();

        // Specifically for Member
        $myUploadsCount  = Media::where('user_id', $user->id)->count();
        // For members, count only albums they have joined (have media in or created)
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
