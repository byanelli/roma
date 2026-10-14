<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;

readonly class ImportedShapeRequest
{
    /**
     * @param  array{card: Card, vouchers?: list<Voucher>}  $checkout
     * @param  array<string, Card>  $saved
     */
    public function __construct(
        public array $checkout,
        public array $saved,
    ) {}
}
