<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Tests\Support\Discriminated\Card;

readonly class ImportedListRequest
{
    /** @var list<Card> */
    public array $cards;
}
