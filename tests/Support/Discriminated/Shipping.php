<?php

namespace BYanelli\Roma\Tests\Support\Discriminated;

use BYanelli\Roma\Attributes\Discriminated;

#[Discriminated([Standard::class, Express::class], key: 'kind')]
abstract readonly class Shipping {}
