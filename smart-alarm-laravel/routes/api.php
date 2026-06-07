<?php

use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\FirmwareController;
use App\Http\Controllers\Api\V1\ImageController;
use App\Http\Controllers\Api\V1\InteractionController;
use App\Http\Controllers\Api\V1\PairController;
use App\Http\Controllers\Api\V1\SyncController;
use App\Http\Controllers\Api\V1\TimeController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // Device self-registration / pairing (no auth — issues the device token).
    // Tightly throttled per IP: it's unauthenticated and creates a device row +
    // token each call, so it's the most abusable endpoint.
    Route::post('pair/init', [PairController::class, 'init'])
        ->middleware('throttle:pair');

    // Everything below requires the device's Sanctum token. The throttle is
    // generous (a healthy device does ~1-2 req/min) but bounds a runaway/compromised
    // device — a rotation program's first sync may still burst ~12 image fetches.
    // Named limiters (see AppServiceProvider) key by token/IP, not user id.
    Route::middleware(['auth:sanctum', 'throttle:device'])->group(function () {
        // Pairing-screen poll: has a human claimed this device yet?
        Route::get('pair/status', [PairController::class, 'status']);

        // Main data pull (alarms, program, messages, settings) with ETag/304.
        Route::get('sync', SyncController::class);

        // Lightweight, frequently-polled clock (local wall-clock + UTC offset).
        Route::get('time', TimeController::class);

        // Heartbeat / telemetry and device-initiated factory reset.
        Route::post('device/status', [DeviceController::class, 'status']);
        Route::post('device/forget', [DeviceController::class, 'forget']);

        // Photo resized to the TFT resolution (ETag/304 cached).
        Route::get('photos/{photo}/render', [ImageController::class, 'render']);

        // OTA firmware check.
        Route::get('firmware/latest', [FirmwareController::class, 'latest']);

        // Acknowledgements from the device back to the server.
        Route::post('alarms/{alarm}/dismissed', [InteractionController::class, 'alarmDismissed']);
        Route::post('messages/{message}/read', [InteractionController::class, 'messageRead']);
        Route::post('programs/photos/{photo}/shown', [InteractionController::class, 'photoShown']);
    });
});
