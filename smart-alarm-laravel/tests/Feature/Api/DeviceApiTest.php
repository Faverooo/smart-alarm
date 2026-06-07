<?php

namespace Tests\Feature\Api;

use App\Models\Alarm;
use App\Models\Device;
use App\Models\Message;
use App\Models\Photo;
use App\Models\Program;
use App\Filament\Resources\DeviceResource\Pages\EditDevice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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

    public function test_unauthenticated_api_returns_401_not_a_redirect(): void
    {
        // A bare GET (no Accept: application/json, as the ESP's binary image
        // fetch makes) must still be a clean 401 — never a 302 to the HTML login.
        $this->get('/api/v1/photos/1/render')->assertStatus(401);
    }

    public function test_pair_init_is_rate_limited(): void
    {
        // The 'pair' limiter allows 20/min per IP; the 21st call is throttled.
        for ($i = 0; $i < 20; $i++) {
            $this->postJson('/api/v1/pair/init', ['mac_address' => sprintf('AA:BB:CC:DD:EE:%02X', $i)])
                ->assertStatus(201);
        }

        $this->postJson('/api/v1/pair/init', ['mac_address' => 'AA:BB:CC:DD:EE:FF'])
            ->assertStatus(429);
    }

    public function test_pair_status_reports_unclaimed_then_claimed(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:20', 'paired_at' => now(), 'pairing_code' => 'ABC123']);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/pair/status')
            ->assertOk()
            ->assertJsonPath('paired', false)
            ->assertJsonPath('pairing_code', 'ABC123')
            ->assertJsonStructure(['paired', 'alarm_user', 'photo_user', 'name', 'pairing_code', 'expires_in']);

        $user = User::factory()->create();
        $device->update(['photo_user_id' => $user->id]);

        // The Sanctum guard caches the resolved device within a single test
        // method; in production each request is fresh, so flush it here.
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/pair/status')
            ->assertOk()
            ->assertJsonPath('paired', true)
            ->assertJsonPath('photo_user', true)
            ->assertJsonPath('pairing_code', null);
    }

    public function test_time_returns_local_wall_clock_and_offset(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:21', 'paired_at' => now()]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/time')
            ->assertOk()
            ->assertJsonStructure(['server_time', 'timezone', 'utc_offset', 'is_dst', 'unix'])
            ->assertJsonPath('timezone', config('app.timezone'));
    }

    public function test_device_status_records_telemetry(): void
    {
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:22', 'paired_at' => now()]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->postJson('/api/v1/device/status', [
            'firmware_version' => '1.4.0',
            'free_heap'        => 123456,
            'rssi'             => -55,
            'ip'               => '192.168.1.42',
        ])->assertOk()->assertJsonPath('ok', true);

        $device->refresh();
        $this->assertSame('1.4.0', $device->firmware_version);
        $this->assertSame(123456, $device->free_heap);
        $this->assertSame(-55, $device->rssi);
        $this->assertSame('192.168.1.42', $device->last_ip);
        $this->assertNotNull($device->last_status_at);
        $this->assertTrue($device->isOnline());
    }

    public function test_device_forget_releases_owners_and_reissues_token(): void
    {
        $user = User::factory()->create();
        $device = Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:23',
            'paired_at'     => now(),
            'photo_user_id' => $user->id,
            'alarm_user_id' => $user->id,
        ]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->postJson('/api/v1/device/forget')
            ->assertOk()
            ->assertJsonStructure(['device_token', 'pairing_code', 'expires_in']);

        $device->refresh();
        $this->assertNull($device->photo_user_id);
        $this->assertNull($device->alarm_user_id);
        $this->assertFalse($device->isOwned());
        $this->assertTrue($device->isClaimable());
    }

    public function test_firmware_latest_reports_update_when_newer(): void
    {
        config(['services.firmware' => ['version' => '2.0.0', 'url' => 'https://x/fw.bin', 'mandatory' => true]]);
        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:24', 'paired_at' => now()]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/firmware/latest?current=1.0.0')
            ->assertOk()
            ->assertJsonPath('update_available', true)
            ->assertJsonPath('mandatory', true)
            ->assertJsonPath('url', 'https://x/fw.bin');

        $this->getJson('/api/v1/firmware/latest?current=2.0.0')
            ->assertOk()
            ->assertJsonPath('update_available', false)
            ->assertJsonPath('url', null);
    }

    public function test_render_returns_resized_jpeg_for_program_photo(): void
    {
        Storage::fake('local');

        $user = User::factory()->create();
        $program = Program::create(['user_id' => $user->id, 'name' => 'P', 'type' => 'fixed']);
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);
        $program->photos()->attach($photo->id);

        // A real (larger-than-display) JPEG on the fake disk.
        $gd = imagecreatetruecolor(640, 480);
        ob_start();
        imagejpeg($gd);
        $jpeg = (string) ob_get_clean();
        imagedestroy($gd);
        Storage::disk('local')->put('photos/a.jpg', $jpeg);

        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:25', 'paired_at' => now(), 'program_id' => $program->id]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $res = $this->get("/api/v1/photos/{$photo->id}/render");
        $res->assertOk();
        $this->assertSame('image/jpeg', $res->headers->get('Content-Type'));
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag);

        // Same ETag → 304, no re-download.
        $this->get("/api/v1/photos/{$photo->id}/render", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_render_rejects_photo_not_in_device_program(): void
    {
        $user = User::factory()->create();
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);

        $device = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:26', 'paired_at' => now()]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->get("/api/v1/photos/{$photo->id}/render")->assertStatus(403);
    }

    public function test_sync_includes_settings(): void
    {
        $device = Device::create([
            'mac_address' => 'AA:BB:CC:DD:EE:27',
            'paired_at'   => now(),
            'brightness'  => 55,
            'time_format' => '12',
        ]);
        $this->withToken($device->createToken('device', ['device'])->plainTextToken);

        $this->getJson('/api/v1/sync')
            ->assertOk()
            ->assertJsonPath('settings.brightness', 55)
            ->assertJsonPath('settings.time_format', '12')
            ->assertJsonStructure(['settings' => ['brightness', 'night_brightness', 'night_start', 'night_end', 'volume', 'time_format']]);
    }

    public function test_pairing_code_clears_once_both_roles_are_filled(): void
    {
        $photo = User::factory()->create();
        $alarm = User::factory()->create();

        // One owner → the invite code is preserved/ensured.
        $device = Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:30',
            'paired_at'     => now(),
            'pairing_code'  => 'ABC123',
            'photo_user_id' => $photo->id,
        ]);
        $this->assertSame('ABC123', $device->fresh()->pairing_code);

        // Second role joins → no open slot left → code is dropped.
        $device->update(['alarm_user_id' => $alarm->id]);
        $this->assertNull($device->fresh()->pairing_code);
    }

    public function test_reopening_a_slot_mints_a_fresh_code(): void
    {
        $user = User::factory()->create();
        $device = Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:31',
            'paired_at'     => now(),
            'photo_user_id' => $user->id,
            'alarm_user_id' => $user->id,
        ]);
        // Fully owned → no code.
        $this->assertNull($device->fresh()->pairing_code);

        // Free the alarm slot → a brand-new code appears (old one is gone for good).
        $device->update(['alarm_user_id' => null]);
        $device->refresh();
        $this->assertNotNull($device->pairing_code);
        $this->assertSame(6, strlen($device->pairing_code));
    }

    public function test_unowned_device_keeps_its_pairing_code(): void
    {
        $device = Device::create([
            'mac_address'  => 'AA:BB:CC:DD:EE:32',
            'paired_at'    => now(),
            'pairing_code' => 'XYZ789',
        ]);

        // No owners yet → the window code from pair/init must survive.
        $this->assertSame('XYZ789', $device->fresh()->pairing_code);
    }

    public function test_regenerate_code_action_only_shows_while_alarm_slot_open(): void
    {
        $photo = User::factory()->create(['is_admin' => false]);
        $alarm = User::factory()->create(['is_admin' => false]);

        // Alarm slot open → the photo manager can rotate the invite code.
        $open = Device::create(['mac_address' => 'AA:BB:CC:DD:EE:33', 'paired_at' => now(), 'photo_user_id' => $photo->id]);

        $this->actingAs($photo);
        Livewire::test(EditDevice::class, ['record' => $open->getRouteKey()])
            ->assertActionVisible('regenerateCode')
            ->callAction('regenerateCode');

        $open->refresh();
        $this->assertNotNull($open->pairing_code);          // persisted, not nulled by the hook
        $this->assertSame(6, strlen($open->pairing_code));

        // Both slots filled → no open slot → action is hidden (code stays null).
        $full = Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:34',
            'paired_at'     => now(),
            'photo_user_id' => $photo->id,
            'alarm_user_id' => $alarm->id,
        ]);
        $this->assertNull($full->fresh()->pairing_code);

        Livewire::test(EditDevice::class, ['record' => $full->getRouteKey()])
            ->assertActionDoesNotExist('regenerateCode');
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
