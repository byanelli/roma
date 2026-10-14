<?php

namespace BYanelli\Roma\Tests\Support\Discriminated\Other;

use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;

final readonly class Card implements PaymentMethod
{
    public function __construct(public string $number) {}
}
