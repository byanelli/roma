<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

use BYanelli\Roma\Attributes\Discriminated;

final readonly class Checkout
{
    /**
     * @param  list<PaymentMethod>  $fallbacks
     * @param  array<string, PaymentMethod>  $saved
     */
    public function __construct(
        public PaymentMethod $payment,
        public Shipping $shipping,
        #[Discriminated([Card::class, Voucher::class])]
        public Card|Voucher $refundTo,
        public array $fallbacks = [],
        public array $saved = [],
        public ?PaymentMethod $backup = null,
        public ?Wallet $wallet = null,
    ) {}
}
