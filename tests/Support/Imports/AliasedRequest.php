<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Tests\Support\Discriminated\Card as PaymentCard;

readonly class AliasedRequest
{
    /** @var list<PaymentCard> */
    public array $cards;
}
