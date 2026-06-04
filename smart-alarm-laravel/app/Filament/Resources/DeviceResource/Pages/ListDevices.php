<?php

namespace App\Filament\Resources\DeviceResource\Pages;

use App\Filament\Resources\DeviceResource;
use App\Models\Device;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Str;

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
            // Register a brand new device (user becomes photo manager)
            Actions\Action::make('registerDevice')
                ->label('Register New Device')
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading('Register a New Device')
                ->modalDescription('Enter the MAC address shown on the device screen when it first boots.')
                ->form([
                    Forms\Components\TextInput::make('mac_address')
                        ->label('Device MAC Address')
                        ->placeholder('AA:BB:CC:DD:EE:FF')
                        ->required()
                        ->rules(['regex:/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/']),
                    Forms\Components\TextInput::make('name')
                        ->label('Device Name')
                        ->placeholder('Bedroom Alarm')
                        ->maxLength(100),
                    Forms\Components\Toggle::make('also_alarm_manager')
                        ->label('I will also manage alarms on this device')
                        ->helperText('Turn on if you are setting this up for yourself alone.')
                        ->default(false),
                ])
                ->action(function (array $data): void {
                    $code = strtoupper(Str::random(6));

                    $device = Device::create([
                        'mac_address'   => strtoupper($data['mac_address']),
                        'name'          => $data['name'] ?? null,
                        'pairing_code'  => $code,
                        'photo_user_id' => auth()->id(),
                        'alarm_user_id' => $data['also_alarm_manager'] ? auth()->id() : null,
                    ]);

                    Notification::make()
                        ->title("Device registered! Pairing code: {$code}")
                        ->body('Share this code with the person who will manage alarms. They will find the "Join with Code" button on their Device page.')
                        ->success()
                        ->persistent()
                        ->send();
                }),

            // Join an existing device as alarm manager using a pairing code
            Actions\Action::make('joinDevice')
                ->label('Join with Pairing Code')
                ->icon('heroicon-o-key')
                ->color('gray')
                ->modalHeading('Join a Device')
                ->modalDescription('Enter the 6-character pairing code given to you by the device owner.')
                ->form([
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Pairing Code')
                        ->placeholder('ABCD12')
                        ->maxLength(6)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $device = Device::where('pairing_code', strtoupper($data['pairing_code']))->first();

                    if (! $device) {
                        Notification::make()
                            ->title('Invalid pairing code')
                            ->body('No device found with that code. Double-check with the device owner.')
                            ->danger()
                            ->send();

                        return;
                    }

                    if ($device->alarm_user_id !== null && $device->alarm_user_id !== auth()->id()) {
                        Notification::make()
                            ->title('Device already has an alarm manager')
                            ->body('Ask the device owner to reset the pairing code if you need to change the alarm manager.')
                            ->warning()
                            ->send();

                        return;
                    }

                    $device->update(['alarm_user_id' => auth()->id()]);

                    Notification::make()
                        ->title('Joined "' . $device->display_name . '" as alarm manager!')
                        ->success()
                        ->send();
                }),
        ];
    }
}
