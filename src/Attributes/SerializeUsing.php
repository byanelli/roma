<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Serialization\SerializeTransform;
use Closure;
use ReflectionFunction;

/**
 * Passes a property's value through a callable before it is serialized:
 * `#[SerializeUsing(self::fullName(...))]`. A callable of your own that declares a second
 * parameter also receives the object, so it can derive the value from other properties. A PHP
 * function such as `strtoupper(...)` receives the value alone.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class SerializeUsing implements SerializeTransform
{
    private bool $takesObject;

    /**
     * @param  Closure(mixed, object=): mixed  $callback
     */
    public function __construct(public Closure $callback)
    {
        $function = new ReflectionFunction($callback);

        $this->takesObject = ! $function->isInternal() && $function->getNumberOfParameters() >= 2;
    }

    public function serializeValue(mixed $value, object $object): mixed
    {
        return $this->takesObject ? ($this->callback)($value, $object) : ($this->callback)($value);
    }
}
