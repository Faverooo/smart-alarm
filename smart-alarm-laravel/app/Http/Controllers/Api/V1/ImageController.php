<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\Photo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\JpegEncoder;
use Intervention\Image\ImageManager;
use Symfony\Component\HttpFoundation\Response;

class ImageController extends Controller
{
    /**
     * Device-optimised photo.
     *
     * The ESP can't resize a multi-megapixel upload, so we render the photo to
     * the TFT resolution (default 320×240, `cover` so it fills the screen) and
     * return a small JPEG. ETag/304 means the device re-downloads only when the
     * file actually changes. Only photos in the calling device's current program
     * are renderable.
     */
    public function render(Request $request, Photo $photo): Response
    {
        /** @var Device $device */
        $device = $request->user();

        abort_unless(
            $device->program && $device->program->photos()->whereKey($photo->id)->exists(),
            403,
        );

        // Clamp to sane bounds so a device can't ask us to render a huge bitmap.
        $width  = (int) min(max($request->integer('w', (int) config('services.device_display.width', 320)), 16), 1024);
        $height = (int) min(max($request->integer('h', (int) config('services.device_display.height', 240)), 16), 1024);

        $etag = substr(sha1($photo->image_path.'|'.$photo->updated_at.'|'.$width.'x'.$height), 0, 16);

        if ($this->ifNoneMatchMatches($request, $etag)) {
            return response('', 304)->setEtag($etag);
        }

        $disk = Storage::disk(Photo::DISK);

        abort_unless($photo->image_path && $disk->exists($photo->image_path), 404);

        // Serve from the render cache when possible — the server is a Raspberry
        // Pi, so decode each multi-megapixel source once, not per cache miss.
        $cachePath = Photo::RENDER_CACHE_DIR."/{$photo->id}-{$width}x{$height}-{$etag}.jpg";

        if ($disk->exists($cachePath)) {
            $jpeg = $disk->get($cachePath);
        } else {
            $image = (new ImageManager(new Driver()))->decode($disk->path($photo->image_path));
            $image->cover($width, $height); // fill the screen, crop the overflow
            $jpeg = (string) $image->encode(new JpegEncoder(quality: 80));

            // Drop renders of this photo at this size with older etags (the
            // file was replaced), then cache the fresh one.
            foreach ($disk->files(Photo::RENDER_CACHE_DIR) as $file) {
                if (str_starts_with(basename($file), "{$photo->id}-{$width}x{$height}-")) {
                    $disk->delete($file);
                }
            }
            $disk->put($cachePath, $jpeg);
        }

        return response($jpeg, 200, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
            'Content-Length' => (string) strlen($jpeg),
        ])->setEtag($etag);
    }
}
