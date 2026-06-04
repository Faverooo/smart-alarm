<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Admin — can see and manage everything
        $admin = User::factory()->create([
            'name'     => 'Admin',
            'email'    => 'admin@smartalarm.local',
            'password' => bcrypt('password'),
            'is_admin' => true,
        ]);

        // Alarm manager — will be assigned as alarm_user on the test device
        $alarmUser = User::factory()->create([
            'name'     => 'Marco (Alarm)',
            'email'    => 'marco@smartalarm.local',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ]);

        // Photo & message manager — will be assigned as photo_user on the test device
        $photoUser = User::factory()->create([
            'name'     => 'Angelica (Photos)',
            'email'    => 'angelica@smartalarm.local',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ]);

        // Placeholder device (MAC will be replaced with the real ESP32 MAC when it arrives)
        \App\Models\Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:FF',
            'name'          => 'Bedroom Alarm',
            'pairing_code'  => null,
            'alarm_user_id' => $alarmUser->id,
            'photo_user_id' => $photoUser->id,
        ]);
    }
}
