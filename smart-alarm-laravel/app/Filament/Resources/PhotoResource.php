<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PhotoResource\Pages;
use App\Models\Photo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhotoResource extends Resource
{
    protected static ?string $model = Photo::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationGroup = 'Media & Messages';
    protected static ?int $navigationSort = 1;

    // Visible to admins and photo managers
    public static function canViewAny(): bool
    {
        return auth()->user()->isPhotoManager();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    // Non-admins only see their own photos
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
                Forms\Components\Section::make('Photo')
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            ->disk('public')
                            ->directory('photos')
                            ->maxSize(10240)
                            ->helperText('Automatically resized to 320×240 px on upload.')
                            ->required(),
                        Forms\Components\TextInput::make('caption')
                            ->label('Caption')
                            ->placeholder('Good morning! ☀️')
                            ->maxLength(255),
                    ]),

                // Admins can assign photos to any user; regular users auto-own their uploads
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
                Tables\Columns\ViewColumn::make('image_path')
                    ->label('Preview')
                    ->view('tables.columns.photo-lightbox'),
                Tables\Columns\TextColumn::make('caption')
                    ->label('Caption')
                    ->placeholder('—')
                    ->searchable()
                    ->limit(50),
                Tables\Columns\TextColumn::make('programs_count')
                    ->label('Programs')
                    ->counts('programs')
                    ->badge(),
                Tables\Columns\TextColumn::make('user.name')
                    ->label('Owner')
                    ->visible(fn () => auth()->user()->is_admin),
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Uploaded')
                    ->since()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
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
            'index' => Pages\ListPhotos::route('/'),
            'create' => Pages\CreatePhoto::route('/create'),
            'edit' => Pages\EditPhoto::route('/{record}/edit'),
        ];
    }
}
