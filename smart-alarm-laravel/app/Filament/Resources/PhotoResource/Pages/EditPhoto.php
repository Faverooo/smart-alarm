<?php

namespace App\Filament\Resources\PhotoResource\Pages;

use App\Filament\Resources\PhotoResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditPhoto extends EditRecord
{
    protected static string $resource = PhotoResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // If the image file was replaced, re-resize it to 320x240 so the public
    // `url` is always device-sized (the resize is idempotent — a no-op when the
    // file is already 320x240, e.g. caption-only edits).
    protected function afterSave(): void
    {
        CreatePhoto::resizeForEsp32($this->record);
    }
}
