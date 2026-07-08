<?php

namespace BYanelli\Roma\Response\Attributes;

use Attribute;

/**
 * Formats a DateTimeInterface property's value with the given format string
 * when serializing. Applies to that property (and dates nested within its
 * value). Without it, dates serialize as DateTimeInterface::ATOM.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class DateFormat
{
    public function __construct(public string $format) {}
}
