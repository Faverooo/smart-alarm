<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Support\Enums\TextSize;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-cpu-chip';
    protected static \UnitEnum|string|null $navigationGroup = 'Device';
    protected static ?int $navigationSort = 0;

    // All authenticated users can see the Devices page (empty list + "Add Device"
    // button for new users). getEloquentQuery() handles per-user filtering.
    public static function canViewAny(): bool
    {
        return auth()->check();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    // Admins use the standard Create page; non-admins use the "Register" modal in ListDevices
    public static function canCreate(): bool
    {
        return auth()->user()->is_admin;
    }

    public static function canDelete(Model $record): bool
    {
        return auth()->user()->is_admin;
    }

    // Non-admins only see devices they are part of
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user->is_admin) {
            $query->where(function ($q) use ($user) {
                $q->where('alarm_user_id', $user->id)
                    ->orWhere('photo_user_id', $user->id);
            });
        }

        return $query;
    }

    // ── Admin form (full control) ──────────────────────────────────────────────
    public static function form(Schema $schema): Schema
    {
        $isAdmin = auth()->user()->is_admin;

        return $schema
            ->columns(1)
            ->schema(
                $isAdmin
                    ? static::adminSchema()
                    : static::userSchema()
            );
    }

    private static function adminSchema(): array
    {
        return [
            Schemas\Components\Section::make('Device Identity')
                ->schema([
                    Forms\Components\TextInput::make('mac_address')
                        ->label('MAC Address')
                        ->unique(ignoreRecord: true)
                        ->required(),
                    Forms\Components\TextInput::make('name')
                        ->label('Device Name')
                        ->placeholder('Bedroom Alarm')
                        ->maxLength(100),
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Pairing Code')
                        ->maxLength(6)
                        ->helperText('6-character code shown on the device screen during setup'),
                ])
                ->columns(3),

            Schemas\Components\Section::make('Two-Key Assignment')
                ->schema([
                    Forms\Components\Select::make('alarm_user_id')
                        ->label('Alarm Manager')
                        ->relationship('alarmUser', 'name')
                        ->searchable()
                        ->nullable(),
                    Forms\Components\Select::make('photo_user_id')
                        ->label('Photo & Message Manager')
                        ->relationship('photoUser', 'name')
                        ->searchable()
                        ->nullable(),
                ])
                ->columns(2),

            Schemas\Components\Section::make('Active Program')
                ->schema([
                    Forms\Components\Select::make('program_id')
                        ->label('Program')
                        ->relationship('program', 'name')
                        ->searchable()
                        ->nullable(),
                ]),

            static::settingsSection(),
            static::telemetrySection(),
        ];
    }

    // Display & sound config pushed to the device through /sync.
    private static function settingsSection(): Schemas\Components\Section
    {
        return Schemas\Components\Section::make('Display & Sound')
            ->description('Pushed to the device on its next sync.')
            ->schema([
                Forms\Components\TextInput::make('brightness')
                    ->label('Day brightness')->numeric()->minValue(0)->maxValue(100)->default(80)->suffix('%'),
                Forms\Components\TextInput::make('night_brightness')
                    ->label('Night brightness')->numeric()->minValue(0)->maxValue(100)->default(10)->suffix('%'),
                Forms\Components\TextInput::make('volume')
                    ->label('Alarm volume')->numeric()->minValue(0)->maxValue(100)->default(70)->suffix('%'),
                Forms\Components\Select::make('time_format')
                    ->label('Clock format')->options(['24' => '24-hour', '12' => '12-hour'])->default('24'),
                Forms\Components\TimePicker::make('night_start')
                    ->label('Night mode from')->seconds(false)->default('22:00'),
                Forms\Components\TimePicker::make('night_end')
                    ->label('Night mode until')->seconds(false)->default('07:00'),
            ])
            ->columns(3);
    }

    // Read-only diagnostics reported by the device (POST /device/status).
    private static function telemetrySection(): Schemas\Components\Section
    {
        return Schemas\Components\Section::make('Device Status')
            ->schema([
                Forms\Components\Placeholder::make('online_state')
                    ->label('Connection')
                    ->content(fn ($record) => $record?->isOnline() ? 'Online' : 'Offline'),
                Forms\Components\Placeholder::make('firmware_version')
                    ->label('Firmware')
                    ->content(fn ($record) => $record?->firmware_version ?? '—'),
                Forms\Components\Placeholder::make('last_status_at')
                    ->label('Last heartbeat')
                    ->content(fn ($record) => $record?->last_status_at?->diffForHumans() ?? 'Never'),
                Forms\Components\Placeholder::make('rssi')
                    ->label('WiFi signal')
                    ->content(fn ($record) => $record?->rssi !== null ? $record->rssi.' dBm' : '—'),
                Forms\Components\Placeholder::make('last_ip')
                    ->label('IP address')
                    ->content(fn ($record) => $record?->last_ip ?? '—'),
                Forms\Components\Placeholder::make('free_heap')
                    ->label('Free RAM')
                    ->content(fn ($record) => $record?->free_heap !== null ? number_format($record->free_heap / 1024).' KB' : '—'),
            ])
            ->columns(3)
            ->visibleOn('edit');
    }

    // Non-admin: only name + program + pairing info (read-only identity fields)
    private static function userSchema(): array
    {
        return [
            Schemas\Components\Section::make('Device')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Device Name')
                        ->maxLength(100),
                    Forms\Components\TextInput::make('mac_address')
                        ->label('MAC Address')
                        ->disabled()
                        ->dehydrated(false),
                ])
                ->columns(2),

            Schemas\Components\Section::make('Pairing Code')
                ->description('Share this code with the person who will manage alarms on this device.')
                ->schema([
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Current Code')
                        ->placeholder('— fully paired (no open slot)')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('The alarm manager enters this code to join. It disappears once both managers are set; removing the alarm manager generates a fresh one.'),
                ]),

            Schemas\Components\Section::make('Alarm Manager')
                ->schema([
                    Forms\Components\Placeholder::make('alarm_manager_name')
                        ->label('Assigned alarm manager')
                        ->content(fn ($record) => $record?->alarmUser?->name ?? 'Nobody yet — share the pairing code above.'),
                ]),

            Schemas\Components\Section::make('Active Program')
                ->schema([
                    Forms\Components\Select::make('program_id')
                        ->label('Program')
                        ->options(function () {
                            $user = auth()->user();

                            return $user->programs()->pluck('name', 'id');
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('The photo program currently active on this device'),
                ]),

            static::settingsSection(),
            static::telemetrySection(),
        ];
    }

    public static function table(Table $table): Table
    {
        $isAdmin = auth()->user()->is_admin;

        return $table
            // Responsive layout: a single row on desktop (md+), a stacked card on mobile.
            ->columns([
                Split::make([
                    Tables\Columns\IconColumn::make('online')
                        ->label('Status')
                        ->getStateUsing(fn (Device $record) => $record->isOnline())
                        ->boolean()
                        ->trueIcon('heroicon-m-signal')
                        ->falseIcon('heroicon-m-signal-slash')
                        ->trueColor('success')
                        ->falseColor('gray')
                        ->tooltip(fn (Device $record) => $record->last_status_at?->diffForHumans() ?? 'Never seen')
                        ->grow(false),
                    Stack::make([
                        Tables\Columns\TextColumn::make('name')
                            ->label('Name')
                            ->placeholder('Unnamed device')
                            ->weight(FontWeight::Bold)
                            ->searchable(),
                        Tables\Columns\TextColumn::make('mac_address')
                            ->label('MAC Address')
                            ->searchable()
                            ->copyable()
                            ->fontFamily('mono')
                            ->size(TextSize::Small)
                            ->color('gray')
                            ->visible($isAdmin),
                        Tables\Columns\TextColumn::make('alarmUser.name')
                            ->label('Alarm Manager')
                            ->placeholder('No alarm manager')
                            ->icon('heroicon-m-bell')
                            ->color('gray'),
                        Tables\Columns\TextColumn::make('photoUser.name')
                            ->label('Photo Manager')
                            ->placeholder('No photo manager')
                            ->icon('heroicon-m-photo')
                            ->color('gray')
                            ->visible($isAdmin),
                        Tables\Columns\TextColumn::make('program.name')
                            ->label('Active Program')
                            ->placeholder('No program')
                            ->icon('heroicon-m-play-circle')
                            ->color('gray'),
                        Tables\Columns\TextColumn::make('pairing_code')
                            ->label('Pairing Code')
                            ->placeholder('—')
                            ->prefix('Code: ')
                            ->fontFamily('mono')
                            ->color('gray'),
                    ]),
                ])->from('md'),
            ])
            ->recordActions([
                Actions\EditAction::make(),

                // Photo manager: claim the empty alarm-manager slot for themselves
                Actions\Action::make('becomeAlarmManager')
                    ->label('Become Alarm Manager')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Manage alarms on this device yourself?')
                    ->modalDescription('You will be set as the alarm manager for this device.')
                    ->visible(fn (Device $record) => ! auth()->user()->is_admin
                        && $record->photo_user_id === auth()->id()
                        && $record->alarm_user_id === null)
                    ->action(function (Device $record): void {
                        $record->update(['alarm_user_id' => auth()->id()]);

                        Notification::make()
                            ->title('You are now the alarm manager for this device.')
                            ->success()
                            ->send();
                    }),

                // Photo manager: clear whoever currently manages alarms
                Actions\Action::make('removeAlarmManager')
                    ->label('Remove Alarm Manager')
                    ->icon('heroicon-o-user-minus')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Remove the current alarm manager?')
                    ->modalDescription('The alarm-manager slot will become empty. You can re-share the pairing code or take it yourself.')
                    ->visible(fn (Device $record) => ! auth()->user()->is_admin
                        && $record->photo_user_id === auth()->id()
                        && $record->alarm_user_id !== null)
                    ->action(function (Device $record): void {
                        $record->update(['alarm_user_id' => null]);

                        Notification::make()
                            ->title('Alarm manager removed.')
                            ->success()
                            ->send();
                    }),

                // Non-admins: leave the device (nullify their role)
                Actions\Action::make('forget')
                    ->label('Forget Device')
                    ->icon('heroicon-o-arrow-left-on-rectangle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Forget this device?')
                    ->modalDescription('You will be removed from this device. The device itself will not be deleted.')
                    ->action(function (Device $record): void {
                        $user = auth()->user();
                        $updates = [];

                        if ($record->alarm_user_id === $user->id) {
                            $updates['alarm_user_id'] = null;
                        }

                        if ($record->photo_user_id === $user->id) {
                            $updates['photo_user_id'] = null;
                            // Their program (and its photos) must not keep
                            // showing on a device they no longer manage.
                            $updates['program_id'] = null;
                        }

                        $record->update($updates);

                        // Delete orphaned devices (no users attached)
                        $fresh = $record->fresh();
                        if ($fresh->alarm_user_id === null && $fresh->photo_user_id === null) {
                            $fresh->delete();
                        }
                    })
                    ->visible(fn () => ! auth()->user()->is_admin),

                Actions\DeleteAction::make()
                    ->visible($isAdmin),
            ])
            ->toolbarActions(
                $isAdmin
                    ? [Actions\BulkActionGroup::make([Actions\DeleteBulkAction::make()])]
                    : []
            );
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDevices::route('/'),
            'create' => Pages\CreateDevice::route('/create'),
            'edit'   => Pages\EditDevice::route('/{record}/edit'),
        ];
    }
}
