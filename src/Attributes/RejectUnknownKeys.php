<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an input key that no property of the class maps from fail validation, one error per
 * key. It applies to the class and its subclasses, wherever they are mapped: at the top level
 * or as nested objects. `$message` replaces Laravel's `prohibited` message.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class RejectUnknownKeys
{
    public function __construct(public ?string $message = null) {}
}
