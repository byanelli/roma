<?php

namespace BYanelli\Roma\Console;

use BYanelli\Roma\Discovery\RomaCache;
use Illuminate\Console\Command;

class RomaClearCommand extends Command
{
    protected $signature = 'roma:clear';

    protected $description = 'Remove the Roma cache file';

    public function handle(RomaCache $cache): int
    {
        $cache->clear();

        $this->components->info('Roma cache cleared.');

        return self::SUCCESS;
    }
}
