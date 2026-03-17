<?php

use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . "/../routes/web.php",
        commands: __DIR__ . "/../routes/console.php",
        health: "/up",
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(
            append: [
                \App\Http\Middleware\HandleInertiaRequests::class,
                \Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            ],
        );

        $middleware->alias([
            "admin" => \App\Http\Middleware\EnsureUserIsAdmin::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        // Permanently delete media and albums that have been in the Recycle Bin
        // for more than 7 days, and remove their files from the R2 bucket.
        //
        // Runs every day at midnight (server time).
        // Output is appended to storage/logs/recycle-bin-purge.log so every
        // run is auditable without filling up the main Laravel log.
        //
        // To run manually (e.g. for testing or a one-off cleanup):
        //   php artisan recycle-bin:purge
        //   php artisan recycle-bin:purge --days=30
        $schedule
            ->command("recycle-bin:purge")
            ->dailyAt("00:00")
            ->withoutOverlapping()
            ->runInBackground()
            ->appendOutputTo(storage_path("logs/recycle-bin-purge.log"));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (PostTooLargeException $e, $request) {
            $postMax = ini_get("post_max_size");

            return back()
                ->withErrors([
                    "files" => "The upload is too large for the server limit ({$postMax}). Please upload a smaller file or increase the server upload limit.",
                ])
                ->with(
                    "error",
                    "Upload failed because the request exceeded the server size limit.",
                );
        });
    })
    ->create();
