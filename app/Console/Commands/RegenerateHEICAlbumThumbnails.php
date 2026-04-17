<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\HeicJpegConversionService;
use Illuminate\Console\Command;

class RegenerateHEICAlbumThumbnails extends Command
{
    protected $signature = 'thumbnails:heic-regenerate
        {--prefix=albums : R2 path prefix scope (default: albums)}
        {--album= : Optional album ID or slug to scope cleanup}
        {--media-id= : Optional media ID to force-regenerate one HEIC image}
        {--dry-run : Preview without deleting/generating thumbnails}
        {--chunk=100 : Number of media records to process per chunk}
        {--limit=25 : Max HEIC images per run (auto-clamped to 20-25)}';

    protected $description = 'Delete old HEIC thumbnails and regenerate by converting HEIC to JPG in selected scope.';

    public function __construct()
    {
        parent::__construct();

        @ini_set('memory_limit', '-1');
    }

    public function handle(HeicJpegConversionService $thumbnailService): int
    {
        $prefix = $this->normalizePath((string) $this->option('prefix'));
        $albumOption = trim((string) $this->option('album'));
        $mediaIdOption = trim((string) $this->option('media-id'));
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limit = max(20, min(25, (int) $this->option('limit')));

        $targetMediaId = null;
        if ($mediaIdOption !== '') {
            if (!ctype_digit($mediaIdOption)) {
                $this->error("Invalid --media-id value [{$mediaIdOption}]. It must be a numeric ID.");
                return self::FAILURE;
            }
            $targetMediaId = (int) $mediaIdOption;
        }

        $album = null;
        if ($targetMediaId === null && $albumOption !== '') {
            $album = $this->resolveAlbum($albumOption);

            if (! $album) {
                $this->error("Album not found for --album={$albumOption}");
                return self::FAILURE;
            }
        }

        $query = Media::query()
            // ->whereIn('file_type', ['image', 'video'])
            ->whereIn('file_type', ['image'])
            ->whereRaw('LOWER(mime_type) = ?', ['image/heic'])
            ->orderBy('id');

        if ($targetMediaId !== null) {
            $query->where('id', $targetMediaId);
            $this->info("Scoping to media #{$targetMediaId}.");
        } elseif ($album) {
            $query->where('thumb_sync', 0);
            $query->where('album_id', $album->id);
            $this->info("Scoping to album {$album->title} (#{$album->id}).");
        } else {
            $query->where('thumb_sync', 0);
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

        $mediaIds = (clone $query)
            ->limit($limit)
            ->pluck('id');

        $total = $mediaIds->count();

        if ($total === 0) {
            if ($targetMediaId !== null) {
                $this->info("No HEIC image record found for media #{$targetMediaId}.");
            } elseif ($album) {
                $this->info("No HEIC image records found in album {$album->title} (#{$album->id}).");
            } else {
                $this->info('No HEIC image records found in selected prefix scope.');
            }
            return self::SUCCESS;
        }

        $query = Media::query()
            ->whereIn('id', $mediaIds)
            ->orderBy('id');

        if ($dryRun) {
            $this->warn('[DRY RUN] No thumbnails will be deleted or regenerated.');
        }

        if ($targetMediaId !== null) {
            $this->info("Processing media #{$targetMediaId} with {$total} HEIC media record(s), limit {$limit}...");
        } elseif ($album) {
            $this->info("Processing album {$album->title} (#{$album->id}) with {$total} HEIC media record(s), limit {$limit}...");
        } else {
            $this->info("Processing prefix {$prefix} with {$total} HEIC media record(s), limit {$limit}...");
        }

        $deletedOld = 0;
        $regenerated = 0;
        $skipped = 0;
        $failed = 0;
        $generatedMedia = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($items) use (
            $thumbnailService,
            $dryRun,
            &$deletedOld,
            &$regenerated,
            &$skipped,
            &$failed,
            &$generatedMedia,
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
                        $generatedMedia[] = [
                            'id' => $media->id,
                            'file_name' => $media->file_name,
                        ];
                        continue;
                    }

                    $status = $thumbnailService->generateWithStatus($media);

                    if ($status === 'generated') {
                        $regenerated++;
                        $generatedMedia[] = [
                            'id' => $media->id,
                            'file_name' => $media->file_name,
                        ];
                        $thumbnailService->syncDimensionsFromThumbnail($media);
                        // Set thumb_sync to 1 if regenerated successfully
                        $media->thumb_sync = 1;
                        $media->save();
                    } elseif ($status === 'skipped') {
                        $skipped++;
                    } else {
                        $this->cleanupFailedThumbnail($thumbnailService, $media);
                        $failed++;
                        $reason = $thumbnailService->getLastErrorForMedia($media->id);
                        $this->newLine();
                        $this->warn(
                            $reason
                                ? "  [FAILED] Media #{$media->id} ({$media->file_name}) - {$reason}"
                                : "  [FAILED] Media #{$media->id} ({$media->file_name})"
                        );
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
                ['Scope', $targetMediaId !== null ? "Media #{$targetMediaId}" : ($album ? "Album {$album->title} (#{$album->id})" : "Prefix {$prefix}")],
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

        if (count($generatedMedia) > 0) {
            $this->newLine();
            $this->info($dryRun
                ? 'HEIC thumbnails that would be generated (media ID and file name):'
                : 'HEIC thumbnails generated (media ID and file name):');

            $this->table(
                ['Media ID', 'File name'],
                array_map(
                    static fn (array $item): array => [$item['id'], $item['file_name']],
                    $generatedMedia
                ),
            );
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


    private function cleanupFailedThumbnail(HeicJpegConversionService $thumbnailService, Media $media): void
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
