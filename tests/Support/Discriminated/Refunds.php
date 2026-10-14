<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

use BYanelli\Roma\Attributes\Discriminated;

final readonly class Refunds
{
    /**
     * @param  list<Card|Voucher>  $refunds
     */
    public function __construct(
        #[Discriminated([Card::class, Voucher::class])]
        public array $refunds,
    ) {}
}
