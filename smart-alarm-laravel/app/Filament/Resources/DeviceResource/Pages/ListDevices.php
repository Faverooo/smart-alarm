<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use App\Models\Device;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\RateLimiter;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    protected function getHeaderActions(): array
    {
        $user = auth()->user();

        if ($user->is_admin) {
            return [Actions\CreateAction::make()];
        }

        return [
            // Claim a self-registered device as its photo/message manager (primary owner)
            Actions\Action::make('claimDevice')
                ->label('Add Device')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('Add a Device')
                ->modalDescription('Enter the pairing code shown on the device screen. The code is valid for one hour after the device powers on.')
                ->form([
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Pairing Code')
                        ->placeholder('123456')
                        ->required()
                        ->maxLength(6),
                    Forms\Components\TextInput::make('name')
                        ->label('Device Name (optional)')
                        ->placeholder('Bedroom Alarm')
                        ->maxLength(100),
                    Forms\Components\Toggle::make('also_alarm_manager')
                        ->label('I will also manage alarms on this device')
                        ->helperText('Turn on if you are setting this up for yourself alone.')
                        ->default(false),
                ])
                ->action(fn (array $data) => $this->claimRole(
                    $data['pairing_code'],
                    role: 'photo',
                    name: $data['name'] ?? null,
                    alsoAlarm: (bool) ($data['also_alarm_manager'] ?? false),
                )),

            // Join an already-owned device as its alarm manager (second person)
            Actions\Action::make('joinDevice')
                ->label('Join as Alarm Manager')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->modalHeading('Join a Device')
                ->modalDescription('Enter the pairing code shown on the device (or shared by the photo manager).')
                ->form([
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Pairing Code')
                        ->placeholder('123456')
                        ->maxLength(6)
                        ->required(),
                ])
                ->action(fn (array $data) => $this->claimRole($data['pairing_code'], role: 'alarm')),
        ];
    }

    /**
     * Link the current user to a device (by the code shown on its screen) in the
     * given role. Enforces the pairing rules: an unclaimed device can only be
     * taken while its 1-hour pairing window is open, and a role can't be stolen.
     */
    private function claimRole(string $code, string $role, ?string $name = null, bool $alsoAlarm = false): void
    {
        // Codes are only 6 digits, so cap the guessing rate: 10 failed claims
        // per user per 5 minutes. Cleared on success so honest typos don't
        // lock anyone out for long.
        $throttleKey = 'claim-device:'.auth()->id();

        if (RateLimiter::tooManyAttempts($throttleKey, 10)) {
            Notification::make()
                ->title('Too many attempts')
                ->body('Please wait a few minutes before trying another code.')
                ->danger()
                ->send();

            return;
        }

        $device = Device::where('pairing_code', strtoupper(trim($code)))->first();

        if (! $device) {
            RateLimiter::hit($throttleKey, 300);

            Notification::make()
                ->title('Invalid pairing code')
                ->body('No device found with that code. Check the code on the device screen.')
                ->danger()
                ->send();

            return;
        }

        RateLimiter::clear($throttleKey);

        // The 1-hour window only gates a device that nobody has claimed yet.
        if (! $device->isOwned() && ! $device->isClaimable()) {
            Notification::make()
                ->title('Pairing window expired')
                ->body('Restart the device to generate a fresh pairing code, then try again.')
                ->danger()
                ->send();

            return;
        }

        $column = $role === 'alarm' ? 'alarm_user_id' : 'photo_user_id';

        if ($device->{$column} !== null && $device->{$column} !== auth()->id()) {
            Notification::make()
                ->title('Role already taken')
                ->body("This device already has a {$role} manager.")
                ->warning()
                ->send();

            return;
        }

        $device->fill([$column => auth()->id()]);

        if ($role === 'photo') {
            if ($name) {
                $device->name = $name;
            }
            if ($alsoAlarm && $device->alarm_user_id === null) {
                $device->alarm_user_id = auth()->id();
            }
        }

        $device->save();

        Notification::make()
            ->title('Device added: "'.$device->display_name.'"')
            ->body($role === 'alarm' ? 'You manage alarms on this device.' : 'You manage photos & messages on this device.')
            ->success()
            ->send();
    }
}
