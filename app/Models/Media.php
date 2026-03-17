<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Media extends Model
{
    use HasFactory, SoftDeletes;

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
        try {
            return Storage::disk("r2")->temporaryUrl(
                $this->file_path,
                now()->addHours(6),
            );
        } catch (\Throwable $e) {
            \Log::warning("Media: failed to generate presigned URL.", [
                "media_id" => $this->id,
                "file_path" => $this->file_path,
                "error" => $e->getMessage(),
            ]);

            // Fall back to the plain (unsigned) URL so the attribute never
            // throws and the rest of the page can still render.
            return Storage::disk("r2")->url($this->file_path);
        }
    }
}
