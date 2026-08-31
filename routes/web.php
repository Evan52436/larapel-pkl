<?php

use App\Http\Controllers\GalleryController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route('gallery.index');
});

Route::get('/gallery', [GalleryController::class, 'index'])->name('gallery.index');
Route::post('/gallery/upload', [GalleryController::class, 'store'])->name('gallery.upload');
Route::delete('/gallery/media/{media}', [GalleryController::class, 'destroy'])->name('gallery.destroy');

