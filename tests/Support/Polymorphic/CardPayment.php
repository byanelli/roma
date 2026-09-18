<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

class CardPayment extends PaymentMethod
{
    public function __construct(
        public string $last4,
    ) {}
}
