<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

final readonly class BadFolder implements BadEntry
{
    /**
     * @param  list<BadEntry>  $children
     */
    public function __construct(
        public string $name,
        public array $children = [],
    ) {}
}
