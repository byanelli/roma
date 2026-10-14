<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class Node
{
    public function __construct(
        public string $name,
        public ?Node $parent = null,
    ) {}
}
