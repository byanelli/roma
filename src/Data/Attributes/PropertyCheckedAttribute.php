<?php

namespace BYanelli\Roma\Data\Attributes;

use RuntimeException;

/**
 * A property attribute with arguments that may have no meaning on a property. Building a
 * definition and planning serialization both run the check.
 */
interface PropertyCheckedAttribute
{
    /**
     * @param  string  $printableName  the property, e.g. `Order::$id`
     *
     * @throws RuntimeException when the arguments have no meaning on a property
     */
    public function assertAllowedOnProperty(string $printableName): void;
}
