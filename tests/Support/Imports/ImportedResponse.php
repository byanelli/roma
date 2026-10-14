<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Response\Response;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;

class ImportedResponse extends Response
{
    /**
     * @param  list<PaymentMethod>  $methods
     */
    public function __construct(public array $methods) {}
}
