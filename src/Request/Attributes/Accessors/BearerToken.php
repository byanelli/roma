<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Request\Attributes\Accessor;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types\String_;
use Illuminate\Http\Request;

/**
 * @see Request::bearerToken()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class BearerToken extends Accessor
{
    public function getType(): Type
    {
        return new String_;
    }
}
