<?php

namespace App\Filament\Resources\ProgramResource\RelationManagers;

use App\Filament\Resources\PhotoResource\Pages\CreatePhoto;
use App\Models\Photo;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage; // kept for potential future use

class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';
    protected static ?string $recordTitleAttribute = 'caption';

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('caption')->maxLength(255),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')
                    ->label('Preview')
                    ->disk('public')
                    ->square()
                    ->size(56),
                Tables\Columns\TextColumn::make('caption')
                    ->label('Caption')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('pivot.sort_order')
                    ->label('Order')
                    ->sortable(),
                Tables\Columns\TextColumn::make('pivot.scheduled_for')
                    ->label('Show On')
                    ->date('d/m/Y')
                    ->placeholder('Any day'),
                Tables\Columns\IconColumn::make('pivot.is_shown')
                    ->label('Shown')
                    ->boolean(),
            ])
            ->reorderable('sort_order')
            ->headerActions([
                // Attach an existing photo
                Tables\Actions\Action::make('attach')
                    ->label('Attach Existing Photo')
                    ->icon('heroicon-o-link')
                    ->modalHeading('Attach a Photo to this Program')
                    ->form(function () {
                        $user = auth()->user();
                        $photos = $user->is_admin
                            ? Photo::all()
                            : $user->photos()->get();

                        $options = $photos->mapWithKeys(
                            fn ($p) => [$p->id => $p->caption ?? "Photo #{$p->id}"]
                        )->toArray();

                        return [
                            Forms\Components\Select::make('photo_id')
                                ->label('Photo')
                                ->options($options)
                                ->searchable()
                                ->required(),
                            Forms\Components\TextInput::make('sort_order')
                                ->label('Sort Order')
                                ->numeric()
                                ->default(0),
                            Forms\Components\DatePicker::make('scheduled_for')
                                ->label('Show On Date')
                                ->helperText('Optional: pin this photo to a specific date (e.g. a birthday).'),
                        ];
                    })
                    ->action(function (array $data): void {
                        $this->getOwnerRecord()->photos()->attach($data['photo_id'], [
                            'sort_order'    => $data['sort_order'] ?? 0,
                            'scheduled_for' => $data['scheduled_for'] ?? null,
                            'is_shown'      => false,
                        ]);
                    }),

                // Upload a new photo and attach it in one step
                Tables\Actions\Action::make('upload')
                    ->label('Upload & Attach New Photo')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->modalHeading('Upload a New Photo')
                    ->form([
                        Forms\Components\FileUpload::make('image_path')
                            ->label('Image (auto-resized to 320×240 on save)')
                            ->image()
                            ->disk('public')
                            ->directory('photos')
                            ->maxSize(10240)
                            ->required(),
                        Forms\Components\TextInput::make('caption')
                            ->label('Caption')
                            ->maxLength(255),
                        Forms\Components\TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->default(0),
                        Forms\Components\DatePicker::make('scheduled_for')
                            ->label('Show On Date')
                            ->helperText('Optional: pin this photo to a specific date.'),
                    ])
                    ->action(function (array $data): void {
                        $photo = Photo::create([
                            'user_id'    => auth()->id(),
                            'image_path' => $data['image_path'],
                            'caption'    => $data['caption'] ?? null,
                        ]);

                        // Resize for ESP32 display (requires php-gd)
                        CreatePhoto::resizeForEsp32($photo);

                        $this->getOwnerRecord()->photos()->attach($photo->id, [
                            'sort_order'    => $data['sort_order'] ?? 0,
                            'scheduled_for' => $data['scheduled_for'] ?? null,
                            'is_shown'      => false,
                        ]);
                    }),
            ])
            ->actions([
                Tables\Actions\Action::make('editPivot')
                    ->label('Edit Order/Date')
                    ->icon('heroicon-o-pencil')
                    ->modalHeading('Edit Photo Settings in this Program')
                    ->form(function (Photo $record): array {
                        $pivot = $this->getOwnerRecord()
                            ->photos()
                            ->where('photo_id', $record->id)
                            ->first()?->pivot;

                        return [
                            Forms\Components\TextInput::make('sort_order')
                                ->numeric()
                                ->default($pivot?->sort_order ?? 0),
                            Forms\Components\DatePicker::make('scheduled_for')
                                ->label('Show On Date')
                                ->default($pivot?->scheduled_for),
                            Forms\Components\Toggle::make('is_shown')
                                ->label('Already Shown')
                                ->default($pivot?->is_shown ?? false),
                        ];
                    })
                    ->action(function (Photo $record, array $data): void {
                        $this->getOwnerRecord()->photos()->updateExistingPivot($record->id, [
                            'sort_order'    => $data['sort_order'] ?? 0,
                            'scheduled_for' => $data['scheduled_for'] ?? null,
                            'is_shown'      => $data['is_shown'] ?? false,
                        ]);
                    }),
                Tables\Actions\Action::make('detach')
                    ->label('Remove')
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (Photo $record) => $this->getOwnerRecord()->photos()->detach($record->id)),
            ]);
    }
}
