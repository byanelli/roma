<?php

namespace BYanelli\Roma\Data\Attributes;

/**
 * An attribute that changes a property's value after validation, before the object is
 * constructed. It receives the value the property would get, including its default or null,
 * but not Missing. Several apply in declaration order.
 */
interface MapTransform
{
    public function mapValue(mixed $value): mixed;
}
