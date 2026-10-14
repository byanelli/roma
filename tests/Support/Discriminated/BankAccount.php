<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

use BYanelli\Roma\Attributes\DiscriminatorValue;

#[DiscriminatorValue('bank')]
final readonly class BankAccount implements PaymentMethod
{
    public function __construct(public string $iban) {}
}
