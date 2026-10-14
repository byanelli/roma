<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Note implements Entry
{
    public function __construct(
        public string $name,
        public int $size,
    ) {}
}
