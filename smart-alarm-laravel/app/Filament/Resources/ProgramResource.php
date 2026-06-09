<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProgramResource\Pages;
use App\Filament\Resources\ProgramResource\RelationManagers;
use App\Models\Program;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProgramResource extends Resource
{
    protected static ?string $model = Program::class;

    protected static ?string $navigationIcon = 'heroicon-o-play-circle';
    protected static ?string $navigationGroup = 'Media & Messages';
    protected static ?int $navigationSort = 2;

    // Visible to admins and photo managers
    public static function canViewAny(): bool
    {
        return auth()->user()->isPhotoManager();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    // Non-admins only see their own programs
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $user = auth()->user();

        if (! $user->is_admin) {
            $query->where('user_id', $user->id);
        }

        return $query;
    }

    public static function form(Form $form): Form
    {
        $user = auth()->user();

        return $form
            ->schema([
                Forms\Components\Section::make('Program Details')
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Program Name')
                            ->placeholder('Morning Memories')
                            ->maxLength(100)
                            ->required()
                            // Daily programs have no page Save button (the calendar
                            // saves instantly), so persist the name on blur instead.
                            ->live(onBlur: true)
                            ->afterStateUpdated(static::autoSaveOnDaily('name')),

                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options([
                                'fixed'    => 'Fixed — a single photo, always shown',
                                'rotation' => 'Rotation — cycle through photos every X minutes',
                                'daily'    => 'Daily — a different photo each day (calendar)',
                            ])
                            ->default('fixed')
                            ->live()
                            // Type is structural: lock it after creation. To change it,
                            // delete the program and create a new one.
                            ->disabled(fn (string $operation) => $operation === 'edit')
                            ->dehydrated()
                            ->helperText(fn (string $operation) => $operation === 'edit'
                                ? 'Type cannot be changed after creation. Delete the program to start over.'
                                : 'Choose carefully — this cannot be changed later.')
                            ->required(),

                        Forms\Components\TextInput::make('interval_minutes')
                            ->label('Rotation Interval (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('30')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'rotation')
                            ->requiredIf('type', 'rotation'),
                    ]),

                // Daily programs manage photos through a clickable calendar (one per day).
                // The calendar's action modals are teleported to <body> in its blade so their
                // <form> doesn't nest inside this page form (which would push "Save changes"
                // out of the form and stop it saving).
                Forms\Components\Section::make('Daily Calendar')
                    ->description('Click a day to set the photo shown on that date. Click again to replace it.')
                    ->visible(fn (string $operation, Forms\Get $get) => $operation === 'edit' && $get('type') === 'daily')
                    ->schema([
                        Forms\Components\Livewire::make(
                            \App\Livewire\DailyPhotoCalendar::class,
                            fn ($record) => ['program' => $record],
                        ),
                    ]),

                // Admins can assign programs to any user
                Forms\Components\Select::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'name')
                    ->default(fn () => auth()->id())
                    ->visible(fn () => $user->is_admin)
                    ->required()
                    ->live()
                    ->afterStateUpdated(static::autoSaveOnDaily('user_id')),
            ]);
    }

    /**
     * Daily programs drop the page Save button (the calendar commits instantly),
     * so the remaining editable fields persist themselves on change. Only fires
     * when editing a daily program and the value actually changed.
     */
    protected static function autoSaveOnDaily(string $column): \Closure
    {
        return function ($state, ?Program $record, string $operation) use ($column) {
            if ($operation !== 'edit' || ! $record || $record->type !== 'daily') {
                return;
            }

            if ($state === null || $state === '' || $record->{$column} === $state) {
                return;
            }

            $record->update([$column => $state]);

            Notification::make()->title('Saved')->success()->send();
        };
    }

    public static function table(Table $table): Table
    {
        return $table
            // Responsive layout: a single row on desktop (md+), a stacked card on mobile.
            ->columns([
                Split::make([
                    Stack::make([
                        Tables\Columns\TextColumn::make('name')
                            ->label('Name')
                            ->weight(FontWeight::Bold)
                            ->searchable()
                            ->sortable(),
                        Tables\Columns\TextColumn::make('user.name')
                            ->label('Owner')
                            ->icon('heroicon-m-user')
                            ->color('gray')
                            ->visible(fn () => auth()->user()->is_admin),
                    ]),
                    Tables\Columns\TextColumn::make('type')
                        ->label('Type')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'fixed'    => 'primary',
                            'daily'    => 'success',
                            'rotation' => 'warning',
                            default    => 'gray',
                        })
                        ->grow(false),
                    Tables\Columns\TextColumn::make('photos_count')
                        ->label('Photos')
                        ->counts('photos')
                        ->badge()
                        ->icon('heroicon-m-photo')
                        ->color('gray')
                        ->grow(false),
                    Tables\Columns\TextColumn::make('devices_count')
                        ->label('Devices')
                        ->counts('devices')
                        ->badge()
                        ->icon('heroicon-m-cpu-chip')
                        ->color('gray')
                        ->grow(false),
                    Tables\Columns\TextColumn::make('interval_minutes')
                        ->label('Interval')
                        ->formatStateUsing(fn ($state) => $state ? "{$state} min" : '—')
                        ->color('gray')
                        ->grow(false),
                ])->from('md'),
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
        return [
            RelationManagers\PhotosRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPrograms::route('/'),
            'create' => Pages\CreateProgram::route('/create'),
            'edit' => Pages\EditProgram::route('/{record}/edit'),
        ];
    }
}
