<?php

namespace App\Filament\Resources\ProgramResource\RelationManagers;

use App\Filament\Resources\PhotoResource\Pages\CreatePhoto;
use App\Models\Photo;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PhotosRelationManager extends RelationManager
{
    protected static string $relationship = 'photos';

    // Photos are identified visually by their preview, not by a name.
    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $title = 'Photos';

    // Daily programs use the calendar embedded in the form instead of this table.
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->type !== 'daily';
    }

    // How many more photos this program can still accept (read fresh so a saved
    // type change — e.g. rotation -> fixed — is respected immediately).
    protected function remainingSlots(): int
    {
        $program = $this->getOwnerRecord()->fresh();

        return max(0, $program->photoLimit() - $program->photos()->count());
    }

    public function table(Table $table): Table
    {
        $program = $this->getOwnerRecord();
        $isFixed = $program->type === 'fixed';
        $isDaily = $program->type === 'daily';

        return $table
            // Responsive layout: a single row on desktop (md+), a stacked card on mobile.
            ->columns([
                Split::make([
                    Tables\Columns\ViewColumn::make('image_path')
                        ->label('Preview')
                        ->view('tables.columns.photo-lightbox')
                        ->grow(false),
                    Tables\Columns\TextColumn::make('caption')
                        ->label('Caption')
                        ->placeholder('No caption')
                        ->weight(FontWeight::Medium)
                        ->wrap(),
                ])->from('md'),
            ])
            ->emptyStateHeading($isFixed ? 'No photo set yet' : 'No photos yet')
            ->emptyStateDescription(
                $isFixed
                    ? 'Upload the single photo this program should always show.'
                    : 'Upload up to ' . $program->photoLimit() . ' photos for this program.'
            )
            ->headerActions([
                // Upload one or more new photos straight into this program
                Actions\Action::make('uploadPhotos')
                    ->label($isFixed ? 'Set Photo' : 'Upload Photos')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('success')
                    ->visible(fn () => $this->remainingSlots() > 0)
                    ->modalHeading($isFixed ? 'Set the Program Photo' : 'Upload Photos')
                    ->schema(fn () => [
                        Forms\Components\FileUpload::make('images')
                            ->label($isFixed ? 'Image' : 'Images')
                            ->image()
                            // Live 4:3 crop selector locked to the 320×240 screen.
                            ->imageEditor()
                            ->imageEditorAspectRatioOptions(['4:3'])
                            ->imageEditorViewportWidth(320)
                            ->imageEditorViewportHeight(240)
                            ->multiple(! $isFixed)
                            ->maxFiles($this->remainingSlots())
                            ->disk(Photo::DISK)
                            ->visibility('private')
                            ->directory('photos')
                            ->maxSize(10240)
                            ->helperText('Shown on a 4:3 (320×240) screen — use the pencil to crop. '
                                . ($isFixed ? '' : 'Up to ' . $this->remainingSlots() . ' more photo(s).'))
                            ->required(),
                        Forms\Components\TextInput::make('caption')
                            ->label('Caption (optional)')
                            ->maxLength(255)
                            ->helperText($isFixed ? null : 'Applied to every photo in this upload — edit them individually afterwards.'),
                    ])
                    ->action(function (array $data): void {
                        $remaining = $this->remainingSlots();

                        if ($remaining < 1) {
                            $this->limitReachedNotification();

                            return;
                        }

                        $paths = array_slice((array) $data['images'], 0, $remaining);

                        foreach ($paths as $path) {
                            $photo = Photo::create([
                                'user_id'    => auth()->id(),
                                'image_path' => $path,
                                'caption'    => $data['caption'] ?? null,
                            ]);

                            CreatePhoto::resizeForEsp32($photo);

                            $this->getOwnerRecord()->photos()->attach($photo->id, ['is_shown' => false]);
                        }
                    }),

                // Attach a photo the user already uploaded elsewhere — shown with previews
                Actions\Action::make('attachExisting')
                    ->label('Attach Existing')
                    ->icon('heroicon-o-link')
                    ->visible(fn () => $this->remainingSlots() > 0 && count($this->attachableOptions()) > 0)
                    ->modalHeading('Attach Existing Photos')
                    ->schema(fn () => [
                        // Always a multi-select (capped) so the value is consistently an
                        // array — a single-value select tripped "must be an array".
                        Forms\Components\Select::make('photo_ids')
                            ->label($isFixed ? 'Photo' : 'Photos')
                            ->multiple()
                            ->options($this->attachableOptions())
                            ->allowHtml()
                            ->searchable()
                            ->maxItems($this->remainingSlots())
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        $remaining = $this->remainingSlots();

                        if ($remaining < 1) {
                            $this->limitReachedNotification();

                            return;
                        }

                        $ids = array_slice((array) $data['photo_ids'], 0, $remaining);

                        foreach ($ids as $photoId) {
                            $this->getOwnerRecord()->photos()->attach($photoId, ['is_shown' => false]);
                        }
                    }),
            ])
            ->recordActions([
                // Edit a photo's caption (and, for daily programs, the day it shows)
                Actions\Action::make('editPhoto')
                    ->label('Edit')
                    ->icon('heroicon-o-pencil')
                    ->button()
                    ->outlined()
                    ->modalHeading('Edit Photo')
                    ->fillForm(fn (Photo $record) => [
                        'caption'       => $record->caption,
                        'scheduled_for' => $this->getOwnerRecord()->photos()
                            ->where('photo_id', $record->id)
                            ->first()?->pivot?->scheduled_for,
                    ])
                    ->schema([
                        Forms\Components\TextInput::make('caption')
                            ->label('Caption')
                            ->maxLength(255),
                        Forms\Components\DatePicker::make('scheduled_for')
                            ->label('Show On')
                            ->helperText('Leave empty to let the device pick automatically.')
                            ->visible($isDaily),
                    ])
                    ->action(function (Photo $record, array $data) use ($isDaily): void {
                        $record->update(['caption' => $data['caption'] ?? null]);

                        if ($isDaily) {
                            $this->getOwnerRecord()->photos()->updateExistingPivot($record->id, [
                                'scheduled_for' => $data['scheduled_for'] ?? null,
                            ]);
                        }
                    }),

                // Remove the photo from this program (custom name avoids the built-in
                // DetachAction "detach" collision, which made the button disappear)
                Actions\Action::make('removePhoto')
                    ->label('Remove')
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->button()
                    ->requiresConfirmation()
                    ->modalHeading('Remove this photo from the program?')
                    ->action(fn (Photo $record) => $this->getOwnerRecord()->photos()->detach($record->id)),
            ]);
    }

    protected function limitReachedNotification(): void
    {
        Notification::make()
            ->title('Photo limit reached')
            ->body('This program type does not allow any more photos. Remove one first.')
            ->warning()
            ->send();
    }

    // Build <img> option labels so the picker shows previews instead of names.
    protected function attachableOptions(): array
    {
        $program = $this->getOwnerRecord();
        $user = auth()->user();

        $query = $user->is_admin ? Photo::query() : $user->photos();
        $attachedIds = $program->photos()->pluck('photos.id')->all();

        return $query->latest()
            ->get()
            ->reject(fn (Photo $p) => in_array($p->id, $attachedIds))
            ->mapWithKeys(function (Photo $p) {
                $url = $p->url;
                $caption = $p->caption ? ' ' . e($p->caption) : ' Photo #' . $p->id;
                $label = '<img src="' . e($url) . '" '
                    . 'style="height:40px;width:40px;object-fit:cover;border-radius:6px;'
                    . 'display:inline-block;margin-right:8px;vertical-align:middle">' . $caption;

                return [$p->id => $label];
            })
            ->toArray();
    }
}
