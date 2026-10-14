<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;

readonly class ImportedUnionRequest
{
    /**
     * @param  list<Card|Voucher>  $methods
     */
    public function __construct(
        #[Discriminated([Card::class, Voucher::class])]
        public array $methods,
    ) {}
}
