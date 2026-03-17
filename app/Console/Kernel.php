<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use App\Console\Commands\DriveImport;
use App\Console\Commands\PurgeOldRecycleBinItems;

class Kernel extends ConsoleKernel
{
    protected $commands = [DriveImport::class, PurgeOldRecycleBinItems::class];

    protected function schedule(Schedule $schedule)
    {
        // Purge Recycle Bin items older than 7 days every day at midnight.
        // Override the retention period without touching code:
        //   php artisan recycle-bin:purge --days=30
        $schedule
            ->command("recycle-bin:purge")
            ->dailyAt("00:00")
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path("logs/recycle-bin-purge.log"));
    }

    protected function commands()
    {
        $this->load(__DIR__ . "/Commands");

        require base_path("routes/console.php");
    }
}
