<?php

namespace App\Jobs;

use App\Services\MediaDerivativeSyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncMediaDerivativesChunkJob implements ShouldQueue
{
    use Queueable, InteractsWithQueue, SerializesModels;

    public int $tries = 1;
    public int $timeout = 7200;

    /**
     * @param array<int> $mediaIds
     */
    public function __construct(
        public array $mediaIds,
        public bool $force,
        public string $disk,
    ) {
    }

    public function handle(MediaDerivativeSyncService $syncService): void
    {
        $ids = array_values(array_filter(array_map('intval', $this->mediaIds)));

        if (empty($ids)) {
            return;
        }

        $stats = $syncService->syncByIds($ids, $this->force, $this->disk);

        Log::info('SyncMediaDerivativesChunkJob completed.', [
            'media_count' => count($ids),
            'force' => $this->force,
            'disk' => $this->disk,
            'stats' => $stats,
        ]);
    }
}
