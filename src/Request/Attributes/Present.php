<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;

/**
 * Require the key to be present in the request while still allowing a null
 * value — i.e. "present but may be null". Pair with a nullable type (`?T`),
 * which otherwise makes the key optional.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Present implements RulesAttribute
{
    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return ['present'];
    }
}
