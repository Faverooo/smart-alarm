<?php

namespace Tests\Feature;

use App\Filament\Resources\PhotoResource\Pages\CreatePhoto;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoResizeTest extends TestCase
{
    use RefreshDatabase;

    private function putJpeg(string $path, int $w, int $h): void
    {
        $gd = imagecreatetruecolor($w, $h);
        ob_start();
        imagejpeg($gd);
        Storage::disk('local')->put($path, (string) ob_get_clean());
        imagedestroy($gd);
    }

    public function test_resize_shrinks_an_oversized_photo_to_the_tft_size(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->putJpeg('photos/big.jpg', 1600, 1200);
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/big.jpg']);

        CreatePhoto::resizeForEsp32($photo);

        [$w, $h] = getimagesize(Storage::disk('local')->path('photos/big.jpg'));
        $this->assertSame(320, $w);
        $this->assertSame(240, $h);
    }

    public function test_resize_is_a_noop_when_already_device_sized(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();
        $this->putJpeg('photos/ok.jpg', 320, 240);
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/ok.jpg']);

        $before = Storage::disk('local')->get('photos/ok.jpg');
        CreatePhoto::resizeForEsp32($photo);
        $after = Storage::disk('local')->get('photos/ok.jpg');

        // Untouched → no re-encode → identical bytes (so the sync hash won't churn).
        $this->assertSame($before, $after);
    }
}
