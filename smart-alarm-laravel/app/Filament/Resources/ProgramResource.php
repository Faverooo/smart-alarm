<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ProgramResource\Pages;
use App\Filament\Resources\ProgramResource\RelationManagers;
use App\Models\Program;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
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
                            ->required(),

                        Forms\Components\Select::make('type')
                            ->label('Type')
                            ->options([
                                'fixed'    => 'Fixed — always show one photo',
                                'daily'    => 'Daily — one new photo per day (queue)',
                                'rotation' => 'Rotation — cycle through photos every X minutes',
                            ])
                            ->default('fixed')
                            ->live()
                            ->required(),

                        Forms\Components\TextInput::make('interval_minutes')
                            ->label('Rotation Interval (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('30')
                            ->visible(fn (Forms\Get $get) => $get('type') === 'rotation')
                            ->requiredIf('type', 'rotation'),

                        Forms\Components\Toggle::make('is_active')
                            ->label('Active')
                            ->default(true),
                    ]),

                // Admins can assign programs to any user
                Forms\Components\Select::make('user_id')
                    ->label('Owner')
                    ->relationship('user', 'name')
                    ->default(fn () => auth()->id())
                    ->visible(fn () => $user->is_admin)
                    ->required(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable(),
                Tables\Columns\BadgeColumn::make('type')
                    ->label('Type')
                    ->colors([
                        'primary' => 'fixed',
                        'success' => 'daily',
                        'warning' => 'rotation',
                    ]),
                Tables\Columns\TextColumn::make('interval_minutes')
                    ->label('Interval')
                    ->formatStateUsing(fn ($state) => $state ? "{$state} min" : '—'),
                Tables\Columns\TextColumn::make('photos_count')
                    ->label('Photos')
                    ->counts('photos')
                    ->badge(),
                Tables\Columns\TextColumn::make('devices_count')
                    ->label('Devices')
                    ->counts('devices')
                    ->badge(),
                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->visible(fn () => auth()->user()->is_admin),
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
