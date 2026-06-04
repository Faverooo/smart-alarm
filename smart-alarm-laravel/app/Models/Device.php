<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens; // 1. Import Sanctum
use App\Models\Program;
use App\Models\User;
use App\Models\Alarm;

class Device extends Model
{
    use HasApiTokens, HasFactory;

    // Allow mass assignment for all fields 
    protected $guarded = [];

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
}
