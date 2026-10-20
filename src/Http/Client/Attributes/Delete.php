<?php

namespace BYanelli\Roma\Http\Client\Attributes;

use Attribute;
use BYanelli\Roma\Http\Enums\Method;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Delete extends Endpoint
{
    public function method(): Method
    {
        return Method::Delete;
    }
}
