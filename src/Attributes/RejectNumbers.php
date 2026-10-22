<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an int or float input fail validation for a string property, instead of mapping to its
 * string form. On a class it applies to the string properties of the class and of its
 * subclasses. On a property without a string in its type it throws when the definition is
 * built.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS)]
final readonly class RejectNumbers {}
