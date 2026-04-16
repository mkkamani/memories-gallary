<?php

namespace App\Console\Commands;

use App\Jobs\SyncMediaDerivativesChunkJob;
use App\Models\Media;
use App\Services\MediaDerivativeSyncService;
use Illuminate\Console\Command;

class SyncMediaDerivatives extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sync:media-records
                            {--force : Regenerate even if a thumbnail already exists}
                            {--ids=  : Comma-separated list of media IDs to process}
                            {--limit=0 : Maximum number of items to process (0 = all)}
                            {--queue : Dispatch processing to queue jobs}
                            {--queue-chunk=200 : Number of media IDs per queued job}
                            {--queue-name=media-sync : Queue name for dispatched jobs}';

    /**
     * @var string
     */
    protected $description = 'Sync missing media derivatives: dimensions (images only) and thumbnails for images/videos in one pass';

    public function handle(MediaDerivativeSyncService $syncService): int
    {
        $force = (bool) $this->option('force');
        $limit = (int) $this->option('limit');
        $useQueue = (bool) $this->option('queue');
        $disk = (string) config('filesystems.media_disk', 'public');

        $query = Media::query()->whereIn('file_type', ['image', 'video']);
        // $query = Media::query()->where('file_type', 'video');

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
            $this->info('No image/video items to process.');
            return self::SUCCESS;
        }

        if ($useQueue) {
            $chunkSize = max(1, (int) $this->option('queue-chunk'));
            $queueName = trim((string) $this->option('queue-name')) ?: 'media-sync';
            $chunks = array_chunk($selectedIds->all(), $chunkSize);

            foreach ($chunks as $chunk) {
                SyncMediaDerivativesChunkJob::dispatch($chunk, $force, $disk)
                    ->onQueue($queueName);
            }

            $this->info(sprintf(
                'Dispatched %d media item(s) as %d queued job(s) on queue "%s".',
                $total,
                count($chunks),
                $queueName,
            ));

            $this->line('Start a queue worker to process them, e.g.: php artisan queue:work --queue=' . $queueName . ' --tries=1 --timeout=7200');

            return self::SUCCESS;
        }

        $this->line("Processing {$total} media item(s)…");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $stats = $syncService->syncByIds(
            $selectedIds->all(),
            $force,
            $disk,
            fn() => $bar->advance(),
        );

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Task', 'Updated/Generated', 'Skipped', 'Failed'],
            [
                ['Dimensions (width/height)', $stats['dimensionUpdated'], $stats['dimensionSkipped'], $stats['dimensionFailed']],
                ['Thumbnails (thumbnail_path)', $stats['thumbGenerated'], $stats['thumbSkipped'], $stats['thumbFailed']],
            ],
        );

        $this->info('Done. Single command pass completed.');

        return self::SUCCESS;
    }
}
