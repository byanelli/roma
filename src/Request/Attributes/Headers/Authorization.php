<?php

namespace BYanelli\Roma\Request\Attributes\Headers;

use Attribute;
use BYanelli\Roma\Request\Attributes\Header;

/**
 * Sources a property from the Authorization request header.
 *
 * On a plain string property it yields the raw header value; the Authorization
 * value object (Request\Values\Authorization) uses it to self-locate and then
 * parse that value into a scheme and credentials.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Authorization extends Header
{
    public function __construct()
    {
        parent::__construct('Authorization');
    }
}
