<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\SourceAttribute;

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
     * carries every facet of the mapping it needs (source, key, rules, error
     * key); a property that also wants extra validation rules implements
     * HasValidationRules.
     */
    public static function requestSourceAttribute(): SourceAttribute;
}
