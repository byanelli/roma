<?php

namespace BYanelli\Roma\Tests\Support\Recursive;

use BYanelli\Roma\Attributes\Discriminated;

#[Discriminated([Note::class, Folder::class])]
interface Entry {}
