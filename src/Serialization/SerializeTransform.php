<?php

namespace BYanelli\Roma\Serialization;

/**
 * An attribute that changes a property's value before it is serialized. It receives the
 * value, including null but not Missing, and the object being serialized. Several apply in
 * declaration order. TypeScript and JSON Schema still describe the property's declared type.
 */
interface SerializeTransform
{
    public function serializeValue(mixed $value, object $object): mixed;
}
