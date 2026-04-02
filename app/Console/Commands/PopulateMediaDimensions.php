<?php

namespace App\Console\Commands;

use App\Models\Media;
use Illuminate\Console\Command;
use App\Support\MediaDimensionExtractor;

class PopulateMediaDimensions extends Command
{
    protected $signature = 'media:populate-dimensions
        {--force : Skip confirmation prompt}
        {--batch=100 : Number of records to process per batch}
    ';

    protected $description = 'Populate width and height dimensions for existing media files in the database';

    public function handle(): int
    {
        $disk = (string) config('filesystems.media_disk', 'public');
        $batchSize = (int) $this->option('batch');
        $force = $this->option('force');

        // Count media without dimensions
        $totalWithoutDimensions = Media::where(function ($q) {
                $q->whereNull('width')->orWhereNull('height');
            })
            ->count();

        if ($totalWithoutDimensions === 0) {
            $this->info('✓ All media files already have dimensions set.');
            return 0;
        }

        $this->info(sprintf(
            'Found %d media file(s) without complete dimension data.',
            $totalWithoutDimensions
        ));

        if (!$force && !$this->confirm('Do you want to proceed with dimension extraction?')) {
            $this->info('Operation cancelled.');
            return 0;
        }

        $this->newLine();

        // Collect all IDs upfront so failed/non-image records are never re-fetched
        $ids = Media::where(function ($q) {
                $q->whereNull('width')->orWhereNull('height');
            })
            ->pluck('id')
            ->toArray();

        $processed = 0;
        $updated   = 0;
        $failed    = 0;

        $bar = $this->output->createProgressBar(count($ids));
        $bar->start();

        foreach (array_chunk($ids, $batchSize) as $idChunk) {
            $mediaItems = Media::whereIn('id', $idChunk)->get();

            foreach ($mediaItems as $media) {
                if (empty($media->file_path)) {
                    $failed++;
                    $processed++;
                    $bar->advance();
                    continue;
                }

                $dimensions = MediaDimensionExtractor::fromStorage(
                    $disk,
                    $media->file_path,
                    (string) ($media->mime_type ?: 'application/octet-stream'),
                );

                if ($dimensions[0] !== null && $dimensions[1] !== null) {
                    $media->update([
                        'width'  => $dimensions[0],
                        'height' => $dimensions[1],
                    ]);
                    $updated++;
                } else {
                    $failed++;
                }

                $processed++;
                $bar->advance();
            }
        }

        $bar->finish();
        $this->newLine(2);

        // Display summary
        $this->table(
            ['Metric', 'Count'],
            [
                ['Updated',  $updated],
                ['Failed/Skipped', $failed],
                ['Total Processed', $processed],
            ],
        );

        if ($updated > 0) {
            $this->info(sprintf('✓ Successfully updated %d media file(s) with dimensions.', $updated));
        }

        if ($failed > 0) {
            $this->warn(sprintf('⚠ %d file(s) could not be processed (unsupported type or read/probe error).', $failed));
        }

        return 0;
    }
}
