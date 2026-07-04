<?php

namespace App\Filament\Resources\AlarmResource\Pages;

use App\Filament\Resources\AlarmResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlarm extends CreateRecord
{
    protected static string $resource = AlarmResource::class;

    // Times are stored as local wall-clock values (Europe/Rome) — no conversion needed.

    // A one-time date wins over recurring days (the device ignores the days
    // when a date is set) — drop them so the record matches what will happen.
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['scheduled_date'])) {
            $data['days_of_week'] = null;
        }

        return $data;
    }
}
