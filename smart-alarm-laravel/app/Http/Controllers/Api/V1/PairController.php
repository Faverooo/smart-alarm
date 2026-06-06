<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PairController extends Controller
{
    /**
     * Device self-registration / pairing.
     *
     * Rules (per spec):
     *  - The ESP calls this on first boot (or after a factory reset).
     *  - Pairing is only allowed while the device has NO users linked. Once a
     *    user has claimed it, re-pairing is rejected (409) so nobody can hijack
     *    an in-use device. The owner must "forget" it first to re-pair.
     *  - The pairing window lasts Device::PAIRING_TTL_MINUTES (1h). A scheduled
     *    prune removes unowned devices past it; calling init again refreshes it.
     */
    public function init(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mac_address' => ['required', 'string', 'regex:/^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$/'],
        ]);

        $mac = strtoupper(str_replace('-', ':', $data['mac_address']));
        $device = Device::firstWhere('mac_address', $mac);

        // Already claimed by a user → cannot re-pair.
        if ($device && $device->isOwned()) {
            return response()->json([
                'message' => 'This device is already paired to an account.',
            ], 409);
        }

        // New device, or an unowned one re-registering (reboot / expired window).
        $device ??= new Device(['mac_address' => $mac]);
        $device->pairing_code = strtoupper(Str::random(6));
        $device->paired_at = now();
        $device->save();

        // Issue a fresh device token, revoking any previous one.
        $device->tokens()->delete();
        $token = $device->createToken('device', ['device'])->plainTextToken;

        return response()->json([
            'device_token' => $token,
            'pairing_code' => $device->pairing_code,
            'name'         => $device->display_name,
            'expires_in'   => Device::PAIRING_TTL_MINUTES * 60,
        ], 201);
    }
}
