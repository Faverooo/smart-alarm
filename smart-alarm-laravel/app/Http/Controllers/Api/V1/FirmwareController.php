<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class FirmwareController extends Controller
{
    /**
     * OTA update check.
     *
     * The ESP sends its running version as `?current=1.3.0`. If config holds a
     * higher version we tell it where to download the new .bin. Server-driven via
     * config/services.php (`FIRMWARE_VERSION` / `FIRMWARE_URL`) so rolling out a
     * build needs no code change.
     */
    public function latest(Request $request): JsonResponse
    {
        $current = (string) $request->query('current', '');
        $latest  = config('services.firmware.version');
        $url     = config('services.firmware.url');

        $available = $latest !== null
            && $url !== null
            && ($current === '' || version_compare($latest, $current, '>'));

        return response()->json([
            'version'          => $latest,
            'url'              => $available ? $url : null,
            // md5sum of the .bin — the device refuses to flash a download
            // that doesn't match it.
            'md5'              => $available ? $this->firmwareMd5($url) : null,
            'update_available' => $available,
            'mandatory'        => $available && (bool) config('services.firmware.mandatory', false),
        ]);
    }

    /**
     * The device downloads-then-rejects on a hash mismatch and retries every
     * check cycle, so a stale FIRMWARE_MD5 (URL updated, hash not) becomes a
     * silent download-and-fail loop on every device. When the .bin is served
     * from our own public/ dir, hash the actual file (cached by mtime) and
     * prefer that; the env value is only trusted for externally hosted builds.
     */
    private function firmwareMd5(string $url): ?string
    {
        $configured = config('services.firmware.md5') ?: null;

        $app = parse_url((string) config('app.url'));
        $bin = parse_url($url);
        if (! isset($bin['host']) || strcasecmp($bin['host'], $app['host'] ?? '') !== 0) {
            return $configured;   // external host — the env hash is all we have
        }

        // URL path → public/ path, stripping the app's base path when it's
        // deployed under a sub-directory.
        $rel  = rawurldecode($bin['path'] ?? '');
        $base = rtrim($app['path'] ?? '', '/');
        if ($base !== '' && str_starts_with($rel, $base)) {
            $rel = substr($rel, strlen($base));
        }
        $path = realpath(public_path(ltrim($rel, '/')));
        if ($path === false
            || ! is_file($path)
            || ! str_starts_with($path, realpath(public_path()).DIRECTORY_SEPARATOR)) {
            return $configured;
        }

        $computed = Cache::remember(
            'firmware.md5:'.$path.':'.filemtime($path),
            now()->addWeek(),
            fn () => md5_file($path) ?: null,
        );

        if ($computed !== null && $configured !== null && strcasecmp($computed, $configured) !== 0) {
            Log::warning('FIRMWARE_MD5 does not match the hosted .bin — serving the computed hash instead.', [
                'configured' => $configured,
                'computed'   => $computed,
                'file'       => $path,
            ]);
        }

        return $computed ?? $configured;
    }
}
