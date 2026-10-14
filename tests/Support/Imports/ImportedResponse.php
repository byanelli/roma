<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Response\Response;
use BYanelli\Roma\Tests\Support\Discriminated\Card;

class ImportedResponse extends Response
{
    /**
     * @param  list<Card>  $cards
     */
    public function __construct(public array $cards) {}
}
