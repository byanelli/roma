<?php

namespace BYanelli\Roma\Tests\Support\Imports\Traits;

use BYanelli\Roma\Tests\Support\Discriminated\Card;

trait HasCards
{
    /** @var list<Card> */
    public array $cards;
}
