<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Person
{
    public function __construct(
        public string $name,
        public ?Pet $pet = null,
    ) {}
}
