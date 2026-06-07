<?php

namespace Tests\Feature;

use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PhotoFileTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $path): string
    {
        return route('photos.serve', ['path' => $path]);
    }

    private function putPhoto(User $owner, string $path = 'photos/a.jpg'): Photo
    {
        Storage::disk('local')->put($path, 'JPEGBYTES');

        return Photo::create(['user_id' => $owner->id, 'image_path' => $path]);
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get($this->url('photos/a.jpg'))->assertRedirect('/login');
    }

    public function test_owner_can_stream_their_photo(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $photo = $this->putPhoto($owner);

        $this->actingAs($owner)
            ->get($this->url($photo->image_path))
            ->assertOk();
    }

    public function test_a_different_user_cannot_view_someone_elses_photo(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $stranger = User::factory()->create(['is_admin' => false]);
        $photo = $this->putPhoto($owner);

        $this->actingAs($stranger)
            ->get($this->url($photo->image_path))
            ->assertStatus(403);
    }

    public function test_admin_can_view_any_photo(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create();
        $admin = User::factory()->create(['is_admin' => true]);
        $photo = $this->putPhoto($owner);

        $this->actingAs($admin)
            ->get($this->url($photo->image_path))
            ->assertOk();
    }

    public function test_path_traversal_is_rejected(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->url('photos/../../.env'))
            ->assertStatus(404);
    }

    public function test_missing_file_is_404(): void
    {
        Storage::fake('local');
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get($this->url('photos/nope.jpg'))
            ->assertStatus(404);
    }
}
