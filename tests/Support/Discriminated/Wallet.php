<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

final readonly class Wallet
{
    /**
     * @param  array{primary: PaymentMethod, label?: string}  $slots
     */
    public function __construct(
        public PaymentMethod $default,
        public array $slots,
    ) {}
}
