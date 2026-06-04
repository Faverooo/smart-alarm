<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Message extends Model
{
    protected $fillable = [
        'device_id',
        'text_content',
        'scheduled_at',
        'is_read',
    ];

    protected $casts = [
        'is_read' => 'boolean',
        'scheduled_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
