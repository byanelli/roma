<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\String_;
use BYanelli\Roma\Request\Attributes\Accessor;
use Illuminate\Http\Request;

/**
 * @see Request::root()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Root extends Accessor
{
    public function getType(): Type
    {
        return new String_;
    }
}
