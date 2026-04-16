<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory, SoftDeletes;

    protected static function mediaDisk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    protected static function mediaCdnUrl(): string
    {
        return rtrim((string) config('filesystems.cdn_url', ''), '/');
    }

    protected static function shouldUseMediaCdn(?string $disk = null): bool
    {
        $disk ??= self::mediaDisk();

        return $disk !== 'public' && self::mediaCdnUrl() !== '';
    }

    protected function mediaCdnPathUrl(string $path): string
    {
        return self::mediaCdnUrl() . '/' . ltrim($path, '/');
    }

    protected static function mediaDiskIsObjectStorage(string $disk): bool
    {
        return $disk !== 'public';
    }

    protected function plainUrl(string $disk): string
    {
        if ($disk === 'public') {
            return asset('storage/' . ltrim($this->file_path, '/'));
        }

        $baseUrl = (string) config("filesystems.disks.{$disk}.url", '');

        if ($baseUrl !== '') {
            return rtrim($baseUrl, '/') . '/' . ltrim($this->file_path, '/');
        }

        return asset('storage/' . ltrim($this->file_path, '/'));
    }

    protected $fillable = [
        "album_id",
        "user_id",
        "file_path",
        "thumbnail_path",
        "file_name",
        "file_type",
        "file_size",
        "mime_type",
        "width",
        "height",
        "duration",
        "taken_at",
        "thumb_sync",
    ];

    protected $casts = [
        "taken_at" => "datetime",
    ];

    protected $appends = ["url", "thumbnail_url"];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function album()
    {
        return $this->belongsTo(Album::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, "taggable");
    }

    /**
     * Get a presigned URL for this media file from Cloudflare R2.
     *
     * Using temporaryUrl() instead of url() for two reasons:
     *  1. The S3 adapter builds the presigned URL from the configured
     *     endpoint + bucket (path-style), so the bucket name "cx-memories"
     *     is automatically included in the URL path.
     *  2. The presigned query params (X-Amz-*) allow the browser to fetch
     *     the object directly from R2 without requiring the bucket to have
     *     public access enabled.
     *
     * TTL is set to 6 hours — long enough for any normal browsing session
     * while still limiting exposure of the signed credentials.
     */
    public function getUrlAttribute(): string
    {
        $disk = self::mediaDisk();

        if (self::shouldUseMediaCdn($disk)) {
            return $this->mediaCdnPathUrl($this->file_path);
        }

        try {
            /** @var FilesystemAdapter $storage */
            $storage = Storage::disk($disk);

            if (method_exists($storage, 'temporaryUrl')) {
                return $storage->temporaryUrl(
                    $this->file_path,
                    now()->addHours(6),
                );
            }

            return $this->plainUrl($disk);
        } catch (\Throwable $e) {
            Log::warning("Media: failed to generate presigned URL.", [
                "media_id" => $this->id,
                "file_path" => $this->file_path,
                "disk" => $disk,
                "error" => $e->getMessage(),
            ]);

            // Fall back to the plain (unsigned) URL so the attribute never
            // throws and the rest of the page can still render.
            return $this->plainUrl($disk);
        }
    }

    /**
     * Return the URL used for list/grid thumbnails.
     *
     * - When a Worker/CDN URL is configured for object storage, use the CDN
     *   path for the original object and let edge caching handle speed.
     * - Otherwise fall back to the locally-generated thumbnail path.
     */
    public function getThumbnailUrlAttribute(): ?string
    {
        $disk = self::mediaDisk();

        if (!empty($this->thumbnail_path)) {
            if (self::shouldUseMediaCdn($disk) && self::mediaDiskIsObjectStorage($disk)) {
                return $this->mediaCdnPathUrl((string) $this->thumbnail_path);
            }

            // For local/public disk, keep same-origin relative URL.
            return '/storage/' . ltrim((string) $this->thumbnail_path, '/');
        }

        // If thumbnail is missing, gracefully fall back to original media URL.
        return $this->getUrlAttribute();
    }

    /**
     * Generate a transformed image URL with optional format conversion and resizing.
     *
     * @param array $options {
     *     @var string $format One of: webp, avif, auto, json
     *     @var int $width Width in pixels (1-2048)
     *     @var int $height Height in pixels (1-2048)
     *     @var int $quality Quality 1-100 (default: 80)
     * }
     * @return string Transform URL or original URL if not CDN-backed
     */
    public function transformUrl(array $options = []): string
    {
        if (!self::shouldUseMediaCdn()) {
            return $this->url;
        }

        $baseUrl = $this->mediaCdnPathUrl($this->file_path);
        if (empty($options)) {
            return $baseUrl;
        }

        $params = [];

        if (!empty($options['format']) && in_array($options['format'], ['webp', 'avif', 'auto', 'json'], true)) {
            $params['f'] = $options['format'];
        }

        if (!empty($options['width'])) {
            $params['w'] = (int) $options['width'];
        }

        if (!empty($options['height'])) {
            $params['h'] = (int) $options['height'];
        }

        if (!empty($options['quality'])) {
            $params['q'] = (int) $options['quality'];
        }

        if (empty($params)) {
            return $baseUrl;
        }

        return $baseUrl . '?' . http_build_query($params);
    }

    /**
     * Convenience method: Get WebP version of image (auto quality).
     */
    public function webpUrl(int $width = null, int $height = null): string
    {
        return $this->transformUrl([
            'format' => 'webp',
            'width' => $width,
            'height' => $height,
            'quality' => 80,
        ]);
    }

    /**
     * Convenience method: Get AVIF version (better compression).
     */
    public function avifUrl(int $width = null, int $height = null): string
    {
        return $this->transformUrl([
            'format' => 'auto',
            'width' => $width,
            'height' => $height,
            'quality' => 75,
        ]);
    }

    /**
     * Convenience method: Get thumbnail at fixed size (typically for grid).
     * Defaults to 400x300 webp for 2x clarity on retina displays.
     */
    public function thumbnailUrl(int $width = 400, int $height = 300): string
    {
        return $this->transformUrl([
            'format' => 'webp',
            'width' => $width,
            'height' => $height,
            'quality' => 85,
        ]);
    }

    /**
     * Convenience method: Get responsive thumbnail sizes for srcset.
     * Returns array of [size => url] pairs.
     *
     * @example
     *     $srcset = $media->responsiveThumbnails();
     *     Output: ['200w' => 'https://...?f=webp&w=200&q=85', '400w' => '...?f=webp&w=400&q=85', ...]
     */
    public function responsiveThumbnails(array $widths = [200, 400, 600, 800, 1200]): array
    {
        return collect($widths)->mapWithKeys(function ($width) {
            return ["{$width}w" => $this->transformUrl([
                'format' => 'webp',
                'width' => $width,
                'quality' => 82,
            ])];
        })->toArray();
    }
}

