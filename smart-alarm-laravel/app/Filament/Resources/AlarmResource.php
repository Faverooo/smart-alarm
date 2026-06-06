<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlarmResource\Pages;
use App\Models\Alarm;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AlarmResource extends Resource
{
    protected static ?string $model = Alarm::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell';
    protected static ?string $navigationGroup = 'Alarm Management';
    protected static ?int $navigationSort = 1;

    // Visible to admins and users who are the alarm manager on at least one device
    public static function canViewAny(): bool
    {
        return auth()->user()->isAlarmManager();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    // Non-admins only see alarms belonging to their devices
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user->is_admin) {
            $deviceIds = $user->alarmDevices()->pluck('id');
            $query->whereIn('device_id', $deviceIds);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Forms\Components\Section::make('Device')
                    ->schema([
                        Forms\Components\Select::make('device_id')
                            ->label('Device')
                            ->options(function () use ($user) {
                                if ($user->is_admin) {
                                    return \App\Models\Device::all()->pluck('display_name', 'id');
                                }

                                return $user->alarmDevices()->get()->pluck('display_name', 'id');
                            })
                            ->default(function () use ($user) {
                                if ($user->is_admin) {
                                    return null;
                                }
                                $devices = $user->alarmDevices()->get();

                                return $devices->count() === 1 ? $devices->first()->id : null;
                            })
                            ->searchable()
                            ->required(),
                    ]),

                Forms\Components\Section::make('Schedule')
                    ->schema([
                        Forms\Components\TimePicker::make('time')
                            ->label('Alarm Time')
                            ->helperText('Local time on the device (Europe/Rome).')
                            ->seconds(false)
                            ->required(),

                        Forms\Components\Grid::make(2)
                            ->schema([
                                Forms\Components\DatePicker::make('scheduled_date')
                                    ->label('One-time Date')
                                    ->helperText('Set a date for a one-time alarm. Leave empty for a recurring alarm.'),

                                Forms\Components\CheckboxList::make('days_of_week')
                                    ->label('Recurring Days')
                                    ->options([
                                        'Monday'    => 'Monday',
                                        'Tuesday'   => 'Tuesday',
                                        'Wednesday' => 'Wednesday',
                                        'Thursday'  => 'Thursday',
                                        'Friday'    => 'Friday',
                                        'Saturday'  => 'Saturday',
                                        'Sunday'    => 'Sunday',
                                    ])
                                    ->columns(2)
                                    ->helperText('Only used when no one-time date is set.'),
                            ]),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('One-time alarms auto-deactivate after the ESP32 dismisses them.'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('device.mac_address')
                    ->label('Device')
                    ->formatStateUsing(fn ($state, $record) => $record->device->display_name)
                    ->sortable()
                    ->searchable(),
                Tables\Columns\TextColumn::make('time')
                    ->label('Time')
                    ->formatStateUsing(fn ($state) => substr((string) $state, 0, 5))
                    ->sortable(),
                Tables\Columns\TextColumn::make('scheduled_date')
                    ->label('One-time Date')
                    ->date('d/m/Y')
                    ->placeholder('Recurring')
                    ->sortable(),
                Tables\Columns\TextColumn::make('days_of_week')
                    ->label('Days')
                    ->formatStateUsing(function ($state) {
                        if (empty($state)) {
                            return '—';
                        }

                        return implode(', ', array_map(fn ($d) => substr($d, 0, 3), $state));
                    }),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('updated_at')
                    ->label('Last Updated')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('time')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\Filter::make('one_time')
                    ->label('One-time only')
                    ->query(fn (Builder $query) => $query->whereNotNull('scheduled_date')),
                Tables\Filters\Filter::make('recurring')
                    ->label('Recurring only')
                    ->query(fn (Builder $query) => $query->whereNull('scheduled_date')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlarms::route('/'),
            'create' => Pages\CreateAlarm::route('/create'),
            'edit' => Pages\EditAlarm::route('/{record}/edit'),
        ];
    }
}
