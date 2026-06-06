<?php

namespace App\Console\Commands;

use App\Models\Device;
use Illuminate\Console\Command;

class PruneUnpairedDevices extends Command
{
    protected $signature = 'devices:prune-unpaired';

    protected $description = 'Delete devices that registered but were never claimed within the pairing window';

    public function handle(): int
    {
        $stale = Device::stalePairing()->get();

        foreach ($stale as $device) {
            $device->tokens()->delete(); // revoke the device's API token
            $device->delete();
        }

        $this->info("Pruned {$stale->count()} unpaired device(s).");

        return self::SUCCESS;
    }
}
