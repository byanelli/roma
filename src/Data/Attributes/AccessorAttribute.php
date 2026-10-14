<?php

namespace BYanelli\Roma\Data\Attributes;

use Closure;

interface AccessorAttribute
{
    public function getAccessor(): Closure;
}
