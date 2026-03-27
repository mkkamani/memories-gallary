<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AlbumController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\RecycleBinController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get("/", function () {
    return Inertia::render("Welcome", [
        "canLogin" => Route::has("login"),
        "canRegister" => Route::has("register"),
    ]);
});

Route::get('/phpinfo', function () {
    return response()->make(phpinfo());
});

Route::middleware(["auth"])->group(function () {
    // ── Dashboard ─────────────────────────────────────────────────────────────
    Route::get("/dashboard", [DashboardController::class, "index"])->name(
        "dashboard",
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
