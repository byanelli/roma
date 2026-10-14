<?php

namespace BYanelli\Roma\Tests\Support\Imports\Traits;

use BYanelli\Roma\Tests\Support\Discriminated\Voucher as Coupon;

trait HasCoupons
{
    /**
     * @param  list<Coupon>  $coupons
     */
    public function __construct(public array $coupons) {}
}
