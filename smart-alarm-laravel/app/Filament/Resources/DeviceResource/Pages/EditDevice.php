<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Str;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $actions = [];

        // Photo managers can regenerate the pairing code to invite a new alarm manager
        if (! $user->is_admin && $this->record->photo_user_id === $user->id) {
            $actions[] = Actions\Action::make('regenerateCode')
                ->label('Regenerate Pairing Code')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Generate a new pairing code?')
                ->modalDescription('The old code will stop working immediately.')
                ->action(function (): void {
                    $code = strtoupper(Str::random(6));
                    $this->record->update(['pairing_code' => $code]);
                    $this->refreshFormData(['pairing_code']);

                    Notification::make()
                        ->title("New pairing code: {$code}")
                        ->body('Share this code with the new alarm manager.')
                        ->success()
                        ->persistent()
                        ->send();
                });
        }

        if ($user->is_admin) {
            $actions[] = Actions\DeleteAction::make();
        }

        return $actions;
    }
}
