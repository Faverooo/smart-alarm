<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_a_photo_deletes_its_file(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $user = User::factory()->create();
        $disk->put('photos/a.jpg', 'x');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);

        $photo->delete();

        $disk->assertMissing('photos/a.jpg');
    }

    public function test_replacing_a_photos_file_deletes_the_old_one(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $user = User::factory()->create();
        $disk->put('photos/old.jpg', 'x');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/old.jpg']);

        $disk->put('photos/new.jpg', 'y');
        $photo->update(['image_path' => 'photos/new.jpg']);

        $disk->assertMissing('photos/old.jpg'); // old file cleaned up
        $disk->assertExists('photos/new.jpg');  // new file kept
    }

    public function test_caption_only_edit_keeps_the_file(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $user = User::factory()->create();
        $disk->put('photos/a.jpg', 'x');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);

        $photo->update(['caption' => 'Hello']);

        $disk->assertExists('photos/a.jpg'); // image_path unchanged → file untouched
    }

    public function test_prune_removes_abandoned_uploads_and_orphans_but_keeps_referenced_and_recent(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $user = User::factory()->create();

        // An upload that was chosen but never saved (sits in Livewire's temp dir).
        $disk->put('livewire-tmp/abandoned.jpg', 'x');
        // A very recent temp upload (someone mid-form) — must be kept.
        $disk->put('livewire-tmp/recent.jpg', 'x');
        // A final file no Photo references (orphan).
        $disk->put('photos/orphan.jpg', 'x');
        // A final file that IS referenced — must be kept even if old.
        $disk->put('photos/kept.jpg', 'x');
        Photo::create(['user_id' => $user->id, 'image_path' => 'photos/kept.jpg']);

        // Age the abandoned upload and the orphan past the 24h grace.
        $old = now()->subDays(2)->getTimestamp();
        touch($disk->path('livewire-tmp/abandoned.jpg'), $old);
        touch($disk->path('photos/orphan.jpg'), $old);
        touch($disk->path('photos/kept.jpg'), $old);

        $this->artisan('photos:prune-files')->assertSuccessful();

        $disk->assertMissing('livewire-tmp/abandoned.jpg'); // abandoned upload gone
        $disk->assertExists('livewire-tmp/recent.jpg');     // too recent → kept
        $disk->assertMissing('photos/orphan.jpg');          // orphan gone
        $disk->assertExists('photos/kept.jpg');             // referenced → kept
    }
}
