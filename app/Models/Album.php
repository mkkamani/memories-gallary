<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use App\Models\Media;
use App\Models\Tag;

class Album extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'parent_id',
        'title',
        'slug',
        'description',
        'cover_image',
        'type',
        'event_date',
        'is_public',
    ];

    protected $casts = [
        'event_date' => 'date',
        'is_public' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function media()
    {
        return $this->hasMany(Media::class);
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    // Parent-child relationships for nested albums
    public function parent()
    {
        return $this->belongsTo(Album::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Album::class, 'parent_id');
    }

    // Get all ancestors (parent, grandparent, etc.)
    public function ancestors()
    {
        $ancestors = collect();
        $parent = $this->parent;

        while ($parent) {
            $ancestors->push($parent);
            $parent = $parent->parent;
        }

        return $ancestors;
    }

    // Get all descendants (children, grandchildren, etc.)
    public function descendants()
    {
        $descendants = collect();

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->descendants());
        }

        return $descendants;
    }

    // Check if this album is a descendant of another album
    public function isDescendantOf($album)
    {
        if (!$album) {
            return false;
        }

        $parent = $this->parent;

        while ($parent) {
            if ($parent->id === $album->id) {
                return true;
            }
            $parent = $parent->parent;
        }

        return false;
    }
}
