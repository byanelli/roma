<?php

namespace BYanelli\Roma\Console;

use BYanelli\Roma\Discovery\RomaCache;
use Illuminate\Console\Command;

class RomaCacheCommand extends Command
{
    protected $signature = 'roma:cache';

    protected $description = 'Cache the members of Roma #[AutoDiscriminated] unions and the config objects';

    public function handle(RomaCache $cache): int
    {
        $discovered = $cache->cache();
        $unions = count($discovered['auto_discriminated']);
        $configObjects = count($discovered['config_objects']);

        $this->components->info(sprintf(
            'Cached %d #[AutoDiscriminated] %s and %d config %s to %s.',
            $unions,
            $unions === 1 ? 'union' : 'unions',
            $configObjects,
            $configObjects === 1 ? 'object' : 'objects',
            $cache->cachePath(),
        ));

        return self::SUCCESS;
    }
}
