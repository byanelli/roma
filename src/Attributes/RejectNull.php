<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an input value of exactly null fail validation for a property that isn't nullable but
 * has a default, instead of taking the default. On a class it applies to such properties of
 * the class and of its subclasses. On a nullable property, or one without a default, it throws
 * when the definition is built.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS)]
final readonly class RejectNull {}
