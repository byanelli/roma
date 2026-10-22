<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\MapTransform;
use Closure;
use ReflectionFunction;
use ReflectionNamedType;

/**
 * Passes a property's mapped value through a callable after validation:
 * `#[MapUsing(strtolower(...))]`. A callable whose parameter is typed as the input being
 * mapped, such as Laravel's Request, receives the input instead, and its result is the
 * property's value: the property isn't read from the input or validated. In an attribute the
 * callable is a first-class callable or a static closure.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER | Attribute::IS_REPEATABLE)]
final readonly class MapUsing implements MapTransform
{
    /**
     * The class of the callable's parameter, or null when it isn't typed as a class.
     *
     * @var ?class-string
     */
    public ?string $parameterClass;

    /**
     * @param  Closure(mixed): mixed  $callback
     */
    public function __construct(public Closure $callback)
    {
        $type = (new ReflectionFunction($callback)->getParameters()[0] ?? null)?->getType();
        $class = $type instanceof ReflectionNamedType && ! $type->isBuiltin() ? $type->getName() : null;

        $this->parameterClass = $class !== null && (class_exists($class) || interface_exists($class)) ? $class : null;
    }

    /**
     * Whether the callable takes the input, an object of `$inputType`, instead of the value.
     *
     * @param  class-string  $inputType
     */
    public function takesInput(string $inputType): bool
    {
        return $this->parameterClass !== null && is_a($inputType, $this->parameterClass, true);
    }

    public function mapValue(mixed $value): mixed
    {
        return ($this->callback)($value);
    }
}
