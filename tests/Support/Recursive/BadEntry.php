<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

use BYanelli\Roma\Attributes\Discriminated;

#[Discriminated([BadFolder::class], key: 'name')]
interface BadEntry {}
