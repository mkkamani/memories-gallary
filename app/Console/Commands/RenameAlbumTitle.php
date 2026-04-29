<?php

namespace App\Console\Commands;

use App\Models\Album;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class RenameAlbumTitle extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:rename-album-title';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rename album titles based on location and date taken of media items';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting album title renaming...');
        $albums = Album::with('media')->get();

        foreach ($albums as $album) {
            $oldTitle = $album->title;
            $album->title = Str::replace('-', ' ', $album->title);
            $this->info($oldTitle . ' => ' . $album->title);
            $album->save();
        }
        $this->info('Album title renaming completed.');
    }
}
