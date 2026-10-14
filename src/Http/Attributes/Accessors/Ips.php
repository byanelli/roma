<?php

namespace BYanelli\Roma\Http\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\Array_;
use BYanelli\Roma\Data\Types\String_;
use BYanelli\Roma\Http\Attributes\Accessor;
use Illuminate\Http\Request;

/**
 * @see Request::ips()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Ips extends Accessor
{
    public function getType(): Type
    {
        return new Array_(new String_);
    }
}
