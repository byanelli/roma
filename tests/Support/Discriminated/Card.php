<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

final readonly class Card implements PaymentMethod
{
    public function __construct(
        public string $last4,
        public bool $saved = false,
    ) {}
}
