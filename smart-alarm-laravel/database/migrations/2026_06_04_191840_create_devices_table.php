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
