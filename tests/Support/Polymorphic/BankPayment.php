<?php

namespace BYanelli\Roma\Tests\Support\Polymorphic;

class BankPayment extends PaymentMethod
{
    public function __construct(
        public string $iban,
    ) {}
}
