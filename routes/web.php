<?php

use App\Http\Controllers\AlbumController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecycleBinController;
use App\Http\Controllers\TerminalController;
use App\Models\Media;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

Route::get("/", function () {
    return Inertia::render("Welcome", [
        "canLogin" => Route::has("login"),
        "canRegister" => Route::has("register"),
    ]);
})->name("home");

Route::get('/phpinfo', function () {
    return response()->make(phpinfo());
});

Route::get('/test', function() {
    $data['total'] = Media::whereIn('mime_type', ['image/heic', 'image/heif'])->count();

    // $data['total'] = Media::where('height', 512)->where('width', 512)->where('thumbnail_path', '!=', null)->count();
    // $data['data'] = Media::where('height', 512)->where('width', 512)->where('thumbnail_path', '!=', null)->get()->toArray();
    // $data['clear'] = Media::where('height', 512)->where('width', 512)->where('thumbnail_path', '!=', null)->update(['width' => null, 'height' => null]);

    return response()->json($data);
});

Route::get('/media-listing', function () {
    $sortColumn = request('sort', 'id');
    $sortDirection = request('direction', 'desc');

    // Validate sort direction
    if (!in_array($sortDirection, ['asc', 'desc'])) {
        $sortDirection = 'desc';
    }

    $media = DB::table('media')->orderBy($sortColumn, $sortDirection)->paginate(50);

    $firstRow = $media->first();
    $columns = $firstRow ? array_keys((array) $firstRow) : Schema::getColumnListing('media');

    return view('listing.media', [
        'media' => $media,
        'columns' => $columns,
        'sortColumn' => $sortColumn,
        'sortDirection' => $sortDirection,
    ]);
});

Route::get('/job', function () {
    $jobs = DB::table('jobs')
        ->orderByDesc('id')
        ->paginate(50, ['*'], 'jobs_page')
        ->withQueryString();

    $failedJobs = DB::table('failed_jobs')
        ->orderByDesc('failed_at')
        ->paginate(50, ['*'], 'failed_jobs_page')
        ->withQueryString();

    $jobsFirstRow = $jobs->first();
    $jobsColumns = $jobsFirstRow ? array_keys((array) $jobsFirstRow) : Schema::getColumnListing('jobs');

    $failedJobsFirstRow = $failedJobs->first();
    $failedJobsColumns = $failedJobsFirstRow ? array_keys((array) $failedJobsFirstRow) : Schema::getColumnListing('failed_jobs');

    return view('listing.jobs', [
        'jobs' => $jobs,
        'jobsColumns' => $jobsColumns,
        'failedJobs' => $failedJobs,
        'failedJobsColumns' => $failedJobsColumns,
    ]);
});

Route::prefix('terminal')->name('terminal.')->group(function () {
    Route::get('/', [TerminalController::class, 'index'])->name('index');
    Route::post('execute', [TerminalController::class, 'execute'])->name('execute');
});

Route::middleware(["auth"])->group(function () {
    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get("/dashboard", [DashboardController::class, "index"])->name(
        "dashboard",
    );
    Route::get("/dashboard/storage-stats", [DashboardController::class, "storageStats"])->name(
        "dashboard.storage-stats",
    );

    // ── Albums ────────────────────────────────────────────────────────────────
    // Static / extra album routes MUST be declared before Route::resource() so
    // Laravel matches them before the parameterised {album} show/edit routes.

    // System (smart) album viewer — e.g. /albums/rajkot/all/recent
    Route::get("/albums/{location}/all/{type}", [
        AlbumController::class,
        "showSystemAlbum",
    ])->name("albums.all");

    // ZIP import
    Route::post("/albums/import", [AlbumController::class, "import"])->name(
        "albums.import",
    );

    // Pin / unpin album (per user)
    Route::post("/albums/{album}/pin-toggle", [
        AlbumController::class,
        "togglePin",
    ])->name("albums.pin-toggle");

    // Per-album upload page & handler using full nested path
    Route::get("/albums/{path}/upload", [
        AlbumController::class,
        "uploadPage",
    ])->where("path", ".*")->name("albums.upload");
    Route::post("/albums/{path}/upload", [
        AlbumController::class,
        "uploadStore",
    ])->where("path", ".*")->name("albums.upload.store");

    // Standard resource routes (index, create, store, edit, update, destroy)
    // MUST come before the generic {path} route so that /albums/create isn't caught as a path
    Route::resource("albums", AlbumController::class)->except(["show"]);

    // Generic nested album show route — matches /albums/slug or /albums/parent/child
    Route::get("albums/{path}", [AlbumController::class, "show"])->where("path", ".*")->name("albums.show");

    // ── Media ─────────────────────────────────────────────────────────────────
    // Extra media routes must come before the resource so that
    // /media/bulk-delete and /media/bulk-download are not swallowed by
    // the parameterised DELETE /media/{media} route.
    Route::post("/media/bulk-delete", [
        MediaController::class,
        "bulkDelete",
    ])->name("media.bulk-delete");
    Route::post("/media/bulk-download", [
        MediaController::class,
        "bulkDownload",
    ])->name("media.bulk-download");
    Route::get("/media/{media}/raw", [MediaController::class, "raw"])->name(
        "media.raw",
    );

    // Resource (only store + destroy — list/show handled inside album pages)
    Route::resource("media", MediaController::class)
        ->parameters(["media" => "media"])
        ->only([
            "store",
            "destroy",
        ]);

    // ── Recycle Bin (admin only) ──────────────────────────────────────────────
    Route::middleware("admin")
        ->prefix("recycle-bin")
        ->name("recycle-bin.")
        ->group(function () {
            Route::get("/", [RecycleBinController::class, "index"])->name(
                "index",
            );
            Route::post("/media/{id}/restore", [
                RecycleBinController::class,
                "restoreMedia",
            ])->name("restore-media");
            Route::delete("/media/{id}", [
                RecycleBinController::class,
                "forceDeleteMedia",
            ])->name("force-delete-media");
            Route::post("/albums/{id}/restore", [
                RecycleBinController::class,
                "restoreAlbum",
            ])->name("restore-album");
            Route::delete("/albums/{id}", [
                RecycleBinController::class,
                "forceDeleteAlbum",
            ])->name("force-delete-album");
        });

    // ── Users ─────────────────────────────────────────────────────────────────
    Route::resource("users", \App\Http\Controllers\UserController::class);

    // ── Profile ───────────────────────────────────────────────────────────────
    Route::get("/profile", [ProfileController::class, "edit"])->name(
        "profile.edit",
    );
    Route::patch("/profile", [ProfileController::class, "update"])->name(
        "profile.update",
    );
    Route::delete("/profile", [ProfileController::class, "destroy"])->name(
        "profile.destroy",
    );
});

require __DIR__ . "/auth.php";
