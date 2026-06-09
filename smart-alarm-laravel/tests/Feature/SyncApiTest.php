<?php

namespace Tests\Feature;

use App\Models\Alarm;
use App\Models\Device;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncApiTest extends TestCase
{
    use RefreshDatabase;

    private function device(): Device
    {
        return Device::create(['mac_address' => 'AA:BB:CC:DD:EE:FF']);
    }

    private function sync(Device $device, array $headers = [])
    {
        $token = $device->createToken('device', ['device'])->plainTextToken;

        return $this->withHeaders(['Authorization' => "Bearer {$token}"] + $headers)
            ->getJson('/api/v1/sync');
    }

    public function test_sync_returns_payload_with_etag(): void
    {
        $response = $this->sync($this->device());

        $response->assertOk()
            ->assertJsonStructure(['revision', 'alarms', 'messages', 'settings'])
            ->assertHeader('ETag');
    }

    public function test_sync_returns_304_for_matching_etag(): void
    {
        $device = $this->device();
        $revision = $this->sync($device)->json('revision');

        $this->sync($device, ['If-None-Match' => '"'.$revision.'"'])
            ->assertStatus(304);
    }

    public function test_sync_returns_304_for_weak_etag_from_proxy(): void
    {
        // Cloudflare re-compresses responses and weakens the ETag to W/"...";
        // the device echoes that back verbatim, and it must still match.
        $device = $this->device();
        $revision = $this->sync($device)->json('revision');

        $this->sync($device, ['If-None-Match' => 'W/"'.$revision.'"'])
            ->assertStatus(304);
    }

    public function test_sync_caps_alarms_and_messages_to_firmware_limits(): void
    {
        $device = $this->device();

        foreach (range(0, 19) as $i) {
            Alarm::create([
                'device_id'    => $device->id,
                'time'         => sprintf('%02d:00', $i),
                'days_of_week' => ['Monday'],
                'is_active'    => true,
            ]);
        }

        foreach (range(1, 12) as $i) {
            Message::create([
                'device_id'    => $device->id,
                'text_content' => "Message {$i}",
                'scheduled_at' => now()->subMinutes($i),
                'is_read'      => false,
            ]);
        }

        $response = $this->sync($device)->assertOk();

        // Mirrors firmware/src/config.h MAX_ALARMS / MAX_MESSAGES.
        $this->assertCount(16, $response->json('alarms'));
        $this->assertCount(8, $response->json('messages'));
    }
}
