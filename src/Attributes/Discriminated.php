<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Declares the classes a value can be and the key whose value says which one it is.
 *
 * On an interface or abstract class it applies to every property typed as it. On a
 * property it is required for a native union type such as `Card|Voucher`, and on a
 * property typed as an interface or abstract class it replaces the class-level attribute.
 * On an array property it applies to the array's elements.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Discriminated
{
    /**
     * @param  list<class-string>  $classes
     */
    public function __construct(
        public array $classes,
        public string $key = 'type',
    ) {}
}
