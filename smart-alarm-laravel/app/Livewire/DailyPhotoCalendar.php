<?php

namespace App\Livewire;

use App\Filament\Resources\PhotoResource\Pages\CreatePhoto;
use App\Models\Photo;
use App\Models\Program;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class DailyPhotoCalendar extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    public Program $program;

    // 'month' | 'week'
    public string $view = 'month';

    // Any date within the period being shown, as 'Y-m-d'.
    public string $cursor;

    public function mount(Program $program): void
    {
        $this->program = $program;
        $this->cursor = now()->toDateString();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['month', 'week'], true) ? $view : 'month';
    }

    public function goToday(): void
    {
        $this->cursor = now()->toDateString();
    }

    public function previous(): void
    {
        $this->cursor = $this->shift(-1);
    }

    public function next(): void
    {
        $this->cursor = $this->shift(1);
    }

    protected function shift(int $direction): string
    {
        $date = Carbon::parse($this->cursor);

        return ($this->view === 'week'
            ? $date->addWeeks($direction)
            : $date->addMonthsNoOverflow($direction)
        )->toDateString();
    }

    protected function weekStart(): Carbon
    {
        return Carbon::parse($this->cursor)->startOfWeek(CarbonInterface::MONDAY);
    }

    protected function monthStart(): Carbon
    {
        return Carbon::parse($this->cursor)->startOfMonth();
    }

    // Per-request memo for photosByDate() — buildCell() runs once per calendar
    // cell, so without this a month render fires ~35 identical pivot queries.
    // Protected, so Livewire never serializes it; reset after pivot mutations.
    protected ?\Illuminate\Support\Collection $photosByDateMemo = null;

    // Photos with a pinned date, keyed by 'Y-m-d'.
    protected function photosByDate()
    {
        return $this->photosByDateMemo ??= $this->program->photos()
            ->wherePivotNotNull('scheduled_for')
            ->get()
            ->keyBy(fn (Photo $p) => Carbon::parse($p->pivot->scheduled_for)->format('Y-m-d'));
    }

    protected function buildCell(?Carbon $date, bool $inPeriod = true): ?array
    {
        if ($date === null) {
            return null;
        }

        $key = $date->format('Y-m-d');
        $photo = $this->photosByDate()->get($key);

        return [
            'date'     => $key,
            'day'      => $date->day,
            'weekday'  => $date->isoFormat('ddd'),
            'isToday'  => $date->isToday(),
            'inPeriod' => $inPeriod,
            'photo'    => $photo ? [
                'id'      => $photo->id,
                'url'     => $photo->url,
                'caption' => $photo->caption,
            ] : null,
        ];
    }

    // Month view: rows of 7 cells (null = padding).
    public function getWeeksProperty(): array
    {
        $start = $this->monthStart();
        $leading = $start->dayOfWeekIso - 1;

        $cells = array_fill(0, $leading, null);

        for ($d = 1; $d <= $start->daysInMonth; $d++) {
            $cells[] = $this->buildCell($start->copy()->day($d));
        }

        while (count($cells) % 7 !== 0) {
            $cells[] = null;
        }

        return array_chunk($cells, 7);
    }

    // Week view: the 7 days of the current week.
    public function getDaysProperty(): array
    {
        $start = $this->weekStart();

        return collect(range(0, 6))
            ->map(fn (int $i) => $this->buildCell($start->copy()->addDays($i)))
            ->all();
    }

    public function getPeriodLabelProperty(): string
    {
        if ($this->view === 'week') {
            $start = $this->weekStart();
            $end = $start->copy()->addDays(6);

            return $start->isSameMonth($end)
                ? $start->format('j').' – '.$end->format('j M Y')
                : $start->format('j M').' – '.$end->format('j M Y');
        }

        return $this->monthStart()->translatedFormat('F Y');
    }

    // Existing photos the user can attach, rendered with thumbnails.
    protected function existingPhotoOptions(): array
    {
        $user = auth()->user();
        $photos = $user->is_admin ? Photo::query()->latest()->get() : $user->photos()->latest()->get();

        return $photos->mapWithKeys(function (Photo $p) {
            $url = $p->url;
            $label = '<img src="'.e($url).'" '
                .'style="height:36px;width:48px;object-fit:cover;border-radius:6px;'
                .'display:inline-block;margin-right:8px;vertical-align:middle">'
                .e($p->caption ?: 'Photo #'.$p->id);

            return [$p->id => $label];
        })->toArray();
    }

    // Add / replace the photo for a day — upload a new one or attach an existing one.
    public function setDayAction(): Action
    {
        return Action::make('setDay')
            ->modalHeading(fn (array $arguments) => 'Photo for '.Carbon::parse($this->validDate($arguments))->isoFormat('dddd D MMMM YYYY'))
            ->modalWidth('lg')
            ->modalSubmitActionLabel('Save photo')
            ->fillForm(fn (array $arguments) => [
                'source'  => 'upload',
                'caption' => optional($this->photosByDate()->get($this->validDate($arguments)))->caption,
            ])
            ->schema([
                Forms\Components\ToggleButtons::make('source')
                    ->label('Add a photo by')
                    ->options([
                        'upload'   => 'Uploading new',
                        'existing' => 'Choosing existing',
                    ])
                    ->icons([
                        'upload'   => 'heroicon-o-arrow-up-tray',
                        'existing' => 'heroicon-o-photo',
                    ])
                    ->default('upload')
                    ->inline()
                    ->live(),

                Forms\Components\FileUpload::make('image')
                    ->label('Image')
                    ->image()
                    // Live 4:3 crop selector locked to the 320×240 screen.
                    ->imageEditor()
                    ->imageEditorAspectRatioOptions(['4:3'])
                    ->imageEditorViewportWidth(320)
                    ->imageEditorViewportHeight(240)
                    ->disk(Photo::DISK)
                    ->visibility('private')
                    ->directory('photos')
                    ->maxSize(10240)
                    ->helperText('Shown on a 4:3 (320x240) screen — use the pencil to crop.')
                    ->visible(fn (Get $get) => $get('source') === 'upload')
                    ->requiredIf('source', 'upload'),

                Forms\Components\TextInput::make('caption')
                    ->label('Caption (optional)')
                    ->maxLength(255)
                    ->visible(fn (Get $get) => $get('source') === 'upload'),

                Forms\Components\Select::make('photo_id')
                    ->label('Existing photo')
                    ->options($this->existingPhotoOptions())
                    ->allowHtml()
                    ->searchable()
                    ->visible(fn (Get $get) => $get('source') === 'existing')
                    ->requiredIf('source', 'existing'),
            ])
            ->action(function (array $data, array $arguments): void {
                $date = $this->validDate($arguments);

                // One photo per day — clear whatever is currently pinned there
                $this->detachDate($date);

                if (($data['source'] ?? 'upload') === 'existing') {
                    if (! empty($data['photo_id'])) {
                        $this->program->photos()->attach($data['photo_id'], [
                            'scheduled_for' => $date,
                            'is_shown'      => false,
                        ]);
                    }
                } else {
                    $photo = Photo::create([
                        'user_id'    => auth()->id(),
                        'image_path' => $data['image'],
                        'caption'    => $data['caption'] ?? null,
                    ]);
                    CreatePhoto::resizeForEsp32($photo);

                    $this->program->photos()->attach($photo->id, [
                        'scheduled_for' => $date,
                        'is_shown'      => false,
                    ]);
                }

                $this->photosByDateMemo = null;   // pivot changed → re-read on render
            });
    }

    public function removeDayAction(): Action
    {
        return Action::make('removeDay')
            ->requiresConfirmation()
            ->modalHeading(fn (array $arguments) => 'Remove photo for '.Carbon::parse($this->validDate($arguments))->isoFormat('D MMMM YYYY').'?')
            ->modalSubmitActionLabel('Remove')
            ->action(fn (array $arguments) => $this->detachDate($this->validDate($arguments)));
    }

    // The `date` action argument comes from the browser (wire:click), so never
    // trust it: anything but a plain Y-m-d would end up raw in Carbon::parse
    // and in the pivot insert. Applied in EVERY closure that touches
    // $arguments['date'] (modalHeading/fillForm included — they run at modal
    // mount, before any form validation).
    protected function validDate(array $arguments): string
    {
        $date = (string) ($arguments['date'] ?? '');

        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1, 422);

        return $date;
    }

    // Remove only the pivot row for THIS day. Detaching by photo id would wipe the
    // same photo from every other day it's pinned to, so delete by date instead.
    protected function detachDate(string $date): void
    {
        $relation = $this->program->photos();

        DB::table($relation->getTable())
            ->where($relation->getForeignPivotKeyName(), $this->program->getKey())
            ->where('scheduled_for', $date)
            ->delete();

        $this->photosByDateMemo = null;   // pivot changed → re-read on render
    }

    public function render()
    {
        return view('livewire.daily-photo-calendar');
    }
}
