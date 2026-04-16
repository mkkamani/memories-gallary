<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class CleanupHeicThumbnails extends Command
{
    protected $signature = 'thumbnails:cleanup-heic
        {--prefix=albums : R2 path prefix scope (default: albums)}
        {--album= : Optional album ID or slug to scope cleanup}
        {--dry-run : Preview records without deleting thumbnails or updating DB}
        {--chunk=200 : Number of records to process per chunk}
        {--with-trashed : Include soft-deleted media records}';

    protected $description = 'Delete stored thumbnails for HEIC media and set thumbnail_path to null.';

    public function handle(ThumbnailService $thumbnailService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $albumOption = trim((string) $this->option('album'));
        $withTrashed = (bool) $this->option('with-trashed');
        $prefix = $this->normalizePath((string) $this->option('prefix'));

        $query = $withTrashed ? Media::withTrashed() : Media::query();

        $query
            ->where('mime_type', 'image/heic')
            ->whereNotNull('thumbnail_path')
            ->where('thumbnail_path', '!=', '');

        if ($albumOption !== '') {
            $album = $this->resolveAlbum($albumOption);

            if (! $album) {
                $this->error("Album not found for --album={$albumOption}");
                return self::FAILURE;
            }

            $query->where('album_id', $album->id);
            $this->info("Scoping to album {$album->title} (#{$album->id}).");
        } elseif ($prefix !== '') {
            $query->where(function ($scoped) use ($prefix) {
                $scoped->whereRaw('TRIM(LEADING \'/\' FROM file_path) = ?', [$prefix])
                    ->orWhereRaw('TRIM(LEADING \'/\' FROM file_path) LIKE ?', [$prefix . '/%']);
            });

            $this->info("Scoping to prefix [{$prefix}].");
        }

        $total = (int) (clone $query)->count();

        if ($total === 0) {
            $this->info('No HEIC media rows with thumbnail_path found.');
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No thumbnails will be deleted and no DB rows will be updated.');
        }

        $deletedFromStorage = 0;
        $clearedInDb = 0;
        $failed = 0;

        $this->info("Processing {$total} record(s)...");

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query
            ->orderBy('id')
            ->chunkById($chunkSize, function ($mediaRows) use ($thumbnailService, $dryRun, &$deletedFromStorage, &$clearedInDb, &$failed, $bar) {
                foreach ($mediaRows as $media) {
                    try {
                        if ($dryRun) {
                            $clearedInDb++;
                            $deletedFromStorage++;
                        } else {
                            $thumbnailService->delete($media);
                            $media->refresh();

                            if (empty($media->thumbnail_path)) {
                                $clearedInDb++;
                                $deletedFromStorage++;
                            } else {
                                $failed++;
                                $this->newLine();
                                $this->warn("  [FAILED] Media #{$media->id} thumbnail_path is still set.");
                            }
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
                ['Matched HEIC records', $total],
                ['Thumbnails deleted (storage)', $deletedFromStorage],
                ['thumbnail_path cleared (DB)', $clearedInDb],
                ['Failed', $failed],
            ],
        );

        if ($failed > 0) {
            $this->warn('Completed with failures. Check logs/output for details.');
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
}
