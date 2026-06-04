<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Program extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'type',
        'interval_minutes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'interval_minutes' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function photos()
    {
        return $this->belongsToMany(Photo::class, 'photo_program')
            ->withPivot(['sort_order', 'scheduled_for', 'is_shown'])
            ->withTimestamps()
            ->orderByPivot('sort_order');
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }
}
