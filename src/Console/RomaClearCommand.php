<?php

namespace BYanelli\Roma\Console;

use BYanelli\Roma\Discovery\DiscriminatedMembers;
use Illuminate\Console\Command;

class RomaClearCommand extends Command
{
    protected $signature = 'roma:clear';

    protected $description = 'Remove the Roma cache file';

    public function handle(DiscriminatedMembers $members): int
    {
        $members->clear();

        $this->components->info('Roma cache cleared.');

        return self::SUCCESS;
    }
}
