<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    public function albums()
    {
        return $this->morphedByMany(Album::class, 'taggable');
    }

    public function media()
    {
        return $this->morphedByMany(Media::class, 'taggable');
    }
}
