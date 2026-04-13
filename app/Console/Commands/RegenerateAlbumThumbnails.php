<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class RegenerateAlbumThumbnails extends Command
{
    protected $signature = 'thumbnails:regenerate
        {--prefix=albums : R2 path prefix scope (default: albums)}
        {--album= : Optional album ID or slug to scope cleanup}
        {--dry-run : Preview without deleting/generating thumbnails}
        {--chunk=100 : Number of media records to process per chunk}';

    protected $description = 'Delete old thumbnails from storage and regenerate new thumbnails for media in the selected scope (prefix or album).';

    public function __construct()
    {
        parent::__construct();

        @ini_set('memory_limit', '1024M');
    }

    public function handle(ThumbnailService $thumbnailService): int
    {
        $prefix = $this->normalizePath((string) $this->option('prefix'));
        $albumOption = trim((string) $this->option('album'));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        $album = null;
        if ($albumOption !== '') {
            $album = $this->resolveAlbum($albumOption);

            if (! $album) {
                $this->error("Album not found for --album={$albumOption}");
                return self::FAILURE;
            }
        }

        $query = Media::query()
            ->whereIn('file_type', ['image', 'video'])
            ->whereRaw('LOWER(mime_type) != ?', ['image/heic'])
            ->orderBy('id');

        if ($album) {
            $query->where('album_id', $album->id);
            $this->info("Scoping to album {$album->title} (#{$album->id}).");
        } else {
            if ($prefix === '') {
                $this->error('Invalid --prefix value.');
                return self::FAILURE;
            }

            $query->where(function ($scoped) use ($prefix) {
                $scoped->whereRaw('TRIM(LEADING \'/\' FROM file_path) = ?', [$prefix])
                    ->orWhereRaw('TRIM(LEADING \'/\' FROM file_path) LIKE ?', [$prefix . '/%']);
            });

            $this->info("Scoping to prefix [{$prefix}].");
        }

        $total = (int) (clone $query)->count();

        if ($total === 0) {
            if ($album) {
                $this->info("No image/video records found in album {$album->title} (#{$album->id}).");
            } else {
                $this->info('No image/video records found in selected prefix scope.');
            }
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No thumbnails will be deleted or regenerated.');
        }

        if ($album) {
            $this->info("Processing album {$album->title} (#{$album->id}) with {$total} media record(s)...");
        } else {
            $this->info("Processing prefix {$prefix} with {$total} media record(s)...");
        }

        $deletedOld = 0;
        $regenerated = 0;
        $skipped = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($items) use (
            $thumbnailService,
            $dryRun,
            &$deletedOld,
            &$regenerated,
            &$skipped,
            &$failed,
            $bar,
        ) {
            foreach ($items as $media) {
                try {
                    if (! empty($media->thumbnail_path)) {
                        $deletedOld++;

                        if (! $dryRun) {
                            $thumbnailService->delete($media);
                            $media->refresh();
                        }
                    }

                    if ($dryRun) {
                        $regenerated++;
                        continue;
                    }

                    $status = $thumbnailService->generateWithStatus($media);

                    if ($status === 'generated') {
                        $regenerated++;
                        $thumbnailService->syncDimensionsFromThumbnail($media);
                    } elseif ($status === 'skipped') {
                        $skipped++;
                    } else {
                        $this->cleanupFailedThumbnail($thumbnailService, $media);
                        $failed++;
                        $this->newLine();
                        $this->warn("  [FAILED] Media #{$media->id} ({$media->file_name})");
                    }
                } catch (\Throwable $e) {
                    $this->cleanupFailedThumbnail($thumbnailService, $media);
                    $failed++;
                    $this->newLine();
                    $this->warn("  [ERROR] Media #{$media->id} ({$media->file_name}): {$e->getMessage()}");
                } finally {
                    $bar->advance();
                }
            }
        });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scope', $album ? "Album {$album->title} (#{$album->id})" : "Prefix {$prefix}"],
                ['Total media', $total],
                ['Old thumbnails deleted', $deletedOld],
                ['Regenerated', $regenerated],
                ['Skipped', $skipped],
                ['Failed', $failed],
            ],
        );

        if ($failed > 0) {
            $this->warn('Completed with some failures.');
        }

        return self::SUCCESS;
    }

    private function resolveAlbum(string $albumOption): ?Album
    {
        if (ctype_digit($albumOption)) {
            return Album::withTrashed()->find((int) $albumOption);
        }

        return Album::withTrashed()->where('slug', $albumOption)->first();
    }

    private function normalizePath(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }


    private function cleanupFailedThumbnail(ThumbnailService $thumbnailService, Media $media): void
    {
        try {
            $thumbnailService->delete($media);
            $media->refresh();
        } catch (\Throwable $cleanupError) {
            $this->newLine();
            $this->warn("  [CLEANUP ERROR] Media #{$media->id} ({$media->file_name}): {$cleanupError->getMessage()}");
        }
    }
}
