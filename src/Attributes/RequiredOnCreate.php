<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an optional property required when the mapping's Operation is Create. A request is a
 * Create when it is an HTTP POST, as Laravel's Request::isMethod('POST') reports it, so a form's
 * _method override counts. An array is one only when Mapper::fromArray() is passed
 * Operation::Create. On a property that is required anyway, the class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class RequiredOnCreate {}
