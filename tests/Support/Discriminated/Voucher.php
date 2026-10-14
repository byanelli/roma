<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

final readonly class Voucher
{
    public function __construct(public string $code) {}
}
