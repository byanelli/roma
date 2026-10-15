<?php

namespace BYanelli\Roma\Http\Enums;

use BYanelli\Roma\Data\Attributes\SourceAttribute;

/**
 * Implemented by a request-metadata type (a metadata enum like Method or
 * ContentType, or a value object like Authorization) that knows which request
 * source it maps from. When a property is typed as one of these and carries no
 * explicit source attribute, Roma injects this attribute so the property
 * behaves as if the user had written it by hand.
 */
interface HasRequestSource
{
    /**
     * The source attribute this type is equivalent to. A single attribute
     * specifies all parts of the mapping (source, key, rules, error key); for
     * extra validation rules, the type also implements HasValidationRules.
     */
    public static function requestSourceAttribute(): SourceAttribute;
}
