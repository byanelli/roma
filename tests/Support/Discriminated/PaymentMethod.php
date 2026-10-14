<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

use BYanelli\Roma\Attributes\Discriminated;

#[Discriminated([Card::class, BankAccount::class])]
interface PaymentMethod {}
