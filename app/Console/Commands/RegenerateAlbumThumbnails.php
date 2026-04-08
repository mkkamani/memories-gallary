<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class RegenerateAlbumThumbnails extends Command
{
    protected $signature = 'thumbnails:regenerate
        {--album= : Album ID or slug to process}
        {--dry-run : Preview without deleting/generating thumbnails}
        {--chunk=100 : Number of media records to process per chunk}';

    protected $description = 'Delete old thumbnails from storage and regenerate new thumbnails for media in the selected album.';

    public function handle(ThumbnailService $thumbnailService): int
    {
        $albumOption = trim((string) $this->option('album'));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($albumOption === '') {
            $this->error('The --album option is required. Provide an album ID or album slug.');
            return self::FAILURE;
        }

        $album = $this->resolveAlbum($albumOption);

        if (! $album) {
            $this->error("Album not found for --album={$albumOption}");
            return self::FAILURE;
        }

        $query = Media::query()
            ->where('album_id', $album->id)
            ->whereIn('file_type', ['image', 'video'])
            ->orderBy('id');

        $total = (int) (clone $query)->count();

        if ($total === 0) {
            $this->info("No image/video records found in album {$album->title} (#{$album->id}).");
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No thumbnails will be deleted or regenerated.');
        }

        $this->info("Processing album {$album->title} (#{$album->id}) with {$total} media record(s)...");

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

                        if ($this->shouldSyncDimensionsFromThumbnail($media)) {
                            $thumbnailService->syncDimensionsFromThumbnail($media);
                        }
                    } elseif ($status === 'skipped') {
                        $skipped++;
                    } else {
                        $failed++;
                        $this->newLine();
                        $this->warn("  [FAILED] Media #{$media->id} ({$media->file_name})");
                    }
                } catch (\Throwable $e) {
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
                ['Album', "{$album->title} (#{$album->id})"],
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
            return Album::find((int) $albumOption);
        }

        return Album::where('slug', $albumOption)->first();
    }

    private function shouldSyncDimensionsFromThumbnail(Media $media): bool
    {
        if (empty($media->thumbnail_path)) {
            return false;
        }

        $width = (int) ($media->width ?? 0);
        $height = (int) ($media->height ?? 0);

        return $width <= 0 || $height <= 0;
    }
}
