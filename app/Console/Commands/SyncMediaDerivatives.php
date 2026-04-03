<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\ThumbnailService;
use App\Support\MediaDimensionExtractor;
use Illuminate\Console\Command;

class SyncMediaDerivatives extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:media-records
                            {--force : Regenerate even if a thumbnail already exists}
                            {--ids=  : Comma-separated list of media IDs to process}
                            {--limit=0 : Maximum number of items to process (0 = all)}';

    /**
     * @var string
     */
    protected $description = 'Sync missing media derivatives: dimensions (width/height) and thumbnails (thumbnail_path) in one pass';

    public function handle(ThumbnailService $service): int
    {
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $disk = (string) config('filesystems.media_disk', 'public');

        $query = Media::query()->where('file_type', 'image');

        if ($ids = $this->option('ids')) {
            $idList = array_filter(array_map('intval', explode(',', (string) $ids)));
            $query->whereIn('id', $idList);
        } elseif (!$force) {
            // One merged command behavior:
            // - if width/height is missing -> populate dimensions
            // - if thumbnail_path is missing -> generate thumbnail
            // - if all three are missing -> execute both parts in one pass
            $query->where(function ($q) {
                $q->whereNull('width')
                    ->orWhereNull('height')
                    ->orWhereNull('thumbnail_path');
            });
        }

        $idQuery = (clone $query)->orderBy('id')->select('id');

        if ($limit > 0) {
            $idQuery->limit($limit);
        }

        $selectedIds = $idQuery->pluck('id');
        $total = $selectedIds->count();

        if ($total === 0) {
            $this->info('No images to process.');
            return self::SUCCESS;
        }

        $this->line("Processing {$total} image(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $dimensionUpdated = 0;
        $dimensionFailed = 0;
        $dimensionSkipped = 0;

        $thumbGenerated = 0;
        $thumbSkipped = 0;
        $thumbFailed  = 0;

        Media::whereIn('id', $selectedIds)
            ->orderBy('id')
            ->chunk(50, function ($items) use (
                $service,
                $force,
                $disk,
                &$dimensionUpdated,
                &$dimensionFailed,
                &$dimensionSkipped,
                &$thumbGenerated,
                &$thumbSkipped,
                &$thumbFailed,
                $bar,
            ) {
                foreach ($items as $media) {
                    $needsDimensions = empty($media->width) || empty($media->height);

                    if ($needsDimensions) {
                        $dimensions = MediaDimensionExtractor::fromStorage(
                            $disk,
                            (string) $media->file_path,
                            (string) ($media->mime_type ?: 'application/octet-stream'),
                        );

                        if ($dimensions[0] !== null && $dimensions[1] !== null) {
                            $media->update([
                                'width' => $dimensions[0],
                                'height' => $dimensions[1],
                            ]);
                            $dimensionUpdated++;
                        } else {
                            $dimensionFailed++;
                        }
                    } else {
                        $dimensionSkipped++;
                    }

                    $shouldGenerateThumb = $force || empty($media->thumbnail_path);

                    if ($shouldGenerateThumb) {
                        if ($force && !empty($media->thumbnail_path)) {
                            // Delete old thumbnail before regenerating.
                            $service->delete($media);
                        }

                        $status = $service->generateWithStatus($media);

                        if ($status === 'generated') {
                            $thumbGenerated++;
                        } elseif ($status === 'skipped') {
                            $thumbSkipped++;
                        } else {
                            $thumbFailed++;
                        }
                    } else {
                        $thumbSkipped++;
                    }

                    $bar->advance();
                }
            });

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Task', 'Updated/Generated', 'Skipped', 'Failed'],
            [
                ['Dimensions (width/height)', $dimensionUpdated, $dimensionSkipped, $dimensionFailed],
                ['Thumbnails (thumbnail_path)', $thumbGenerated, $thumbSkipped, $thumbFailed],
            ],
        );

        $this->info('Done. Single command pass completed.');

        return self::SUCCESS;
    }
}
