<?php

namespace App\Filament\Resources\ProgramResource\Pages;

use App\Filament\Resources\ProgramResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditProgram extends EditRecord
{
    protected static string $resource = ProgramResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    // Daily programs are edited entirely through instant-saving controls — the
    // calendar commits immediately and name/owner auto-save on blur — so the
    // page-level Save/Cancel buttons only cause confusion. Drop them for daily.
    protected function getFormActions(): array
    {
        if ($this->record->type === 'daily') {
            return [];
        }

        return parent::getFormActions();
    }
}
