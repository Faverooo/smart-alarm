<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Photo extends Model
{
    // Private disk (storage/app/private) — NOT web-served. Photos are personal,
    // so they're never on the public symlink; access goes through gated routes:
    // the panel via the session-cookie route (photos.serve), the ESP via the
    // token-gated /api/v1/photos/{id}/render endpoint.
    public const DISK = 'local';

    protected $fillable = [
        'user_id',
        'image_path',
        'caption',
    ];

    // Keep the disk in lock-step with the records: never leave a file with no row.
    protected static function booted(): void
    {
        // Deleting a photo deletes its file.
        static::deleted(function (Photo $photo) {
            if ($photo->image_path) {
                Storage::disk(self::DISK)->delete($photo->image_path);
            }
        });

        // Replacing a photo's file (new upload → new path) deletes the old file.
        static::updated(function (Photo $photo) {
            if ($photo->wasChanged('image_path')) {
                $old = $photo->getOriginal('image_path');
                if ($old && $old !== $photo->image_path) {
                    Storage::disk(self::DISK)->delete($old);
                }
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Non-null title used by Filament RelationManager select (caption may be null)
    public function getTitleAttribute(): string
    {
        return $this->caption ?? "Photo #{$this->id}";
    }

    // Browser URL for the panel: a session-gated streaming route (NOT a public
    // file URL). Used by every Filament preview/lightbox/calendar cell.
    public function getUrlAttribute(): ?string
    {
        return $this->image_path
            ? route('photos.serve', ['path' => $this->image_path])
            : null;
    }

    public function programs()
    {
        return $this->belongsToMany(Program::class, 'photo_program')
            ->withPivot(['scheduled_for', 'is_shown'])
            ->withTimestamps();
    }
}
