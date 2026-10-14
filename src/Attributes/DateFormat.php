<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * The format of a date. Mapping requires the input to match it exactly (Laravel's date_format
 * rule) and parses it with createFromFormat; fields the format lacks are zero. This applies to
 * the property and to dates in an array or shape it contains. Serializing writes the property,
 * and dates nested within its value, in this format instead of DateTimeInterface::ATOM.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateFormat
{
    public function __construct(public string $format) {}
}
