<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

class Device extends Model
{
    use HasApiTokens;

    protected $fillable = [
        'mac_address',
        'name',
        'pairing_code',
        'alarm_user_id',
        'photo_user_id',
        'program_id',
    ];

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
