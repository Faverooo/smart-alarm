<?php

namespace Tests\Feature;

use App\Filament\Resources\ProgramResource\Pages\EditProgram;
use App\Filament\Resources\ProgramResource\RelationManagers\PhotosRelationManager;
use App\Livewire\DailyPhotoCalendar;
use App\Models\Photo;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ProgramPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_program_edit_page_renders(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        // Make the user a photo manager so the resource is visible
        \App\Models\Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:01',
            'photo_user_id' => $user->id,
        ]);

        $program = Program::create([
            'user_id' => $user->id,
            'name'    => 'Test',
            'type'    => 'rotation',
            'interval_minutes' => 10,
        ]);

        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/x.jpg']);
        $program->photos()->attach($photo->id, ['is_shown' => false]);

        $this->actingAs($user)
            ->get("/programs/{$program->id}/edit")
            ->assertStatus(200);
    }

    public function test_daily_program_edit_page_renders_with_calendar(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');

        $this->actingAs($user)
            ->get("/programs/{$program->id}/edit")
            ->assertStatus(200);
    }

    public function test_daily_program_name_auto_saves_without_a_save_button(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        $this->actingAs($user);

        // Daily edit page has no Save button; the name persists on change.
        Livewire::test(EditProgram::class, ['record' => $program->getRouteKey()])
            ->set('data.name', 'Renamed Daily')
            ->assertHasNoErrors();

        $this->assertSame('Renamed Daily', $program->fresh()->name);
    }

    public function test_non_daily_program_name_does_not_auto_save(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('rotation');
        $this->actingAs($user);

        // Rotation keeps the normal Save flow — changing the field alone must NOT persist.
        Livewire::test(EditProgram::class, ['record' => $program->getRouteKey()])
            ->set('data.name', 'Should Not Persist');

        $this->assertSame('P', $program->fresh()->name);
    }

    public function test_remove_action_detaches_photo(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('rotation');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/x.jpg']);
        $program->photos()->attach($photo->id, ['is_shown' => false]);

        $this->actingAs($user);

        Livewire::test(PhotosRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass'   => EditProgram::class,
        ])
            ->assertTableActionVisible('removePhoto', $photo)
            ->assertTableActionVisible('editPhoto', $photo)
            ->callTableAction('removePhoto', $photo)
            ->assertHasNoTableActionErrors();

        $this->assertSame(0, $program->fresh()->photos()->count());
    }

    public function test_fixed_program_blocks_second_photo(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('fixed');
        $existing = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);
        $program->photos()->attach($existing->id, ['is_shown' => false]);

        $this->actingAs($user);

        // A full fixed program hides both add actions and refuses extra photos.
        Livewire::test(PhotosRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass'   => EditProgram::class,
        ])
            ->assertTableActionHidden('uploadPhotos')
            ->assertTableActionHidden('attachExisting');

        $this->assertSame(1, $program->fresh()->photos()->count());
    }

    public function test_remove_then_re_add_works_on_fixed(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('fixed');
        $a = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);
        $program->photos()->attach($a->id, ['is_shown' => false]);

        $this->actingAs($user);

        Livewire::test(PhotosRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass'   => EditProgram::class,
        ])
            ->callTableAction('removePhoto', $a)
            ->assertTableActionVisible('uploadPhotos'); // slot freed up again

        $this->assertSame(0, $program->fresh()->photos()->count());
    }

    public function test_attach_existing_works_on_fixed_program(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('fixed');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);

        $this->actingAs($user);

        // Regression: a single-value select tripped "the photo field must be an array".
        Livewire::test(PhotosRelationManager::class, [
            'ownerRecord' => $program,
            'pageClass'   => EditProgram::class,
        ])
            ->callTableAction('attachExisting', data: ['photo_ids' => [$photo->id]])
            ->assertHasNoTableActionErrors();

        $this->assertSame(1, $program->fresh()->photos()->count());
    }

    public function test_daily_program_hides_table_and_shows_calendar(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');

        $this->actingAs($user);

        // The photos table is hidden for daily programs...
        $this->assertFalse(
            PhotosRelationManager::canViewForRecord($program, EditProgram::class)
        );

        // ...and the calendar component renders.
        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->assertOk();
    }

    public function test_daily_calendar_sets_one_photo_per_day(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        $first = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/old.jpg']);
        $program->photos()->attach($first->id, ['scheduled_for' => '2026-06-12', 'is_shown' => false]);

        $this->actingAs($user);

        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->callAction('removeDay', arguments: ['date' => '2026-06-12']);

        $this->assertSame(0, $program->fresh()->photos()->wherePivot('scheduled_for', '2026-06-12')->count());
    }

    public function test_daily_calendar_setday_action_is_registered(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        $this->actingAs($user);

        // The "add a photo to this day" action mounts without error.
        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->mountAction('setDay', arguments: ['date' => '2026-06-20'])
            ->assertActionMounted('setDay');
    }

    public function test_daily_calendar_attaches_existing_photo_to_a_day(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg', 'caption' => 'Sunrise']);
        $this->actingAs($user);

        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->mountAction('setDay', arguments: ['date' => '2026-06-22'])
            ->setActionData(['source' => 'existing', 'photo_id' => $photo->id])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $pinned = $program->fresh()->photos()->wherePivot('scheduled_for', '2026-06-22')->first();
        $this->assertNotNull($pinned);
        $this->assertSame($photo->id, $pinned->id);
    }

    public function test_removing_a_day_keeps_the_same_photo_on_other_days(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        // The SAME photo pinned to two different days
        $photo = Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg']);
        $program->photos()->attach($photo->id, ['scheduled_for' => '2026-06-10', 'is_shown' => false]);
        $program->photos()->attach($photo->id, ['scheduled_for' => '2026-06-11', 'is_shown' => false]);

        $this->actingAs($user);

        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->callAction('removeDay', arguments: ['date' => '2026-06-10']);

        // Only the 10th is cleared; the 11th still shows the photo
        $this->assertSame(0, $program->fresh()->photos()->wherePivot('scheduled_for', '2026-06-10')->count());
        $this->assertSame(1, $program->fresh()->photos()->wherePivot('scheduled_for', '2026-06-11')->count());
    }

    public function test_daily_calendar_week_view_toggle(): void
    {
        [$user, $program] = $this->makePhotoUserAndProgram('daily');
        $this->actingAs($user);

        Livewire::test(DailyPhotoCalendar::class, ['program' => $program])
            ->assertSet('view', 'month')
            ->call('setView', 'week')
            ->assertSet('view', 'week')
            ->assertCount('days', 7)
            ->call('next')
            ->assertOk();
    }

    public function test_photos_list_renders_with_lightbox_column(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        \App\Models\Device::create(['mac_address' => 'AA:BB:CC:DD:EE:09', 'photo_user_id' => $user->id]);
        Photo::create(['user_id' => $user->id, 'image_path' => 'photos/a.jpg', 'caption' => 'Hi']);

        $this->actingAs($user)
            ->get('/photos')
            ->assertStatus(200);
    }

    private function makePhotoUserAndProgram(string $type): array
    {
        $user = User::factory()->create(['is_admin' => false]);
        \App\Models\Device::create([
            'mac_address'   => 'AA:BB:CC:DD:EE:' . substr(md5($type), 0, 2),
            'photo_user_id' => $user->id,
        ]);

        $program = Program::create([
            'user_id' => $user->id,
            'name'    => 'P',
            'type'    => $type,
            'interval_minutes' => $type === 'rotation' ? 10 : null,
        ]);

        return [$user, $program];
    }
}
