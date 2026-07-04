<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use App\Models\Device;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditDevice extends EditRecord
{
    protected static string $resource = DeviceResource::class;

    // The active program belongs to the photo manager. If an admin clears or
    // replaces `photo_user_id` from this form, the old manager's program must
    // not keep showing on the device (the "forget" action and the API path
    // already null it) — unless a new program was picked in the same save.
    // The non-admin form never submits `photo_user_id`, so this is a no-op there.
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (array_key_exists('photo_user_id', $data)
            && $data['photo_user_id'] != $this->record->photo_user_id
            && ($data['program_id'] ?? null) == $this->record->program_id) {
            $data['program_id'] = null;
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        $user = auth()->user();
        $actions = [];

        // Photo managers can rotate the invite code — but only while the alarm slot
        // is open. Once both managers are set the code is intentionally null (no
        // standing join secret); to invite a different alarm manager, remove the
        // current one first (that frees the slot and mints a fresh code).
        if (! $user->is_admin
            && $this->record->photo_user_id === $user->id
            && $this->record->alarm_user_id === null) {
            $actions[] = Actions\Action::make('regenerateCode')
                ->label('Regenerate Pairing Code')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->requiresConfirmation()
                ->modalHeading('Generate a new pairing code?')
                ->modalDescription('The old code will stop working immediately.')
                ->action(function (): void {
                    // Same generator as pair/init: 6 digits, guaranteed unique
                    // across devices (claimRole looks devices up by code alone).
                    $code = Device::generatePairingCode();
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
