<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MessageResource\Pages;
use App\Models\Message;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MessageResource extends Resource
{
    protected static ?string $model = Message::class;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';
    protected static ?string $navigationGroup = 'Media & Messages';
    protected static ?int $navigationSort = 3;

    // Visible to admins and photo managers (messages are managed by the photo user)
    public static function canViewAny(): bool
    {
        return auth()->user()->isPhotoManager();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    // Non-admins only see messages for devices where they are the photo manager
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user->is_admin) {
            $deviceIds = $user->photoDevices()->pluck('id');
            $query->whereIn('device_id', $deviceIds);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Forms\Components\Section::make('Message')
                    ->schema([
                        Forms\Components\Select::make('device_id')
                            ->label('Device')
                            ->options(function () use ($user) {
                                if ($user->is_admin) {
                                    return \App\Models\Device::all()->pluck('display_name', 'id');
                                }

                                return $user->photoDevices()->get()->pluck('display_name', 'id');
                            })
                            ->default(function () use ($user) {
                                if ($user->is_admin) {
                                    return null;
                                }
                                $devices = $user->photoDevices()->get();

                                return $devices->count() === 1 ? $devices->first()->id : null;
                            })
                            ->searchable()
                            ->required(),

                        Forms\Components\Textarea::make('text_content')
                            ->label('Message Text')
                            ->placeholder("Today it's raining, don't forget your umbrella!")
                            ->rows(3)
                            ->maxLength(500)
                            ->required(),

                        Forms\Components\DateTimePicker::make('scheduled_at')
                            ->label('Show At')
                            ->seconds(false)
                            ->helperText('Local time on the device (Europe/Rome).')
                            ->required(),
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
                Tables\Columns\TextColumn::make('text_content')
                    ->label('Message')
                    ->limit(60)
                    ->searchable(),
                Tables\Columns\TextColumn::make('scheduled_at')
                    ->label('Scheduled At')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                Tables\Columns\IconColumn::make('is_read')
                    ->label('Read')
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('scheduled_at')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_read')->label('Read status'),
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming')
                    ->query(fn (Builder $query) => $query->where('scheduled_at', '>=', now())),
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
            'index' => Pages\ListMessages::route('/'),
            'create' => Pages\CreateMessage::route('/create'),
            'edit' => Pages\EditMessage::route('/{record}/edit'),
        ];
    }
}
