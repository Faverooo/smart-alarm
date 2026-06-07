<?php

namespace App\Http\Controllers;

use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PhotoFileController extends Controller
{
    /**
     * Stream a private photo to the Filament panel.
     *
     * Auth is the panel session cookie (the `auth` web guard — see the route).
     * Authorization: if the path belongs to a saved Photo, only its owner (or an
     * admin) may view it. A path with no Photo row yet is a just-uploaded temp
     * file in the create form, so any authenticated panel user may preview it.
     *
     * The ESP never uses this route — it pulls images from the token-gated
     * /api/v1/photos/{id}/render endpoint instead.
     */
    public function __invoke(Request $request): StreamedResponse
    {
        $path = (string) $request->query('path', '');

        // Hard path-traversal guard: only ever serve files under photos/.
        abort_unless(preg_match('#^photos/[A-Za-z0-9._-]+$#', $path) === 1, 404);

        $disk = Storage::disk(Photo::DISK);
        abort_unless($disk->exists($path), 404);

        $photo = Photo::where('image_path', $path)->first();

        if ($photo) {
            $user = $request->user();
            abort_unless($user->is_admin || $photo->user_id === $user->id, 403);
        }

        return $disk->response($path);
    }
}
