<?php

namespace App\Console\Commands;

use App\Models\Album;
use App\Models\Media;
use App\Services\HeicJpegConversionService;
use App\Services\ThumbnailService;
use Illuminate\Console\Command;

class RegenerateHEICAlbumThumbnails extends Command
{
    protected $signature = 'thumbnails:heic-regenerate
        {--prefix=albums : R2 path prefix scope (default: albums)}
        {--album= : Optional album ID or slug to scope cleanup}
        {--media-id= : Optional media ID to force-regenerate one HEIC media}
        {--force : Regenerate even when thumb_sync is already 2}
        {--dry-run : Preview without deleting/generating thumbnails}
        {--chunk=100 : Number of media records to process per chunk}
        {--limit=0 : Max HEIC media per run (0 = process all)}';

    protected $description = 'Regenerate HEIC/HEIF media derivatives, auto-correct image/video type, and report skip reasons.';

    public function __construct()
    {
        parent::__construct();

        @ini_set('memory_limit', '-1');
    }

    public function handle(
        HeicJpegConversionService $thumbnailService,
        ThumbnailService $videoThumbnailService
    ): int
    {
        $prefix = $this->normalizePath((string) $this->option('prefix'));
        $albumOption = trim((string) $this->option('album'));
        $mediaIdOption = trim((string) $this->option('media-id'));
        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $limitOption = (int) $this->option('limit');
        $limit = $limitOption > 0 ? $limitOption : null;

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
            ->whereIn('file_type', ['image', 'video'])
            ->where(function ($candidate) {
                $candidate
                    ->whereRaw('LOWER(file_name) LIKE ?', ['%.heic'])
                    ->orWhereRaw('LOWER(file_path) LIKE ?', ['%.heic'])
                    ->orWhereRaw('LOWER(mime_type) LIKE ?', ['%heic%']);
            })
            ->where(function ($sync) {
                $sync->whereNull('thumb_sync')
                    ->orWhere('thumb_sync', '!=', 2);
            })
            ->orderBy('id');

        if ($targetMediaId !== null) {
            $query->where('id', $targetMediaId);
            $this->info("Scoping to media #{$targetMediaId}.");
        } elseif ($album) {
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

        if ($limit !== null) {
            $mediaIds = (clone $query)
                ->limit($limit)
                ->pluck('id');

            $total = $mediaIds->count();
            $query = Media::query()
                ->whereIn('id', $mediaIds)
                ->orderBy('id');
        } else {
            $total = (clone $query)->count();
            $query = (clone $query)->orderBy('id');
        }

        $limitLabel = $limit === null ? 'all' : (string) $limit;

        if ($total === 0) {
            if ($targetMediaId !== null) {
                $this->info("No HEIC media record found for media #{$targetMediaId}.");
            } elseif ($album) {
                $this->info("No HEIC media records found in album {$album->title} (#{$album->id}).");
            } else {
                $this->info('No HEIC media records found in selected prefix scope.');
            }
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->warn('[DRY RUN] No thumbnails will be deleted or regenerated.');
        }
        if ($force) {
            $this->warn('[FORCE] Option is currently informational; this command only processes records where thumb_sync != 2.');
        }

        if ($targetMediaId !== null) {
            $this->info("Processing media #{$targetMediaId} with {$total} HEIC/HEIF record(s), limit {$limitLabel}...");
        } elseif ($album) {
            $this->info("Processing album {$album->title} (#{$album->id}) with {$total} HEIC/HEIF record(s), limit {$limitLabel}...");
        } else {
            $this->info("Processing prefix {$prefix} with {$total} HEIC/HEIF record(s), limit {$limitLabel}...");
        }

        $deletedOld = 0;
        $regenerated = 0;
        $skipped = 0;
        $failed = 0;
        $updatedType = 0;
        $queuedForReprocess = 0;
        $generatedMedia = [];

        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $query->chunkById($chunkSize, function ($items) use (
            $thumbnailService,
            $videoThumbnailService,
            $dryRun,
            &$deletedOld,
            &$regenerated,
            &$skipped,
            &$failed,
            &$updatedType,
            &$queuedForReprocess,
            &$generatedMedia,
            $bar,
        ) {
            foreach ($items as $media) {
                try {
                    $routing = $this->normalizeMediaTypeForProcessing($media, $dryRun);
                    if (($routing['type_updated'] ?? false) === true) {
                        $updatedType++;
                    }

                    $targetType = (string) ($routing['target_type'] ?? 'image');
                    $isVideoTarget = $targetType === 'video';
                    $activeService = $isVideoTarget ? $videoThumbnailService : $thumbnailService;

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

                    $status = $activeService->generateWithStatus($media);

                    if ($status === 'generated') {
                        $regenerated++;
                        $generatedMedia[] = [
                            'id' => $media->id,
                            'file_name' => $media->file_name,
                        ];
                        $activeService->syncDimensionsFromThumbnail($media);
                        // Use thumb_sync=2 for HEIC images, and thumb_sync=1 for videos.
                        $media->thumb_sync = $isVideoTarget ? 1 : 2;
                        $media->save();
                    } elseif ($status === 'skipped') {
                        $skipped++;
                        $reason = $isVideoTarget
                            ? $videoThumbnailService->getLastErrorForMedia($media->id)
                            : $thumbnailService->getLastErrorForMedia($media->id);
                        $this->newLine();
                        $this->warn(
                            $reason
                                ? "  [SKIPPED] Media #{$media->id} ({$media->file_name}) - {$reason}"
                                : "  [SKIPPED] Media #{$media->id} ({$media->file_name})"
                        );
                    } else {
                        $reason = $isVideoTarget
                            ? $videoThumbnailService->getLastErrorForMedia($media->id)
                            : $thumbnailService->getLastErrorForMedia($media->id);

                        // Some records are labeled as HEIC but the source object is actually
                        // a QuickTime/video container. Route those through video thumbnail flow.
                        if (!$isVideoTarget && $this->isLikelyQuickTimeVideoMismatch($reason)) {
                            if (!$dryRun && $this->normalizeVideoMismatchRecord($media)) {
                                $updatedType++;
                                $queuedForReprocess++;
                            }

                            $videoStatus = $this->tryVideoThumbnailFallback($videoThumbnailService, $media);

                            if ($videoStatus === 'generated') {
                                $regenerated++;
                                $queuedForReprocess = max(0, $queuedForReprocess - 1);
                                $generatedMedia[] = [
                                    'id' => $media->id,
                                    'file_name' => $media->file_name,
                                ];
                                $videoThumbnailService->syncDimensionsFromThumbnail($media);
                                $media->thumb_sync = 1;
                                $media->save();
                                continue;
                            }

                            if ($videoStatus === 'skipped') {
                                $skipped++;
                                $fallbackReason = $videoThumbnailService->getLastErrorForMedia($media->id);
                                if ($fallbackReason) {
                                    $this->newLine();
                                    $this->warn("  [SKIPPED] Media #{$media->id} ({$media->file_name}) - {$fallbackReason}");
                                }
                                continue;
                            }
                        }

                        $this->cleanupFailedThumbnail($thumbnailService, $media);
                        $media->thumb_sync = 0;
                        $media->save();
                        $failed++;
                        $this->newLine();
                        $this->warn(
                            $reason
                                ? "  [FAILED] Media #{$media->id} ({$media->file_name}) - {$reason}"
                                : "  [FAILED] Media #{$media->id} ({$media->file_name})"
                        );
                    }
                } catch (\Throwable $e) {
                    $this->cleanupFailedThumbnail($thumbnailService, $media);
                    $media->thumb_sync = 0;
                    $media->save();
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
                ['Updated type/mime', $updatedType],
                ['Queued for reprocess', $queuedForReprocess],
            ],
        );

        if ($failed > 0) {
            $this->warn('Completed with some failures.');
        }

        if (count($generatedMedia) > 0) {
            $this->newLine();
            $this->info($dryRun
                ? 'HEIC/HEIF derivatives that would be generated (media ID and file name):'
                : 'HEIC/HEIF derivatives generated (media ID and file name):');

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

    private function isLikelyQuickTimeVideoMismatch(?string $reason): bool
    {
        if (!is_string($reason) || trim($reason) === '') {
            return false;
        }

        $normalized = strtolower($reason);
        return str_contains($normalized, 'major brand: qt')
            || str_contains($normalized, 'compatible: qt')
            || str_contains($normalized, 'input buffer is not a heic image');
    }

    private function tryVideoThumbnailFallback(ThumbnailService $thumbnailService, Media $media): string
    {
        $currentMime = strtolower((string) ($media->mime_type ?? ''));

        try {
            $media->file_type = 'video';
            if (str_starts_with($currentMime, 'image/heic') || str_starts_with($currentMime, 'image/heif')) {
                $media->mime_type = 'video/quicktime';
            }

            $status = $thumbnailService->generateWithStatus($media);
            if ($status === 'generated') {
                $media->file_type = 'video';
                if (str_starts_with($currentMime, 'image/heic') || str_starts_with($currentMime, 'image/heif')) {
                    $media->mime_type = 'video/quicktime';
                }
                $media->save();
            }

            return $status;
        } catch (\Throwable $e) {
            return 'failed';
        }
    }

    private function normalizeVideoMismatchRecord(Media $media): bool
    {
        $originalType = (string) ($media->file_type ?? '');
        $originalMime = strtolower((string) ($media->mime_type ?? ''));
        $targetMime = $originalMime;

        if (str_starts_with($originalMime, 'image/heic') || str_starts_with($originalMime, 'image/heif')) {
            $targetMime = 'video/quicktime';
        }

        $changed = $originalType !== 'video'
            || $targetMime !== $originalMime
            || (int) ($media->thumb_sync ?? 0) !== 0;

        if (!$changed) {
            return false;
        }

        $media->file_type = 'video';
        $media->mime_type = $targetMime;
        $media->thumb_sync = 0;
        $media->save();

        return true;
    }

    private function normalizeMediaTypeForProcessing(Media $media, bool $dryRun): array
    {
        $currentType = strtolower((string) ($media->file_type ?? 'image'));
        $currentMime = strtolower((string) ($media->mime_type ?? ''));
        $fileName = strtolower((string) ($media->file_name ?? ''));
        $filePath = strtolower((string) ($media->file_path ?? ''));

        $targetType = $currentType;
        $targetMime = $currentMime;

        if ($this->looksLikeVideo($currentMime, $fileName, $filePath)) {
            $targetType = 'video';
            if ($targetMime === '' || str_starts_with($targetMime, 'image/heic') || str_starts_with($targetMime, 'image/heif')) {
                $targetMime = 'video/quicktime';
            }
        } else {
            $targetType = 'image';
            if ($targetMime === '' || str_starts_with($targetMime, 'video/')) {
                $targetMime = 'image/heic';
            }
        }

        $typeUpdated = $targetType !== $currentType || $targetMime !== $currentMime;
        if ($typeUpdated && !$dryRun) {
            $media->file_type = $targetType;
            $media->mime_type = $targetMime;
            if ($targetType === 'video') {
                // Ensure corrected videos remain in pending queue until thumbnail is generated.
                $media->thumb_sync = 0;
            }
            $media->save();
        }

        if ($typeUpdated && $dryRun) {
            $media->file_type = $targetType;
            $media->mime_type = $targetMime;
        }

        return [
            'target_type' => $targetType,
            'target_mime' => $targetMime,
            'type_updated' => $typeUpdated,
        ];
    }

    private function looksLikeVideo(string $mime, string $fileName, string $filePath): bool
    {
        if (str_starts_with($mime, 'video/') || str_contains($mime, 'quicktime')) {
            return true;
        }

        foreach ([$fileName, $filePath] as $candidate) {
            if (preg_match('/\.(mov|mp4|m4v|3gp|avi|mkv|webm)$/i', $candidate) === 1) {
                return true;
            }
        }

        return false;
    }
}
