<?php

namespace BYanelli\Roma\Http\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\String_;
use BYanelli\Roma\Http\Attributes\Accessor;
use Illuminate\Http\Request;

/**
 * @see Request::getScheme()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Scheme extends Accessor
{
    public function getType(): Type
    {
        return new String_;
    }

    protected function getFromRequest(Request $request): mixed
    {
        // Symfony exposes the scheme as getScheme(), not scheme().
        return $request->getScheme();
    }
}
