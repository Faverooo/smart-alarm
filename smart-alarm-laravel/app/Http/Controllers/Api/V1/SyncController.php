<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Photo;
use App\Models\Program;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncController extends Controller
{
    // Mirror the firmware's parse caps (firmware/src/config.h MAX_ALARMS /
    // MAX_MESSAGES) so we never ship items the device would drop anyway.
    private const MAX_ALARMS = 16;
    private const MAX_MESSAGES = 8;

    /**
     * Everything the device needs, trimmed to the minimum:
     *  - only ACTIVE alarms, minimal fields
     *  - program photos resolved by type (daily/fixed → 1 photo, rotation → ≤10)
     *  - only UNREAD messages due within the next 24h
     *  - each photo carries a `hash` so the ESP re-downloads only on change
     *  - ETag + 304: if nothing changed since the device's last sync, return an
     *    empty 304 so the ESP keeps its cached payload and downloads nothing.
     */
    public function __invoke(Request $request)
    {
        /** @var Device $device */
        $device = $request->user();

        // Build the stable content first (drives the ETag).
        $content = [
            'device'   => ['name' => $device->display_name],
            'settings' => $device->deviceSettings(),
            'alarms'   => $this->alarms($device),
            'program'  => $this->program($device),
            'messages' => $this->messages($device),
        ];

        $revision = substr(sha1(json_encode($content)), 0, 16);

        $device->forceFill(['last_synced_at' => now()])->saveQuietly();

        // Not modified since the device last fetched → nothing to download.
        if ($this->ifNoneMatchMatches($request, $revision)) {
            return response('', 304)->setEtag($revision);
        }

        // Volatile fields live outside the ETag content.
        $payload = array_merge([
            'server_time' => now()->format('Y-m-d\TH:i:s'),
            'timezone'    => config('app.timezone'),
            'revision'    => $revision,
        ], $content);

        return response()->json($payload)->setEtag($revision);
    }

    private function alarms(Device $device): array
    {
        return $device->alarms()
            ->where('is_active', true)
            ->orderBy('time')
            ->limit(self::MAX_ALARMS)
            ->get(['id', 'time', 'scheduled_date', 'days_of_week', 'is_active'])
            ->map(fn ($a) => [
                'id'             => $a->id,
                'time'           => substr((string) $a->time, 0, 5), // HH:MM, local
                'scheduled_date' => $a->scheduled_date?->format('Y-m-d'),
                'days_of_week'   => $a->days_of_week ?? [],
            ])
            ->all();
    }

    private function program(Device $device): ?array
    {
        $program = $device->program;

        if (! $program) {
            return null;
        }

        $photos = match ($program->type) {
            'fixed'    => $program->photos()->limit(1)->get(),
            'daily'    => collect([$this->todaysPhoto($program)])->filter(),
            'rotation' => $program->photos()->limit(Program::MAX_PHOTOS)->get(),
            default    => collect(),
        };

        return [
            'type'             => $program->type,
            'interval_minutes' => $program->type === 'rotation' ? $program->interval_minutes : null,
            'photos'           => $photos->map(fn (Photo $p) => $this->photo($p))->values()->all(),
        ];
    }

    // For a daily program: the photo pinned to today, else any unpinned one.
    private function todaysPhoto(Program $program): ?Photo
    {
        $today = now()->toDateString();

        return $program->photos()->wherePivot('scheduled_for', $today)->first()
            ?? $program->photos()->wherePivotNull('scheduled_for')->first();
    }

    private function messages(Device $device): array
    {
        return $device->messages()
            ->where('is_read', false)
            ->where('scheduled_at', '<=', now()->addDay())
            ->orderBy('scheduled_at')
            ->limit(self::MAX_MESSAGES)
            ->get(['id', 'text_content', 'scheduled_at'])
            ->map(fn ($m) => [
                'id'           => $m->id,
                'text'         => $m->text_content,
                'scheduled_at' => $m->scheduled_at instanceof Carbon
                    ? $m->scheduled_at->format('Y-m-d\TH:i:s')
                    : (string) $m->scheduled_at,
            ])
            ->all();
    }

    private function photo(Photo $photo): array
    {
        $scheduledFor = $photo->pivot->scheduled_for ?? null;

        return [
            'id'            => $photo->id,
            // Photos are private (not on the public disk), so the device fetches
            // them ONLY through this token-gated render endpoint — a guaranteed
            // exact-size JPEG, ETag-cached. Needs the device's bearer token.
            'render_url'    => url('api/v1/photos/'.$photo->id.'/render'),
            'caption'       => $photo->caption,
            'scheduled_for' => $scheduledFor ? Carbon::parse($scheduledFor)->format('Y-m-d') : null,
            // Changes when the file is replaced → ESP cache key.
            'hash'          => substr(sha1($photo->image_path.'|'.$photo->updated_at), 0, 12),
        ];
    }
}
