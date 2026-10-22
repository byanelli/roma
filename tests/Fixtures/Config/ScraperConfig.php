<?php

namespace BYanelli\Roma\Tests\Fixtures\Config;

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Config\ConfigObject;

#[ConfigObject('services.scraper')]
final readonly class ScraperConfig
{
    public function __construct(
        public ScraperProvider $provider,
        #[Rule('min:1')]
        public int $timeout = 30,
    ) {}
}
