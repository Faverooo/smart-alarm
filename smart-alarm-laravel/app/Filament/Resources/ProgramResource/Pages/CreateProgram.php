<?php

namespace App\Filament\Resources\ProgramResource\Pages;

use App\Filament\Resources\ProgramResource;
use Filament\Resources\Pages\CreateRecord;

class CreateProgram extends CreateRecord
{
    protected static string $resource = ProgramResource::class;

    // Automatically assign the current user as owner when a non-admin creates a program
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        if (! auth()->user()->is_admin) {
            $data['user_id'] = auth()->id();
        }

        return $data;
    }

    // Land on the edit page so the user can immediately add photos / use the calendar
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}

