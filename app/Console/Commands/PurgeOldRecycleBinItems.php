<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\AlbumService;
use App\Services\MediaService;
use Illuminate\Console\Command;

class PurgeOldRecycleBinItems extends Command
{
    /**
     * The name and signature of the console command.
     *
     * The optional --days flag lets you override the retention period without
     * touching code (useful for one-off manual purges or testing):
     *
     *   php artisan recycle-bin:purge          # default: 7 days
     *   php artisan recycle-bin:purge --days=30
     */
    protected $signature = 'recycle-bin:purge
                            {--days=7 : Items that have been in the trash longer than this many days will be permanently deleted}';

    protected $description = 'Permanently delete media and albums that have been in the Recycle Bin for longer than the retention period, removing their files from R2.';

    public function handle(MediaService $mediaService, AlbumService $albumService): int
    {
        $days   = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("  Recycle Bin purge — retention: {$days} day(s)");
        $this->info("  Removing items trashed on or before: {$cutoff->toDateTimeString()}");
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // ── Step 1: Purge qualifying albums ───────────────────────────────────
        // Albums are processed first so that their child media records (which
        // may not be individually trashed) are cleaned up from R2 in the same
        // pass via AlbumService::forceDelete().
        $albums = Album::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $albumsPurged = 0;
        $albumsFailed = 0;

        if ($albums->isEmpty()) {
            $this->line('  No qualifying albums found.');
        }

        foreach ($albums as $album) {
            try {
                $albumService->forceDelete($album, $mediaService);
                $albumsPurged++;
                $this->line("  <fg=green>✓</> Album #{$album->id} \"{$album->title}\" — purged.");
            } catch (\Throwable $e) {
                $albumsFailed++;
                $this->error("  ✗ Album #{$album->id} \"{$album->title}\" — FAILED: {$e->getMessage()}");
                \Log::error('recycle-bin:purge – album purge failed', [
                    'album_id' => $album->id,
                    'title'    => $album->title,
                    'error'    => $e->getMessage(),
                    'trace'    => $e->getTraceAsString(),
                ]);
            }
        }

        // ── Step 2: Purge remaining individually-trashed media ────────────────
        // After the album pass, any media that belonged to those albums will
        // already have been hard-deleted from the DB, so they will not appear
        // in this query.  This step catches media items that were deleted on
        // their own (not as part of an album deletion).
        $mediaItems = Media::onlyTrashed()
            ->where('deleted_at', '<=', $cutoff)
            ->get();

        $mediaPurged = 0;
        $mediaFailed = 0;

        if ($mediaItems->isEmpty()) {
            $this->line('  No qualifying media items found.');
        }

        foreach ($mediaItems as $media) {
            try {
                $mediaService->purge($media);
                $mediaPurged++;
                $this->line("  <fg=green>✓</> Media #{$media->id} \"{$media->file_name}\" — purged.");
            } catch (\Throwable $e) {
                $mediaFailed++;
                $this->error("  ✗ Media #{$media->id} \"{$media->file_name}\" — FAILED: {$e->getMessage()}");
                \Log::error('recycle-bin:purge – media purge failed', [
                    'media_id'  => $media->id,
                    'file_name' => $media->file_name,
                    'file_path' => $media->file_path,
                    'error'     => $e->getMessage(),
                    'trace'     => $e->getTraceAsString(),
                ]);
            }
        }

        // ── Summary ───────────────────────────────────────────────────────────
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        if ($albumsFailed === 0 && $mediaFailed === 0) {
            $this->info("  <fg=green>Done.</> Purged {$albumsPurged} album(s) and {$mediaPurged} media file(s). No errors.");
        } else {
            $this->warn("  Done with errors.");
            $this->warn("  Albums  — purged: {$albumsPurged}, failed: {$albumsFailed}");
            $this->warn("  Media   — purged: {$mediaPurged}, failed: {$mediaFailed}");
            $this->warn("  Check the application log for details.");
        }

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");

        // Return a non-zero exit code when any item failed so that the OS /
        // scheduler can detect a partial failure and alert accordingly.
        return ($albumsFailed + $mediaFailed) > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
