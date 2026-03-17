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
        "file_name",
        "file_type",
        "file_size",
        "mime_type",
        "width",
        "height",
        "duration",
        "taken_at",
    ];

    protected $casts = [
        "taken_at" => "datetime",
    ];

    protected $appends = ["url"];

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
}
