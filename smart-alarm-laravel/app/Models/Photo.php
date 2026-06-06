<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Photo extends Model
{
    protected $fillable = [
        'user_id',
        'image_path',
        'caption',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Non-null title used by Filament RelationManager select (caption may be null)
    public function getTitleAttribute(): string
    {
        return $this->caption ?? "Photo #{$this->id}";
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'photo_program')
            ->withPivot(['scheduled_for', 'is_shown'])
            ->withTimestamps();
    }
}
