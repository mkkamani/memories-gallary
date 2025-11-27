<?php

use App\Http\Controllers\ProfileController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\AlbumController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\RecycleBinController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Welcome', [
        'canLogin' => Route::has('login'),
        'canRegister' => Route::has('register'),
        'laravelVersion' => Application::VERSION,
        'phpVersion' => PHP_VERSION,
    ]);
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::resource('albums', AlbumController::class);
    Route::resource('media', MediaController::class)->only(['store', 'destroy']);
    Route::post('/media/bulk-delete', [MediaController::class, 'bulkDelete'])->name('media.bulk-delete');
    Route::post('/media/bulk-download', [MediaController::class, 'bulkDownload'])->name('media.bulk-download');
    
    Route::get('/recycle-bin', [RecycleBinController::class, 'index'])->name('recycle-bin.index');
    Route::post('/recycle-bin/media/{id}/restore', [RecycleBinController::class, 'restoreMedia'])->name('recycle-bin.restore-media');
    Route::delete('/recycle-bin/media/{id}', [RecycleBinController::class, 'forceDeleteMedia'])->name('recycle-bin.force-delete-media');
    Route::post('/recycle-bin/albums/{id}/restore', [RecycleBinController::class, 'restoreAlbum'])->name('recycle-bin.restore-album');
    Route::delete('/recycle-bin/albums/{id}', [RecycleBinController::class, 'forceDeleteAlbum'])->name('recycle-bin.force-delete-album');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
