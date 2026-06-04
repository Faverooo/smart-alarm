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

    // Resize to 320x240 for the 3.2" TFT display.
    // Requires php-gd (sudo apt-get install php8.3-gd on the Raspberry Pi).
    public static function resizeForEsp32(Photo $photo): void
    {
        if (! extension_loaded('gd')) {
            return;
        }

        try {
            $path = Storage::disk('public')->path($photo->image_path);
            $manager = new ImageManager(new Driver());
            $manager->decode($path)->cover(320, 240)->save($path);
        } catch (\Throwable $e) {
            \Log::warning("Photo resize failed for photo #{$photo->id}: {$e->getMessage()}");
        }
    }
}
