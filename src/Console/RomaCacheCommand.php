<?php

namespace BYanelli\Roma\Console;

use BYanelli\Roma\Discovery\DiscriminatedMembers;
use Illuminate\Console\Command;

class RomaCacheCommand extends Command
{
    protected $signature = 'roma:cache';

    protected $description = 'Cache the members of Roma #[AutoDiscriminated] unions';

    public function handle(DiscriminatedMembers $members): int
    {
        $count = count($members->cache());

        $this->components->info(sprintf(
            'Cached %d #[AutoDiscriminated] %s to %s.',
            $count,
            $count === 1 ? 'union' : 'unions',
            $members->cachePath(),
        ));

        return self::SUCCESS;
    }
}
