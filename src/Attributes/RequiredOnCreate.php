<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an optional property required when the request is a create, meaning an HTTP POST as
 * Laravel's Request::isMethod('POST') reports it. On any other method, including PUT, and
 * outside a request, such as in Mapper::fromArray(), the property stays optional. On a
 * property that is required anyway, the class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class RequiredOnCreate {}
