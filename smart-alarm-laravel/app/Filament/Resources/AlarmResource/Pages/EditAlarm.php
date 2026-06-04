<?php

namespace App\Filament\Resources\AlarmResource\Pages;

use App\Filament\Resources\AlarmResource;
use Carbon\Carbon;
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

    // Convert stored UTC time to local when populating the form
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (! empty($data['time'])) {
            $data['time'] = Carbon::today('UTC')
                ->setTimeFromTimeString($data['time'])
                ->setTimezone(config('app.timezone'))
                ->format('H:i:s');
        }

        return $data;
    }

    // Convert local time input back to UTC before saving
    protected function mutateFormDataBeforeSave(array $data): array
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
