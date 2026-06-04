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
            $table->string('pairing_code')->nullable(); // 6-digit code shown on device for pairing

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
