<?php

namespace BYanelli\Roma\Tests\Support\Imports;

readonly class FullyQualifiedRequest
{
    /**
     * @param  list<\BYanelli\Roma\Tests\Support\Discriminated\Card>  $cards
     */
    public function __construct(
        public array $cards,
        public ?Card $own = null,
    ) {}
}
