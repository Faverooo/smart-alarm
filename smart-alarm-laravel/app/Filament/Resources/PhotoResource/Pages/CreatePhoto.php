<?php

namespace App\Filament\Resources\PhotoResource\Pages;

use App\Filament\Resources\PhotoResource;
use App\Models\Photo;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class CreatePhoto extends CreateRecord
{
    protected static string $resource = PhotoResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Always ensure user_id is set (admin may leave it populated via the select)
        if (! auth()->user()->is_admin || empty($data['user_id'])) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->resizeForEsp32($this->record);
    }

    // Resize to 320x240 for the 3.2" TFT display. Idempotent: a file that is
    // already exactly 320x240 is left untouched, so caption-only edits don't
    // re-encode it (which would bump updated_at → change the sync hash → force a
    // needless ESP re-download). Requires php-gd (php8.3-gd on the Raspberry Pi).
    public static function resizeForEsp32(Photo $photo): void
    {
        if (! extension_loaded('gd') || ! $photo->image_path) {
            return;
        }

        try {
            $path = Storage::disk(Photo::DISK)->path($photo->image_path);
            $manager = new ImageManager(new Driver());
            $image = $manager->decode($path);

            if ($image->width() === 320 && $image->height() === 240) {
                return; // already device-sized
            }

            $image->cover(320, 240)->save($path);
        } catch (\Throwable $e) {
            \Log::warning("Photo resize failed for photo #{$photo->id}: {$e->getMessage()}");
        }
    }
}
