@php
    $record = $getRecord();
    $url = $record?->url; // session-gated streaming route (see Photo::getUrlAttribute)
    $caption = $record?->caption;
@endphp

@if ($url)
    <div x-data="{ open: false }" class="inline-block">
        {{-- Thumbnail (click to expand) --}}
        <button
            type="button"
            x-on:click.stop.prevent="open = true"
            class="group block overflow-hidden rounded-lg ring-1 ring-gray-950/5 dark:ring-white/10 focus:outline-none focus:ring-2 focus:ring-primary-500"
        >
            <img
                src="{{ $url }}"
                alt="{{ $caption ?? 'Photo preview' }}"
                class="h-16 w-20 object-cover transition duration-200 group-hover:scale-105"
                loading="lazy"
            />
        </button>

        {{-- Full-screen lightbox, teleported to <body> to escape table overflow --}}
        <template x-teleport="body">
            <div
                x-show="open"
                x-transition.opacity.duration.200ms
                x-on:keydown.escape.window="open = false"
                x-on:click="open = false"
                class="flex items-center justify-center"
                style="display: none; position: fixed; inset: 0; z-index: 60; padding: 1.5rem; background: rgba(2, 6, 23, 0.82); backdrop-filter: blur(4px);"
            >
                <figure
                    x-on:click.stop
                    x-show="open"
                    x-transition.scale.95.duration.200ms
                    class="relative flex max-h-full max-w-full flex-col items-center"
                >
                    <img
                        src="{{ $url }}"
                        alt="{{ $caption ?? 'Photo' }}"
                        class="rounded-2xl shadow-2xl ring-1 ring-white/10"
                        style="max-height: 82vh; max-width: 92vw;"
                    />

                    @if ($caption)
                        <figcaption class="mt-4 rounded-full px-4 py-1.5 text-center text-sm font-medium text-white" style="background: rgba(255, 255, 255, 0.14);">
                            {{ $caption }}
                        </figcaption>
                    @endif

                    <button
                        type="button"
                        x-on:click="open = false"
                        class="absolute -right-2 -top-2 flex h-9 w-9 items-center justify-center rounded-full bg-white text-gray-900 shadow-lg ring-1 ring-gray-950/10 transition hover:scale-110 hover:bg-gray-100"
                        aria-label="Close"
                    >
                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z" clip-rule="evenodd"/></svg>
                    </button>
                </figure>
            </div>
        </template>
    </div>
@else
    <span class="text-sm text-gray-400">—</span>
@endif
