<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Pet
{
    public function __construct(
        public string $name,
        public ?Person $owner = null,
    ) {}
}
