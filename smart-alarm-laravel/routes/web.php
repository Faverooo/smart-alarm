<?php

use App\Http\Controllers\PhotoFileController;
use Illuminate\Support\Facades\Route;

// The Filament admin panel is served at the site root (see AdminPanelProvider
// ->path('')), so there is no custom "/" route here.

// Session-gated streaming of private photos for the panel (previews, lightbox,
// calendar). `auth` = the web guard the panel logs users into. Path kept off the
// /photos/* namespace so it can't collide with the PhotoResource pages.
Route::get('photo-file', PhotoFileController::class)
    ->middleware('auth')
    ->name('photos.serve');
