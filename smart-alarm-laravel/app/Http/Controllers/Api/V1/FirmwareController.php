<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FirmwareController extends Controller
{
    /**
     * OTA update check.
     *
     * The ESP sends its running version as `?current=1.3.0`. If config holds a
     * higher version we tell it where to download the new .bin. Server-driven via
     * config/services.php (`FIRMWARE_VERSION` / `FIRMWARE_URL`) so rolling out a
     * build needs no code change.
     */
    public function latest(Request $request): JsonResponse
    {
        $current = (string) $request->query('current', '');
        $latest  = config('services.firmware.version');
        $url     = config('services.firmware.url');

        $available = $latest !== null
            && $url !== null
            && ($current === '' || version_compare($latest, $current, '>'));

        return response()->json([
            'version'          => $latest,
            'url'              => $available ? $url : null,
            'update_available' => $available,
            'mandatory'        => $available && (bool) config('services.firmware.mandatory', false),
        ]);
    }
}
