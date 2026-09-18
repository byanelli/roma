<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

use BYanelli\Roma\Response\Response;

class PaymentResponse extends Response
{
    public function __construct(
        public PaymentMethod $method,
    ) {}
}
