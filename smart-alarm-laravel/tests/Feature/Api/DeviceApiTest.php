<?php

namespace Tests\Feature\Api;

use App\Models\Alarm;
use App\Models\Device;
use App\Models\Message;
use App\Models\Photo;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pair_init_registers_a_new_device(): void
    {
        $res = $this->postJson('/api/v1/pair/init', ['mac_address' => 'AA:BB:CC:DD:EE:01']);

        $res->assertStatus(201)
            ->assertJsonStructure(['device_token', 'pairing_code', 'name', 'expires_in']);

        $this->assertDatabaseHas('devices', ['mac_address' => 'AA:BB:CC:DD:EE:01']);
        $device = Device::firstWhere('mac_address', 'AA:BB:CC:DD:EE:01');
        $this->assertNotNull($device->paired_at);
        $this->assertTrue($device->isClaimable());
    }

    public function test_pair_init_is_rejected_once_a_user_is_linked(): void
    {
        $user = User::factory()->create();
        Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:02',
            'photo_user_id' => $user->id,
            'paired_at'     => now(),
        ]);

        $this->postJson('/api/v1/pair/init', ['mac_address' => 'AA:BB:CC:DD:EE:02'])
            ->assertStatus(409);
    }

    public function test_sync_returns_only_active_alarms_and_unread_due_messages(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:03', 'paired_at' => now()]);
        Alarm::create(['device_id' => $device->id, 'time' => '07:00', 'days_of_week' => ['Monday'], 'is_active' => true]);
        Alarm::create(['device_id' => $device->id, 'time' => '09:00', 'is_active' => false]); // inactive → excluded
        Message::create(['device_id' => $device->id, 'text_content' => 'Hi', 'scheduled_at' => now(), 'is_read' => false]);
        Message::create(['device_id' => $device->id, 'text_content' => 'Old', 'scheduled_at' => now()->subDay(), 'is_read' => true]); // read → excluded

        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonCount(1, 'alarms')
            ->assertJsonPath('alarms.0.time', '07:00')
            ->assertJsonCount(1, 'messages')
            ->assertJsonPath('messages.0.text', 'Hi');
    }

    public function test_sync_daily_program_returns_only_todays_photo(): void
    {
        $user = User::factory()->create();
        $program = Program::create(['user_id' => $user->id, 'name' => 'P', 'type' => 'daily']);
        $today = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/today.jpg']);
        $other = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/other.jpg']);
        $program->photos()->attach($today->id, ['scheduled_for' => now()->toDateString()]);
        $program->photos()->attach($other->id, ['scheduled_for' => now()->addDays(3)->toDateString()]);

        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:04', 'paired_at' => now(), 'program_id' => $program->id]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonPath('program.type', 'daily')
            ->assertJsonCount(1, 'program.photos')
            ->assertJsonPath('program.photos.0.id', $today->id);
    }

    public function test_sync_returns_304_when_nothing_changed(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:05', 'paired_at' => now()]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $first = $this->getJson('/api/v1/sync')->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->getJson('/api/v1/sync', ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_alarm_dismissed_deactivates_one_time_alarm_only_for_own_device(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:06', 'paired_at' => now()]);
        $oneTime = Alarm::create(['device_id' => $device->id, 'time' => '07:00', 'scheduled_date' => now()->toDateString(), 'is_active' => true]);

        $other = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:07', 'paired_at' => now()]);
        $foreign = Alarm::create(['device_id' => $other->id, 'time' => '08:00', 'scheduled_date' => now()->toDateString(), 'is_active' => true]);

        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->postJson("/api/v1/alarms/{$oneTime->id}/dismissed")->assertOk();
        $this->assertFalse($oneTime->fresh()->is_active);

        // Cannot touch another device's alarm
        $this->postJson("/api/v1/alarms/{$foreign->id}/dismissed")->assertStatus(403);
        $this->assertTrue($foreign->fresh()->is_active);
    }

    public function test_message_read_marks_read(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:08', 'paired_at' => now()]);
        $msg = Message::create(['device_id' => $device->id, 'text_content' => 'Hi', 'scheduled_at' => now(), 'is_read' => false]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->postJson("/api/v1/messages/{$msg->id}/read")->assertOk();
        $this->assertTrue($msg->fresh()->is_read);
    }

    public function test_sync_requires_authentication(): void
    {
        $this->getJson('/api/v1/sync')->assertStatus(401);
    }

    public function test_prune_deletes_expired_unpaired_but_keeps_owned_and_fresh(): void
    {
        $user = User::factory()->create();

        $expired = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:09', 'paired_at' => now()->subHours(2)]);
        $fresh = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:10', 'paired_at' => now()->subMinutes(5)]);
        $owned = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:11', 'paired_at' => now()->subHours(2), 'photo_user_id' => $user->id]);

        $this->artisan('devices:prune-unpaired')->assertSuccessful();

        $this->assertDatabaseMissing('devices', ['id' => $expired->id]);
        $this->assertDatabaseHas('devices', ['id' => $fresh->id]);
        $this->assertDatabaseHas('devices', ['id' => $owned->id]);
    }
}
