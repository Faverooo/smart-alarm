<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TimeController extends Controller
{
    /**
     * Tiny, cheap clock endpoint the ESP polls often to stay accurate.
     *
     * Alarms are stored as Europe/Rome wall-clock (no UTC), so the device must
     * compare against local time. Rather than make the ESP compute DST from a
     * timezone name, we hand it the resolved local wall-clock string plus the
     * current UTC offset — it just trusts these directly.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $now = now();

        return response()->json([
            'server_time' => $now->format('Y-m-d\TH:i:s'), // local wall-clock
            'timezone'    => config('app.timezone'),
            'utc_offset'  => $now->format('P'),             // e.g. "+02:00"
            'is_dst'      => (bool) $now->dst,
            'unix'        => $now->getTimestamp(),          // true UTC epoch
        ]);
    }
}
