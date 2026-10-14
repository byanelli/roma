<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * The format of a date. Mapping requires the input to match it exactly (Laravel's date_format
 * rule) and parses it with createFromFormat; fields the format lacks are zero. Serializing
 * writes the date in this format instead of the profile's. Both apply to the property's own
 * value: a date, or the dates in an array, map or shape. A nested object's dates use the
 * nested class's formats. On a type with no date this throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateFormat
{
    public function __construct(public string $format) {}
}
