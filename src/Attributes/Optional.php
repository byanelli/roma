<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Marks a property that may be absent. When mapping, it is never required: an absent
 * property takes its default, else the Missing instance if typed `T|Missing`; then an absent
 * constructor parameter takes null and a non-promoted property stays unset. A parameter with
 * none of these throws when the definition is built. When serializing, an unset property is
 * omitted instead of throwing. A type with Missing has the same effect without the attribute.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Optional {}
