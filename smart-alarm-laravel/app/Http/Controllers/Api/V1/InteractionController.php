<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Alarm;
use App\Models\Device;
use App\Models\Message;
use App\Models\Photo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InteractionController extends Controller
{
    // A fired one-time alarm was dismissed → deactivate it so it doesn't ring again.
    public function alarmDismissed(Request $request, Alarm $alarm): JsonResponse
    {
        $this->assertOwns($request, $alarm->device_id);

        // Only one-time alarms auto-deactivate; recurring ones keep their schedule.
        if ($alarm->scheduled_date !== null) {
            $alarm->update(['is_active' => false]);
        }

        return response()->json(['ok' => true]);
    }

    // The user acknowledged a message on the device.
    public function messageRead(Request $request, Message $message): JsonResponse
    {
        $this->assertOwns($request, $message->device_id);

        $message->update(['is_read' => true]);

        return response()->json(['ok' => true]);
    }

    // The device displayed a program photo (used by daily/rotation bookkeeping).
    public function photoShown(Request $request, Photo $photo): JsonResponse
    {
        /** @var Device $device */
        $device = $request->user();

        abort_unless(
            $device->program && $device->program->photos()->whereKey($photo->id)->exists(),
            403,
        );

        $device->program->photos()->updateExistingPivot($photo->id, ['is_shown' => true]);

        return response()->json(['ok' => true]);
    }

    // Make sure the targeted record belongs to the calling device.
    private function assertOwns(Request $request, int $deviceId): void
    {
        abort_unless($request->user()->getKey() === $deviceId, 403);
    }
}
