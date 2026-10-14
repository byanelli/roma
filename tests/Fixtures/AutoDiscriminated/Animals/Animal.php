<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals;

use BYanelli\Roma\Attributes\AutoDiscriminated;

#[AutoDiscriminated(key: 'kind')]
abstract readonly class Animal
{
    public function __construct(public string $name) {}
}
