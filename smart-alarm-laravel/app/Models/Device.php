<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasApiTokens;

    // How long the pairing window stays open after the device registers.
    public const PAIRING_TTL_MINUTES = 60;

    /**
     * Keep `pairing_code` in lock-step with the open ownership slots:
     *  - both owners set  → null it (a fully set-up device has no join secret)
     *  - exactly one set  → ensure a code exists so the OTHER role can still join
     *  - unowned          → leave whatever pair/init / forget put there
     *
     * Runs on every *non-quiet* save, so it covers the panel claim flow and the
     * become/remove-alarm-manager actions. `/sync` and `/device/status` use
     * saveQuietly() (no events fired) and never touch ownership, so they're safe.
     * Reopening a slot mints a FRESH code — so a removed manager's old code dies.
     */
    protected static function booted(): void
    {
        static::saving(function (Device $device) {
            $bothFilled = $device->alarm_user_id !== null && $device->photo_user_id !== null;

            if ($bothFilled) {
                $device->pairing_code = null;
            } elseif ($device->isOwned()) {
                $device->pairing_code ??= strtoupper(Str::random(6));
            }
        });
    }

    // Considered "online" if it phoned home within this many minutes.
    public const ONLINE_WINDOW_MINUTES = 5;

    protected $fillable = [
        'mac_address',
        'name',
        'pairing_code',
        'paired_at',
        'last_synced_at',
        'firmware_version',
        'last_ip',
        'rssi',
        'free_heap',
        'last_status_at',
        'brightness',
        'night_brightness',
        'volume',
        'time_format',
        'night_start',
        'night_end',
        'alarm_user_id',
        'photo_user_id',
        'program_id',
    ];

    protected $casts = [
        'paired_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_status_at' => 'datetime',
        'rssi' => 'integer',
        'free_heap' => 'integer',
        'brightness' => 'integer',
        'night_brightness' => 'integer',
        'volume' => 'integer',
    ];

    // The display/sound config the device applies (pushed through /sync).
    public function deviceSettings(): array
    {
        return [
            'brightness'       => (int) $this->brightness,
            'night_brightness' => (int) $this->night_brightness,
            'night_start'      => $this->night_start,
            'night_end'        => $this->night_end,
            'volume'           => (int) $this->volume,
            'time_format'      => (string) $this->time_format,
        ];
    }

    // Recent heartbeat (status or sync) → the panel shows a green "online" dot.
    public function isOnline(): bool
    {
        $last = $this->last_status_at ?? $this->last_synced_at;

        return $last !== null && $last->gt(now()->subMinutes(self::ONLINE_WINDOW_MINUTES));
    }

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
