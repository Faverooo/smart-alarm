<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // ESP32 over-the-air firmware. The device polls GET /api/v1/firmware/latest
    // with its current version; if `version` here is higher it downloads `url`.
    'firmware' => [
        'version'   => env('FIRMWARE_VERSION'),       // e.g. "1.4.0"; null = no OTA configured
        'url'       => env('FIRMWARE_URL'),           // absolute URL to the .bin
        'md5'       => env('FIRMWARE_MD5'),           // md5sum of the .bin — the device verifies it before flashing
        'mandatory' => env('FIRMWARE_MANDATORY', false),
    ],

    // Target resolution for photos rendered for the device's TFT.
    // CAUTION: the firmware caps photo downloads at 64KB (JpegBuffer::CAP in
    // firmware/src/ApiClient.cpp). 320×240 q80 renders stay well under it
    // (~15-30KB); raising the resolution much beyond that can push renders
    // over the cap, making every photo download fail on the device.
    'device_display' => [
        'width'  => env('DEVICE_DISPLAY_WIDTH', 320),
        'height' => env('DEVICE_DISPLAY_HEIGHT', 240),
    ],

];
