<?php

namespace App\Filament\Resources\AlarmResource\Pages;

use App\Filament\Resources\AlarmResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditAlarm extends EditRecord
{
    protected static string $resource = AlarmResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
