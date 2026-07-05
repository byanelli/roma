<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Request\Attributes\Accessor;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types\String_;
use Illuminate\Http\Request;

/**
 * @see Request::userAgent()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class UserAgent extends Accessor
{
    public function getType(): Type
    {
        return new String_;
    }
}
