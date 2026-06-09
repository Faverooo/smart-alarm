<?php

namespace Tests\Feature;

use App\Filament\Resources\AlarmResource\Pages\ListAlarms;
use App\Models\Alarm;
use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AlarmListTest extends TestCase
{
    use RefreshDatabase;

    private function alarmManagerWithDevice(): array
    {
        $user = User::factory()->create(['is_admin' => false]);
        $device = Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:02',
            'alarm_user_id' => $user->id,
        ]);

        return [$user, $device];
    }

    // Regression: days_of_week is an array column; Filament feeds the formatter
    // one item (string) at a time, and the old array_map($state) crashed with
    // "Argument #2 ($array) must be of type array, string given".
    public function test_alarm_list_renders_with_recurring_days(): void
    {
        [$user, $device] = $this->alarmManagerWithDevice();

        Alarm::create([
            'device_id'    => $device->id,
            'time'         => '07:30',
            'days_of_week' => ['Monday', 'Wednesday', 'Friday'],
            'is_active'    => true,
        ]);

        $this->actingAs($user);

        Livewire::test(ListAlarms::class)
            ->assertOk()
            ->assertSee('Mon')
            ->assertSee('Wed')
            ->assertSee('Fri');
    }

    public function test_alarm_list_renders_with_one_time_alarm(): void
    {
        [$user, $device] = $this->alarmManagerWithDevice();

        Alarm::create([
            'device_id'      => $device->id,
            'time'           => '06:00',
            'scheduled_date' => now()->addDay()->toDateString(),
            'days_of_week'   => null,
            'is_active'      => true,
        ]);

        $this->actingAs($user);

        Livewire::test(ListAlarms::class)->assertOk();
    }
}
