@php($showWeekday = $showWeekday ?? false)

@if ($cell === null)
    <div class="spc-day"></div>
@else
    <div
        wire:key="cell-{{ $cell['date'] }}"
        @class([
            'spc-cell spc-day group overflow-hidden rounded-xl border transition',
            'border-transparent' => $cell['photo'],
            'border-dashed border-gray-300 hover:border-primary-400 dark:border-white/15 dark:hover:border-primary-500' => ! $cell['photo'],
            'ring-2 ring-primary-500 ring-offset-2 ring-offset-white dark:ring-offset-gray-900' => $cell['isToday'],
        ])
    >
        {{-- Tap anywhere to add / replace --}}
        <button
            type="button"
            wire:click="mountAction('setDay', { date: '{{ $cell['date'] }}' })"
            class="absolute inset-0 flex h-full w-full items-center justify-center focus:outline-none"
        >
            @if ($cell['photo'])
                <img src="{{ $cell['photo']['url'] }}" class="absolute inset-0 h-full w-full object-cover" loading="lazy" />
                <span class="absolute inset-x-0 top-0 h-12 bg-gradient-to-b from-black/55 to-transparent"></span>
            @endif

            <span class="absolute left-2 top-1.5 z-10 flex flex-col leading-none">
                @if ($showWeekday)
                    <span @class([
                        'text-[10px] font-semibold uppercase tracking-wider',
                        'text-white/80' => $cell['photo'],
                        'text-gray-400 dark:text-gray-500' => ! $cell['photo'],
                    ])>{{ $cell['weekday'] }}</span>
                @endif

                <span @class([
                    'spc-num text-xl font-semibold',
                    'text-white drop-shadow' => $cell['photo'],
                    'text-gray-700 dark:text-gray-200' => ! $cell['photo'],
                ])>{{ $cell['day'] }}</span>
            </span>

            @unless ($cell['photo'])
                <span class="flex h-9 w-9 items-center justify-center rounded-full bg-gray-100 text-gray-400 transition group-hover:scale-110 group-hover:bg-primary-500 group-hover:text-white dark:bg-white/5 dark:text-gray-500">
                    <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M10 4a1 1 0 0 1 1 1v4h4a1 1 0 1 1 0 2h-4v4a1 1 0 1 1-2 0v-4H5a1 1 0 1 1 0-2h4V5a1 1 0 0 1 1-1Z"/></svg>
                </span>
            @endunless
        </button>

        @if ($cell['photo'])
            <button
                type="button"
                wire:click="mountAction('removeDay', { date: '{{ $cell['date'] }}' })"
                title="Remove photo"
                class="absolute bottom-1.5 right-1.5 z-20 flex h-10 w-10 items-center justify-center rounded-full bg-gray-950/60 text-white backdrop-blur-sm transition hover:scale-110 hover:bg-red-600 sm:h-8 sm:w-8"
            >
                <svg class="h-6 w-6 sm:h-5 sm:w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M8.75 1.5a1.25 1.25 0 0 0-1.18.84L7.2 3.5H4a.75.75 0 0 0 0 1.5h12a.75.75 0 0 0 0-1.5h-3.2l-.37-1.16a1.25 1.25 0 0 0-1.18-.84h-2.5ZM5.5 6.5l.6 9a1.5 1.5 0 0 0 1.5 1.4h4.8a1.5 1.5 0 0 0 1.5-1.4l.6-9H5.5Z" clip-rule="evenodd"/></svg>
            </button>
        @endif
    </div>
@endif
