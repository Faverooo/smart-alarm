<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Does the request's If-None-Match header match our ETag?
     *
     * Tolerates proxies (e.g. Cloudflare) that weaken the ETag to `W/"..."`
     * when they re-compress the response — the device echoes back whatever it
     * received, so a strict string compare would silently disable every 304.
     */
    protected function ifNoneMatchMatches(Request $request, string $etag): bool
    {
        $header = trim((string) $request->headers->get('If-None-Match', ''));
        $header = preg_replace('#^W/#i', '', $header);

        return trim($header, '"') === $etag;
    }
}
