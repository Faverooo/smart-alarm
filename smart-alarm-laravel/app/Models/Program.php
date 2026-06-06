<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    // Maximum number of photos the ESP32 can hold for a rotation/daily program
    public const MAX_PHOTOS = 10;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'interval_minutes',
    ];

    protected $casts = [
        'interval_minutes' => 'integer',
    ];

    // How many photos this program type allows
    public function photoLimit(): int
    {
        return $this->type === 'fixed' ? 1 : self::MAX_PHOTOS;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function photos()
    {
        return $this->belongsToMany(Photo::class, 'photo_program')
            ->withPivot(['scheduled_for', 'is_shown'])
            ->withTimestamps();
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
