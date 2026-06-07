<?php

namespace App\Providers;

use App\Models\Photo;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The photos disk is private (no public URL). Filament's FileUpload asks
        // a private disk for a temporaryUrl() to render existing-file previews;
        // point that at our session-gated streaming route instead of a signed
        // public link, so previews stay behind the panel login.
        Storage::disk(Photo::DISK)->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options = []) => route('photos.serve', ['path' => $path]),
        );

        // API rate limiters. We can't use the default `throttle:n,m` because it
        // keys by $request->user()->getAuthIdentifier(), and our Device isn't an
        // Authenticatable. So key by the bearer token (per-device) or IP instead.
        RateLimiter::for('pair', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(120)
            ->by($request->bearerToken() ?: $request->ip()));
    }
}
