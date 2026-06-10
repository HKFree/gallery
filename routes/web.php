<?php

use App\Http\Controllers\Auth\KeycloakController;
use App\Http\Controllers\GalleryController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivateImageController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');

/*
 * Authentication (Keycloak / OIDC)
 */
Route::get('/login', [KeycloakController::class, 'redirect'])->name('login');
Route::get('/auth/callback', [KeycloakController::class, 'callback'])->name('auth.callback');
Route::post('/logout', [KeycloakController::class, 'logout'])->name('logout');

/*
 * Public gallery — open to everyone.
 */
Route::get('/gal/pub/area/{area}/ap/{ap}', [GalleryController::class, 'showPublic'])
    ->whereNumber(['area', 'ap'])
    ->name('gallery.public');

/*
 * Private gallery ("Dokumentace") — authenticated users only.
 */
Route::middleware('auth')->group(function () {
    Route::get('/gal/priv/area/{area}/ap/{ap}', [GalleryController::class, 'showPrivate'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private');

    Route::get('/gal/priv/area/{area}/ap/{ap}/image/{filename}', [PrivateImageController::class, 'show'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private.image');

    Route::get('/gal/priv/area/{area}/ap/{ap}/thumb/{filename}', [PrivateImageController::class, 'thumb'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.private.thumb');
});

/*
 * Write actions (upload / soft-delete) — authenticated SO role only.
 */
Route::middleware(['auth', 'can:manage-gallery'])->group(function () {
    Route::post('/gal/{visibility}/area/{area}/ap/{ap}/upload', [GalleryController::class, 'upload'])
        ->whereIn('visibility', ['pub', 'priv'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.upload');

    Route::delete('/gal/{visibility}/area/{area}/ap/{ap}/image/{filename}', [GalleryController::class, 'destroy'])
        ->whereIn('visibility', ['pub', 'priv'])
        ->whereNumber(['area', 'ap'])
        ->name('gallery.destroy');
});
