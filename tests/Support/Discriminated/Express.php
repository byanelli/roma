<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

final readonly class Express extends Shipping
{
    public function __construct(
        public string $carrier,
        public ?PaymentMethod $surcharge = null,
    ) {}
}
