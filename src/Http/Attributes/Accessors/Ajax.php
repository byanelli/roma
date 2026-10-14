<?php

namespace BYanelli\Roma\Http\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Http\Attributes\BooleanAccessor;
use Illuminate\Http\Request;

/**
 * @see Request::ajax()
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Ajax extends BooleanAccessor
{
    //
}
