<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasApiTokens;

    // How long the pairing window stays open after the device registers.
    public const PAIRING_TTL_MINUTES = 60;

    protected $fillable = [
        'mac_address',
        'name',
        'pairing_code',
        'paired_at',
        'last_synced_at',
        'alarm_user_id',
        'photo_user_id',
        'program_id',
    ];

    protected $casts = [
        'paired_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    // A user (alarm or photo manager) is linked to this device.
    public function isOwned(): bool
    {
        return $this->alarm_user_id !== null || $this->photo_user_id !== null;
    }

    // Within the 1-hour window AND not yet claimed by anyone.
    public function isClaimable(): bool
    {
        return ! $this->isOwned()
            && $this->paired_at !== null
            && $this->paired_at->gt(now()->subMinutes(self::PAIRING_TTL_MINUTES));
    }

    // Unowned and the pairing window has elapsed (eligible for pruning).
    public function isPairingExpired(): bool
    {
        return ! $this->isOwned()
            && $this->paired_at !== null
            && $this->paired_at->lte(now()->subMinutes(self::PAIRING_TTL_MINUTES));
    }

    // Unowned devices whose pairing window has elapsed.
    public function scopeStalePairing(Builder $query): Builder
    {
        return $query->whereNull('alarm_user_id')
            ->whereNull('photo_user_id')
            ->whereNotNull('paired_at')
            ->where('paired_at', '<=', now()->subMinutes(self::PAIRING_TTL_MINUTES));
    }

    public function alarmUser()
    {
        return $this->belongsTo(User::class, 'alarm_user_id');
    }

    public function photoUser()
    {
        return $this->belongsTo(User::class, 'photo_user_id');
    }

    public function program()
    {
        return $this->belongsTo(Program::class);
    }

    public function alarms()
    {
        return $this->hasMany(Alarm::class);
    }

    public function messages()
    {
        return $this->hasMany(Message::class);
    }

    // Display label: name if set, otherwise MAC address
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->mac_address;
    }
}
