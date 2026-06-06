import preset from '../../../../vendor/filament/filament/tailwind.config.preset'

export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './app/Livewire/**/*.php',
        './resources/views/filament/**/*.blade.php',
        // Custom blades that use Tailwind utilities (calendar, lightbox, etc.)
        './resources/views/livewire/**/*.blade.php',
        './resources/views/tables/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
}
