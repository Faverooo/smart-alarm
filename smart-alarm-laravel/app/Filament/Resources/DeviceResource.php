<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DeviceResource\Pages;
use App\Models\Device;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static ?string $navigationIcon = 'heroicon-o-cpu-chip';
    protected static ?string $navigationGroup = 'Device';
    protected static ?int $navigationSort = 0;

    // Admins and photo managers can see devices
    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user->is_admin || $user->isPhotoManager();
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
    public static function form(Form $form): Form
    {
        $isAdmin = auth()->user()->is_admin;

        return $form->schema(
            $isAdmin
                ? static::adminSchema()
                : static::userSchema()
        );
    }

    private static function adminSchema(): array
    {
        return [
            Forms\Components\Section::make('Device Identity')
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

            Forms\Components\Section::make('Two-Key Assignment')
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

            Forms\Components\Section::make('Active Program')
                ->schema([
                    Forms\Components\Select::make('program_id')
                        ->label('Program')
                        ->relationship('program', 'name')
                        ->searchable()
                        ->nullable(),
                ]),
        ];
    }

    // Non-admin: only name + program + pairing info (read-only identity fields)
    private static function userSchema(): array
    {
        return [
            Forms\Components\Section::make('Device')
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

            Forms\Components\Section::make('Pairing Code')
                ->description('Share this code with the person who will manage alarms on this device.')
                ->schema([
                    Forms\Components\TextInput::make('pairing_code')
                        ->label('Current Code')
                        ->disabled()
                        ->dehydrated(false)
                        ->helperText('This person must enter this code when they log in and add the device.'),
                ]),

            Forms\Components\Section::make('Alarm Manager')
                ->schema([
                    Forms\Components\Placeholder::make('alarm_manager_name')
                        ->label('Assigned alarm manager')
                        ->content(fn ($record) => $record?->alarmUser?->name ?? 'Nobody yet — share the pairing code above.'),
                ]),

            Forms\Components\Section::make('Active Program')
                ->schema([
                    Forms\Components\Select::make('program_id')
                        ->label('Program')
                        ->options(function () {
                            $user = auth()->user();

                            return $user->programs()->where('is_active', true)->pluck('name', 'id');
                        })
                        ->searchable()
                        ->nullable()
                        ->helperText('The photo program currently active on this device'),
                ]),
        ];
    }

    public static function table(Table $table): Table
    {
        $isAdmin = auth()->user()->is_admin;

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('mac_address')
                    ->label('MAC Address')
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->visible($isAdmin),
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('alarmUser.name')
                    ->label('Alarm Manager')
                    ->placeholder('Not assigned')
                    ->icon('heroicon-m-bell'),
                Tables\Columns\TextColumn::make('photoUser.name')
                    ->label('Photo Manager')
                    ->placeholder('Not assigned')
                    ->icon('heroicon-m-photo')
                    ->visible($isAdmin),
                Tables\Columns\TextColumn::make('program.name')
                    ->label('Active Program')
                    ->placeholder('None'),
                Tables\Columns\TextColumn::make('pairing_code')
                    ->label('Pairing Code')
                    ->placeholder('—')
                    ->fontFamily('mono'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),

                // Non-admins: leave the device (nullify their role)
                Tables\Actions\Action::make('forget')
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
                        }

                        $record->update($updates);

                        // Delete orphaned devices (no users attached)
                        $fresh = $record->fresh();
                        if ($fresh->alarm_user_id === null && $fresh->photo_user_id === null) {
                            $fresh->delete();
                        }
                    })
                    ->visible(fn () => ! auth()->user()->is_admin),

                Tables\Actions\DeleteAction::make()
                    ->visible($isAdmin),
            ])
            ->bulkActions(
                $isAdmin
                    ? [Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]
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
