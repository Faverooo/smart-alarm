<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Alarm extends Model
{
    protected $fillable = [
        'device_id',
        'scheduled_date',
        'time',
        'days_of_week',
        'is_active',
    ];

    protected $casts = [
        'days_of_week' => 'array',
        'is_active' => 'boolean',
        'scheduled_date' => 'date',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    // True if this is a one-time alarm (has a specific date)
    public function isOneTime(): bool
    {
        return $this->scheduled_date !== null;
    }
}
