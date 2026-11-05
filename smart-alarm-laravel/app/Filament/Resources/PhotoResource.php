<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PhotoResource\Pages;
use App\Models\Photo;
use Filament\Actions;
use Filament\Forms;
use Filament\Schemas;
use Filament\Schemas\Schema;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PhotoResource extends Resource
{
    protected static ?string $model = Photo::class;

    protected static \BackedEnum|string|null $navigationIcon = 'heroicon-o-photo';
    protected static \UnitEnum|string|null $navigationGroup = 'Media & Messages';
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

    public static function form(Schema $schema): Schema
    {
        $user = auth()->user();

        return $schema
            ->columns(1)
            ->schema([
                Schemas\Components\Section::make('Photo')
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Image')
                            ->image()
                            // Live crop selector locked to the device's 4:3 (320×240)
                            // screen — the user just drags the fixed-ratio box to pick
                            // what to keep; no other shape is possible.
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['4:3'])
                            ->imageEditorViewportWidth(320)
                            ->imageEditorViewportHeight(240)
                            ->disk(\App\Models\Photo::DISK)
                            ->visibility('private')
                            ->directory('photos')
                            ->maxSize(15360)
                            ->helperText('Shown on a 4:3 (320×240) screen. If your photo isn’t 4:3, click the pencil to choose the part to keep.')
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
            // Responsive layout: a single row on desktop (md+), a stacked card on mobile.
            ->columns([
                Split::make([
                    Tables\Columns\ViewColumn::make('image_path')
                        ->label('Preview')
                        ->view('tables.columns.photo-lightbox')
                        ->grow(false),
                    Stack::make([
                        Tables\Columns\TextColumn::make('caption')
                            ->label('Caption')
                            ->placeholder('No caption')
                            ->weight(FontWeight::Medium)
                            ->wrap()
                            ->searchable()
                            ->limit(50),
                        Tables\Columns\TextColumn::make('programs_count')
                            ->label('Programs')
                            ->counts('programs')
                            ->badge()
                            ->icon('heroicon-m-play-circle')
                            ->color('gray'),
                        Tables\Columns\TextColumn::make('user.name')
                            ->label('Owner')
                            ->icon('heroicon-m-user')
                            ->color('gray')
                            ->visible(fn () => auth()->user()->is_admin),
                        Tables\Columns\TextColumn::make('created_at')
                            ->label('Uploaded')
                            ->since()
                            ->icon('heroicon-m-clock')
                            ->color('gray')
                            ->sortable(),
                    ]),
                ])->from('md'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->toolbarActions([
                Actions\BulkActionGroup::make([
                    Actions\DeleteBulkAction::make(),
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
