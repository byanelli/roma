<?php

namespace BYanelli\Roma\Tests\Support\Imports\Parents;

use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher as Coupon;

class CardHolder
{
    /** @var list<Card> */
    public array $cards;

    /**
     * @param  list<Coupon>  $coupons
     */
    public function __construct(public array $coupons) {}
}
