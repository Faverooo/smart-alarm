<?php

namespace App\Filament\Resources\AlarmResource\Pages;

use App\Filament\Resources\AlarmResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAlarm extends CreateRecord
{
    protected static string $resource = AlarmResource::class;

    // Times are stored as local wall-clock values (Europe/Rome) — no conversion needed.
}
