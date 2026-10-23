<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use Closure;

/**
 * Treats a property's key as absent when the callable returns true for its raw input value,
 * before coercion and validation: `#[IgnoreIf(self::isPlaceholder(...))]`. The callable is not
 * called for an absent key. In an attribute the callable is a first-class callable or a static
 * closure. On a property whose type has no Missing, the class definition throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class IgnoreIf
{
    /**
     * @param  Closure(mixed): bool  $callback
     */
    public function __construct(public Closure $callback) {}
}
