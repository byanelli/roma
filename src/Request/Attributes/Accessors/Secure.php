<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Request\Attributes\BooleanAccessor;
use Illuminate\Http\Request;

/**
 * @see Request::secure()
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Secure extends BooleanAccessor
{
    //
}
