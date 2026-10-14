<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\String_;
use BYanelli\Roma\Request\Attributes\Accessor;
use Illuminate\Http\Request;

/**
 * The request body exactly as sent, for checks such as webhook signatures that are computed over the raw bytes.
 *
 * @see Request::getContent()
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Content extends Accessor
{
    public function getType(): Type
    {
        return new String_;
    }

    protected function getFromRequest(Request $request): string
    {
        return $request->getContent();
    }
}
