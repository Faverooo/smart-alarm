<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('mac_address')->unique();
            $table->string('name')->nullable(); // Human-readable label (e.g. "Bedroom Alarm")
            $table->string('pairing_code')->nullable(); // 6-char code shown on device for pairing

            // When the device (re)registered via the API. The pairing window is open
            // for 1 hour from here; unowned devices past it are pruned. Null once the
            // device has never paired.
            $table->timestamp('paired_at')->nullable();
            // Last time the device hit /api/v1/sync (telemetry / "online" indicator).
            $table->timestamp('last_synced_at')->nullable();

            // ── Telemetry reported by the ESP via POST /api/v1/device/status ──────
            $table->string('firmware_version', 32)->nullable(); // e.g. "1.4.0"
            $table->string('last_ip', 45)->nullable();          // device's local IP
            $table->integer('rssi')->nullable();                // WiFi signal (dBm)
            $table->unsignedInteger('free_heap')->nullable();   // free RAM (bytes)
            $table->timestamp('last_status_at')->nullable();    // last heartbeat

            // ── Display & sound settings pushed to the device through /sync ───────
            $table->unsignedTinyInteger('brightness')->default(80);        // day, 0-100
            $table->unsignedTinyInteger('night_brightness')->default(10);  // night, 0-100
            $table->unsignedTinyInteger('volume')->default(70);            // alarm, 0-100
            $table->string('time_format', 2)->default('24');               // '12' or '24'
            $table->string('night_start', 5)->default('22:00');            // HH:MM, local
            $table->string('night_end', 5)->default('07:00');              // HH:MM, local

            // Optional: link to the user who set the alarm and the user who uploaded the photo
            $table->foreignId('alarm_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('photo_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
