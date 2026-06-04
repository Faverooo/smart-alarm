<?php

namespace App\Filament\Resources\AlarmResource\Pages;

use App\Filament\Resources\AlarmResource;
use Carbon\Carbon;
use Filament\Resources\Pages\CreateRecord;

class CreateAlarm extends CreateRecord
{
    protected static string $resource = AlarmResource::class;

    // Convert local time input to UTC before storing
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! empty($data['time'])) {
            $data['time'] = Carbon::today(config('app.timezone'))
                ->setTimeFromTimeString($data['time'])
                ->utc()
                ->format('H:i:s');
        }

        return $data;
    }
}
