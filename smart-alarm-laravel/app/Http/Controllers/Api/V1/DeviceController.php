<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    /**
     * Heartbeat + telemetry.
     *
     * The ESP posts this on a slow cadence (e.g. every few minutes) so the panel
     * can show "online / last seen" plus diagnostics (firmware, free RAM, WiFi
     * signal, IP). Everything is optional; missing fields are left untouched.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $data = $request->validate([
            'firmware_version' => ['nullable', 'string', 'max:32'],
            'free_heap'        => ['nullable', 'integer', 'min:0'],
            'rssi'             => ['nullable', 'integer', 'between:-120,0'],
            'ip'               => ['nullable', 'ip'],
        ]);

        $device->forceFill(array_filter([
            'firmware_version' => $data['firmware_version'] ?? null,
            'free_heap'        => $data['free_heap'] ?? null,
            'rssi'             => $data['rssi'] ?? null,
            'last_ip'          => $data['ip'] ?? $request->ip(),
        ], fn ($v) => $v !== null) + ['last_status_at' => now()])->saveQuietly();

        return response()->json(['ok' => true]);
    }

    /**
     * Device-initiated factory reset (physical reset button held on boot).
     *
     * Releases both owners, detaches the program, revokes the old token and
     * reopens a fresh pairing window — then returns a brand-new token + code so
     * the device can immediately show the pairing screen again. Authenticated by
     * the device's own token, so only the physical device can trigger it.
     */
    public function forget(Request $request): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        $device->forceFill([
            'alarm_user_id' => null,
            'photo_user_id' => null,
            'program_id'    => null,
            'pairing_code'  => strtoupper(Str::random(6)),
            'paired_at'     => now(),
        ])->save();

        $device->tokens()->delete();
        $token = $device->createToken('device', ['device'])->plainTextToken;

        return response()->json([
            'device_token' => $token,
            'pairing_code' => $device->pairing_code,
            'name'         => $device->display_name,
            'expires_in'   => Device::PAIRING_TTL_MINUTES * 60,
        ]);
    }
}
