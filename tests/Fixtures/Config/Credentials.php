<?php

namespace BYanelli\Roma\Tests\Fixtures\Config;

/**
 * Used only with a parameter-level prefix, so roma:config:validate doesn't list it.
 */
final readonly class Credentials
{
    public function __construct(
        public string $user,
        #[\SensitiveParameter] public string $password,
    ) {}
}
