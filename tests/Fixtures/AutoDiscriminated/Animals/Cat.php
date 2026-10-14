<?php

namespace BYanelli\Roma\Tests\Fixtures\AutoDiscriminated\Animals;

final readonly class Cat extends Animal
{
    public function __construct(string $name, public bool $indoor = true)
    {
        parent::__construct($name);
    }
}
