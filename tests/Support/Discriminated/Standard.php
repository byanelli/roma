<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

final readonly class Standard extends Shipping
{
    public function __construct(public int $days) {}
}
