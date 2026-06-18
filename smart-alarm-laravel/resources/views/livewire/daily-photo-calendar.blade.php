<div class="spc-cal">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">

    <style>
        .spc-cal .spc-num { font-family: 'Fraunces', ui-serif, Georgia, serif; font-variation-settings: 'opsz' 40; letter-spacing: -0.01em; }
        .spc-cal .spc-title { font-family: 'Fraunces', ui-serif, Georgia, serif; font-variation-settings: 'opsz' 144; letter-spacing: -0.02em; }
        .spc-cal .spc-cell { box-shadow: 0 1px 2px rgb(0 0 0 / 0.04); }
        .spc-cal .spc-seg-active { box-shadow: 0 1px 3px rgb(0 0 0 / 0.12); }
        /* Filament's precompiled CSS ships only the utilities it uses itself, so the
           calendar grid + square cells are defined here to be fully deterministic. */
        .spc-cal .spc-day { position: relative; aspect-ratio: 1 / 1; }
        @supports not (aspect-ratio: 1 / 1) { .spc-cal .spc-day { height: 120px; } }
        .spc-cal .spc-grid7 { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); gap: 0.5rem; }
        .spc-cal .spc-week { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; }
        @media (min-width: 640px) { .spc-cal .spc-week { grid-template-columns: repeat(7, minmax(0, 1fr)); } }
        @media (max-width: 639px) { .spc-cal .spc-num { font-size: 1rem; } }
        /* "Saved instantly" notice — scoped colors so it works without a theme rebuild. */
        .spc-cal .spc-note { border: 1px solid rgb(253 230 138); background: rgb(255 251 235); color: rgb(146 64 14); }
        .dark .spc-cal .spc-note { border-color: rgba(251, 191, 36, 0.25); background: rgba(251, 191, 36, 0.10); color: rgb(253 230 138); }
    </style>

    {{-- ─── Toolbar ─────────────────────────────────────────────── --}}
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-1">
            <button type="button" wire:click="previous"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.79 5.23a.75.75 0 0 1 0 1.06L9.06 10l3.73 3.71a.75.75 0 1 1-1.06 1.06l-4.25-4.24a.75.75 0 0 1 0-1.06l4.25-4.24a.75.75 0 0 1 1.06 0Z" clip-rule="evenodd"/></svg>
            </button>

            <h3 class="spc-title min-w-[10rem] text-center text-2xl font-semibold text-gray-950 dark:text-white">
                {{ $this->periodLabel }}
            </h3>

            <button type="button" wire:click="next"
                class="flex h-9 w-9 items-center justify-center rounded-lg text-gray-500 transition hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-white/5">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 0 1 0-1.06L10.94 10 7.21 6.29a.75.75 0 0 1 1.06-1.06l4.25 4.24a.75.75 0 0 1 0 1.06l-4.25 4.24a.75.75 0 0 1-1.06 0Z" clip-rule="evenodd"/></svg>
            </button>
        </div>

        <div class="flex items-center gap-2">
            <button type="button" wire:click="goToday"
                class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-sm font-medium text-gray-700 transition hover:bg-gray-50 dark:border-white/10 dark:bg-white/5 dark:text-gray-200 dark:hover:bg-white/10">
                Today
            </button>

            {{-- Month / Week segmented control --}}
            <div class="flex rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                @foreach (['month' => 'Month', 'week' => 'Week'] as $value => $label)
                    <button type="button" wire:click="setView('{{ $value }}')"
                        @class([
                            'rounded-md px-3 py-1 text-sm font-medium transition',
                            'spc-seg-active bg-white text-primary-600 dark:bg-gray-700 dark:text-white' => $this->view === $value,
                            'text-gray-500 hover:text-gray-700 dark:text-gray-400 dark:hover:text-gray-200' => $this->view !== $value,
                        ])>
                        {{ $label }}
                    </button>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Immediacy notice: the calendar commits straight away (like a relation
         manager), so the page's Save/Cancel below don't govern it. --}}
    <div class="spc-note" style="display:flex;align-items:flex-start;gap:0.5rem;border-radius:0.5rem;padding:0.75rem 1rem;margin-bottom:1.25rem;font-size:0.875rem;line-height:1.3rem;">
        <svg viewBox="0 0 20 20" fill="currentColor" style="flex:none;height:1.25rem;width:1.25rem;margin-top:0.05rem;opacity:0.8;">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .244.304l-.459 2.066A1.75 1.75 0 0 0 10.747 15H11a.75.75 0 0 0 0-1.5h-.253a.25.25 0 0 1-.244-.304l.459-2.066A1.75 1.75 0 0 0 9.253 9H9Z" clip-rule="evenodd"/>
        </svg>
        <span>Photos here are saved <strong>the moment you add them</strong> — there’s no Save button to press. To remove a day’s photo, click the <strong>✕</strong> on that day.</span>
    </div>

    {{-- ─── Month view ──────────────────────────────────────────── --}}
    @if ($this->view === 'month')
        <div class="spc-grid7 mb-2 text-center text-xs font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $weekday)
                <div>{{ $weekday }}</div>
            @endforeach
        </div>

        <div class="space-y-2">
            @foreach ($this->weeks as $week)
                <div class="spc-grid7">
                    @foreach ($week as $cell)
                        @include('livewire.partials.calendar-day', ['cell' => $cell, 'showWeekday' => false])
                    @endforeach
                </div>
            @endforeach
        </div>

    {{-- ─── Week view (2-up on mobile, 7 across on desktop) ─────── --}}
    @else
        <div class="spc-week">
            @foreach ($this->days as $cell)
                @include('livewire.partials.calendar-day', ['cell' => $cell, 'showWeekday' => true])
            @endforeach
        </div>
    @endif

    {{-- Hint --}}
    <p class="mt-4 text-sm text-gray-500 dark:text-gray-400">
        Tap a day to upload or attach a photo. The device shows that day's photo for the whole day.
    </p>

    {{-- Filament v5 renders action modals via a `wire:partial`; wrapping it in a
         manual <template x-teleport> detaches that partial from Livewire's morph
         tree, so the modal mounts server-side (200) but never appears. Render it
         inline exactly as stock Filament pages do — the modal handles its own
         layering, so the page form is unaffected. --}}
    <x-filament-actions::modals />
</div>
