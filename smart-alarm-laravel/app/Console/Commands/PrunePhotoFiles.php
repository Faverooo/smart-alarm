<?php

namespace App\Console\Commands;

use App\Models\Photo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class PrunePhotoFiles extends Command
{
    protected $signature = 'photos:prune-files {--hours=24 : Grace period before a file is considered orphaned}';

    protected $description = 'Delete photo files with no owning record: abandoned (uploaded-but-never-saved) uploads and orphans left by replaced/deleted photos.';

    public function handle(): int
    {
        $disk = Storage::disk(Photo::DISK);
        $cutoff = now()->subHours((int) $this->option('hours'))->getTimestamp();
        $deleted = 0;

        // 1. Abandoned uploads: a user picked a file but never saved the form, so
        //    it sits in Livewire's temp dir and was never promoted to a Photo.
        foreach ($disk->files('livewire-tmp') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        // 2. Orphaned finals: a file under photos/ that no Photo row references
        //    (e.g. a save that half-failed). Replace/delete are handled live by
        //    the Photo model events; this is the backstop.
        $referenced = Photo::pluck('image_path')->filter()->flip();
        foreach ($disk->files('photos') as $file) {
            if (! $referenced->has($file) && $disk->lastModified($file) < $cutoff) {
                $disk->delete($file);
                $deleted++;
            }
        }

        $this->info("Pruned {$deleted} orphaned photo file(s).");

        return self::SUCCESS;
    }
}
