<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Marks a property that may be absent. When mapping, it is never required: an absent
 * constructor parameter takes its default, else the Missing instance if typed `T|Missing`,
 * else null; an absent non-promoted property stays unset. A parameter with none of these
 * throws when the definition is built. When serializing, an unset property or one whose
 * value is Missing is omitted instead of throwing.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Optional {}
