<?php

namespace App\Services;

use App\Models\Media;
use App\Support\MediaDimensionExtractor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class MediaDerivativeSyncService
{
    public function __construct(private readonly ThumbnailService $thumbnailService)
    {
    }

    /**
     * Sync thumbnails and dimensions for a selected set of media IDs.
     *
     * @param array<int> $selectedIds
     * @param callable|null $onAdvance Called after each processed media item.
     * @return array{dimensionUpdated:int,dimensionSkipped:int,dimensionFailed:int,thumbGenerated:int,thumbSkipped:int,thumbFailed:int}
     */
    public function syncByIds(array $selectedIds, bool $force, string $disk, ?callable $onAdvance = null): array
    {
        $stats = [
            'dimensionUpdated' => 0,
            'dimensionSkipped' => 0,
            'dimensionFailed' => 0,
            'thumbGenerated' => 0,
            'thumbSkipped' => 0,
            'thumbFailed' => 0,
        ];

        Media::whereIn('id', $selectedIds)
            ->orderBy('id')
            ->chunk(50, function ($items) use ($force, $disk, &$stats, $onAdvance) {
                foreach ($items as $media) {
                    try {
                        $deriveDimensionsFromThumbnail = $this->shouldDeriveDimensionsFromThumbnail($media);
                        $thumbnailGeneratedNow = false;

                        $canSyncDimensions = $media->file_type === 'image' && !$deriveDimensionsFromThumbnail;
                        $needsDimensions = $canSyncDimensions && (empty($media->width) || empty($media->height));

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
                                $stats['dimensionUpdated']++;
                            } else {
                                $stats['dimensionFailed']++;
                            }
                        } elseif ($canSyncDimensions) {
                            $stats['dimensionSkipped']++;
                        }

                        $shouldGenerateThumb = $force || empty($media->thumbnail_path);

                        if ($shouldGenerateThumb) {
                            if ($force && !empty($media->thumbnail_path)) {
                                $this->thumbnailService->delete($media);
                            }

                            $status = $this->thumbnailService->generateWithStatus($media);

                            if ($status === 'generated') {
                                $stats['thumbGenerated']++;
                                $thumbnailGeneratedNow = true;
                            } elseif ($status === 'skipped') {
                                $stats['thumbSkipped']++;
                            } else {
                                $stats['thumbFailed']++;
                            }
                        } else {
                            $stats['thumbSkipped']++;
                        }

                        $needsDimensionsFromThumb = $deriveDimensionsFromThumbnail
                            && ($force || $thumbnailGeneratedNow || empty($media->width) || empty($media->height));

                        if ($needsDimensionsFromThumb) {
                            if ($thumbnailGeneratedNow) {
                                $media->refresh();
                            }

                            if ($this->updateDimensionsFromThumbnail($media)) {
                                $stats['dimensionUpdated']++;
                            } else {
                                $stats['dimensionFailed']++;
                            }
                        } elseif ($deriveDimensionsFromThumbnail) {
                            $stats['dimensionSkipped']++;
                        }
                    } catch (\Throwable $e) {
                        $stats['dimensionFailed']++;
                        $stats['thumbFailed']++;

                        Log::warning('MediaDerivativeSyncService: item failed, continuing.', [
                            'media_id' => $media->id,
                            'file_path' => $media->file_path,
                            'mime_type' => $media->mime_type,
                            'error' => $e->getMessage(),
                        ]);
                    } finally {
                        if ($onAdvance !== null) {
                            $onAdvance();
                        }
                    }
                }
            });

        return $stats;
    }

    private function shouldDeriveDimensionsFromThumbnail(Media $media): bool
    {
        $mime = strtolower((string) ($media->mime_type ?? ''));

        return $media->file_type === 'video'
            || str_contains($mime, 'image/heic')
            || str_contains($mime, 'image/heif');
    }

    private function updateDimensionsFromThumbnail(Media $media): bool
    {
        $thumbPath = (string) ($media->thumbnail_path ?? '');
        if ($thumbPath === '') {
            return false;
        }

        $mediaDisk = (string) config('filesystems.media_disk', 'public');
        $candidateDisks = array_values(array_unique([$mediaDisk, 'public']));

        foreach ($candidateDisks as $disk) {
            try {
                if (!Storage::disk($disk)->exists($thumbPath)) {
                    continue;
                }

                $binary = Storage::disk($disk)->get($thumbPath);
                $size = @getimagesizefromstring($binary);

                if ($size === false) {
                    continue;
                }

                $width = (int) ($size[0] ?? 0);
                $height = (int) ($size[1] ?? 0);

                if ($width <= 0 || $height <= 0) {
                    continue;
                }

                $media->update([
                    'width' => $width,
                    'height' => $height,
                ]);

                return true;
            } catch (\Throwable) {
                // Try next candidate disk.
            }
        }

        return false;
    }
}
