<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Media;
use App\Models\User;
use App\Models\Album;
use Illuminate\Filesystem\FilesystemAdapter;
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

        $base  = 1000;
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i     = (int) floor(log($bytes, $base));
        $i     = min($i, count($units) - 1);

        return number_format($bytes / pow($base, $i), 2, '.', '') . ' ' . $units[$i];
    }

    /**
     * Scan the configured media disk (R2/S3/local) and sum up object sizes.
     * Result is cached for 5 minutes to avoid hammering the API on every request.
     *
     * @return array{bytes:int, objects:int}
     */
    private function getBucketUsageStats(): array
    {
        $disk = (string) config('filesystems.media_disk', 'public');

        return Cache::remember("dashboard:r2-usage:{$disk}", now()->addMinutes(5), function () use ($disk) {
            $bytes   = 0;
            $objects = 0;

            /** @var FilesystemAdapter $adapter */
            $adapter = Storage::disk($disk);

            // listContents() on the Flysystem adapter returns a generator of
            // StorageAttributes items. FileAttributes carries the file size.
            $listing = $adapter->getAdapter()->listContents('', true);

            foreach ($listing as $item) {
                if (! ($item instanceof FileAttributes)) {
                    continue;
                }
                $objects++;
                $bytes += (int) ($item->fileSize() ?? 0);
            }

            return ['bytes' => $bytes, 'objects' => $objects];
        });
    }

    /**
     * AJAX endpoint — scans R2 for real storage usage (cached 5 min).
     * Media count always comes from the DB (fast & accurate).
     * Falls back to DB file_size sum if the bucket scan fails.
     */
    public function storageStats(Request $request)
    {
        $user = $request->user();

        // ── Total storage: from R2 (with DB fallback) ─────────────────────────
        try {
            $bucketStats = $this->getBucketUsageStats();
            $totalBytes  = $bucketStats['bytes'];
        } catch (\Throwable $e) {
            // R2 unreachable — fall back to whatever is recorded in the DB
            $totalBytes = (int) Media::sum('file_size');
        }

        // ── Counts: always from DB ─────────────────────────────────────────────
        $totalCount = (int) Media::count();
        $myCount    = (int) Media::where('user_id', $user->id)->count();

        // Per-user storage: use DB file_size if populated, otherwise estimate
        // proportionally from the total R2 bytes.
        $myBytesFromDb = (int) Media::where('user_id', $user->id)->sum('file_size');

        if ($myBytesFromDb > 0) {
            $myBytes = $myBytesFromDb;
        } elseif ($totalCount > 0) {
            $myBytes = (int) round($totalBytes * ($myCount / $totalCount));
        } else {
            $myBytes = 0;
        }

        return response()->json([
            'storageUsed'       => $this->formatBytes($totalBytes),
            'storageTotalBytes' => $totalBytes,
            'mediaAssets'       => number_format($totalCount, 0),
            'myStorageUsed'     => $this->formatBytes($myBytes),
            'myStorageBytes'    => $myBytes,
        ]);
    }

    /**
     * Resolve a cover media item for a dashboard album card.
     * Priority: cover_image field → latest media in album or any descendant.
     */
    private function resolveAlbumCoverMedia(Album $album): ?Media
    {
        // 1. Explicit cover_image stored on the album
        if (!empty($album->cover_image)) {
            $media = Media::where('file_path', $album->cover_image)->first();
            if ($media) {
                return $media;
            }
        }

        // 2. Latest media from the album itself or any nested descendant
        $albumIds = $album->descendants()->pluck('id')->prepend($album->id)->all();
        return Media::whereIn('album_id', $albumIds)->latest()->first();
    }

    private function formatAlbumCoverMediaArray(?Media $media): ?array
    {
        if (!$media) {
            return null;
        }
        return [
            'url'       => $media->url,
            'file_type' => $media->file_type,
            'file_name' => $media->file_name,
            'mime_type' => $media->mime_type,
        ];
    }

    public function index(Request $request)
    {
        $user = $request->user();

        $totalUsers = User::count();
        $albumQuery = Album::query()->whereNull('parent_id')->whereNull('deleted_at');

        if ($user->role !== 'admin' && !empty($user->location)) {
            $albumQuery->where('location', $user->location);
        }

        $totalAlbums = (clone $albumQuery)->count();

        // Media Assets count from DB — fast. Storage bytes come via AJAX.
        $mediaAssets       = number_format(Media::count(), 0);
        $storageTotalBytes = 0; // placeholder; real value fetched via /dashboard/storage-stats
        $storageUsed       = null; // signals the frontend to show "Calculating..."

        // Per-user storage (used on the Member dashboard card)
        $myStorageBytes = (int) Media::where('user_id', $user->id)->sum('file_size');
        $myStorageUsed  = $this->formatBytes($myStorageBytes);

        // Recent Media
        $recentMedia = Media::with(['user', 'album'])->inRandomOrder()->take(20)->get();

        // Pinned Albums (scoped to current user)
        $recentAlbums = $user
            ->pinnedAlbums()
            ->withCount('media')
            ->orderByDesc('pinned_albums.created_at')
            ->take(10)
            ->get()
            ->map(function ($album) {
                $coverMedia = $this->resolveAlbumCoverMedia($album);

                return [
                    'id'         => $album->id,
                    'slug'       => $album->slug,
                    'path'       => $album->path,
                    'name'       => $album->title,
                    'date'       => optional($album->updated_at)->format('Y-m-d'),
                    'photoCount' => $album->media_count,
                    'coverUrl'   => $coverMedia?->url,
                    'coverMedia' => $this->formatAlbumCoverMediaArray($coverMedia),
                ];
            });

        // All Recent Albums (for member dashboard — not pinned, just latest)
        $allRecentAlbums = (clone $albumQuery)
            ->withCount('media')
            ->latest()
            ->take(10)
            ->get()
            ->map(function ($album) {
                $coverMedia = $this->resolveAlbumCoverMedia($album);

                return [
                    'id'         => $album->id,
                    'slug'       => $album->slug,
                    'path'       => $album->path,
                    'name'       => $album->title,
                    'date'       => optional($album->updated_at)->format('Y-m-d'),
                    'photoCount' => $album->media_count,
                    'coverUrl'   => $coverMedia?->url,
                    'coverMedia' => $this->formatAlbumCoverMediaArray($coverMedia),
                ];
            });

        // Specifically for Manager
        $myAlbums   = (clone $albumQuery)->where('user_id', $user->id)->count();
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
            'recentMedia'      => $recentMedia,
            'recentAlbums'     => $recentAlbums,
            'allRecentAlbums'  => $allRecentAlbums,
            'myRecentUploads'  => $myRecentUploads,
            'userRole'        => $user->role,
        ]);
    }
}
