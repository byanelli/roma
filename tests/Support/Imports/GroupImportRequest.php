<?php

// Excluded from Pint in pint.json, which splits group imports and namespace blocks.

namespace BYanelli\Roma\Tests\Support\Imports {
    use BYanelli\Roma\Tests\Support\Discriminated\{Card, Voucher as Coupon, function coupon};
    use function BYanelli\Roma\Tests\Support\Imports\card;
    use const BYanelli\Roma\Tests\Support\Imports\COUPON;

    readonly class GroupImportRequest
    {
        /**
         * @param  list<Card>  $cards
         * @param  list<Coupon>  $coupons
         */
        public function __construct(
            public array $cards,
            public array $coupons,
        ) {}
    }
}

namespace BYanelli\Roma\Tests\Support\Imports\Decoy {
    use BYanelli\Roma\Tests\Support\Imports\Card as Coupon;

    final class DecoyCoupons
    {
        /** @var list<Coupon> */
        public array $coupons;
    }
}
