<?php

use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
 * Authentication (Keycloak / OIDC)
 */
Route::get('/login', [KeycloakController::class, 'redirect'])->name('login');
Route::get('/auth/callback', [KeycloakController::class, 'callback'])->name('auth.callback');
Route::post('/logout', [KeycloakController::class, 'logout'])->name('logout');

/*
 * Public gallery — open to everyone. Images stream through the controller.
 */
Route::get('/gal/area/{area}/ap/{ap}/pub', [GalleryController::class, 'showPublic'])
    ->whereNumber(['area', 'ap'])
    ->name('gallery.public');

Route::get('/gal/area/{area}/ap/{ap}/pub/image/{filename}', [GalleryController::class, 'publicImage'])
    ->whereNumber(['area', 'ap'])
    ->name('gallery.public.image');

Route::get('/gal/area/{area}/ap/{ap}/pub/thumb/{filename}', [GalleryController::class, 'publicThumb'])
    ->whereNumber(['area', 'ap'])
    ->name('gallery.public.thumb');

/*
 * Private gallery ("Dokumentace") — authenticated users only.
 */
Route::middleware('auth')->group(function () {
    Route::get('/gal/area/{area}/ap/{ap}/priv', [GalleryController::class, 'showPrivate'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private');

    Route::get('/gal/area/{area}/ap/{ap}/priv/image/{filename}', [GalleryController::class, 'privateImage'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private.image');

    Route::get('/gal/area/{area}/ap/{ap}/priv/thumb/{filename}', [GalleryController::class, 'privateThumb'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private.thumb');
});

/*
 * Write actions (upload / soft-delete) — authenticated SO,VV,etc. role only.
 */
Route::middleware(['auth', 'can:manage-gallery'])->group(function () {
    Route::post('/gal/area/{area}/ap/{ap}/{visibility}/upload', [GalleryController::class, 'uploadChunk'])
        ->whereIn('visibility', ['pub', 'priv'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.upload');

    Route::delete('/gal/area/{area}/ap/{ap}/{visibility}/image/{filename}', [GalleryController::class, 'destroy'])
        ->whereIn('visibility', ['pub', 'priv'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.destroy');
});
