<?php

use App\Http\Controllers\GalleryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('gallery.index');
});

Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery.index');
Route::post('/gallery/upload', [GalleryController::class, 'store'])->name('gallery.upload');
Route::patch('/gallery/media/{media}', [GalleryController::class, 'updateMedia'])->name('gallery.media.update');
Route::delete('/gallery/media/{media}', [GalleryController::class, 'destroy'])->name('gallery.destroy');

Route::post('/gallery/folders', [GalleryController::class, 'storeFolder'])->name('gallery.folders.store');
Route::patch('/gallery/folders/{folder}', [GalleryController::class, 'updateFolder'])->name('gallery.folders.update');
Route::delete('/gallery/folders/{folder}', [GalleryController::class, 'destroyFolder'])->name('gallery.folders.destroy');

