<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Treats a property's key as absent when its raw input value is exactly '' or [], such as a
 * JSON {} or []. Null, "0", 0 and false are kept. On a property whose type has no Missing, the
 * class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class IgnoreIfEmpty {}
