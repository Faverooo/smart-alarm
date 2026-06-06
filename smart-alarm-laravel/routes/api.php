<?php

use App\Http\Controllers\Api\V1\InteractionController;
use App\Http\Controllers\Api\V1\PairController;
use App\Http\Controllers\Api\V1\SyncController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Device self-registration / pairing (no auth — issues the device token).
    Route::post('pair/init', [PairController::class, 'init']);

    // Everything below requires the device's Sanctum token.
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('sync', SyncController::class);

        Route::post('alarms/{alarm}/dismissed', [InteractionController::class, 'alarmDismissed']);
        Route::post('messages/{message}/read', [InteractionController::class, 'messageRead']);
        Route::post('programs/photos/{photo}/shown', [InteractionController::class, 'photoShown']);
    });
});
