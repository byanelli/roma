<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use DateTimeImmutable;

readonly class SelfTree
{
    /**
     * @param  list<self>  $children
     * @param  list<DateTimeImmutable>  $dates
     */
    public function __construct(
        public string $name,
        public array $children = [],
        public array $dates = [],
    ) {}
}
