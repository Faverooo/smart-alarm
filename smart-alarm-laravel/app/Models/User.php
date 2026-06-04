<?php

namespace App\Models;

use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'is_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    // All registered users can access the panel; visibility is controlled per-resource
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function alarmDevices()
    {
        return $this->hasMany(Device::class, 'alarm_user_id');
    }

    public function photoDevices()
    {
        return $this->hasMany(Device::class, 'photo_user_id');
    }

    public function photos()
    {
        return $this->hasMany(Photo::class);
    }

    public function programs()
    {
        return $this->hasMany(Program::class);
    }

    public function isAlarmManager(): bool
    {
        return $this->is_admin || $this->alarmDevices()->exists();
    }

    public function isPhotoManager(): bool
    {
        return $this->is_admin || $this->photoDevices()->exists();
    }
}
