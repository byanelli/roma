<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\MapTransform;
use Closure;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionType;
use ReflectionUnionType;
use Traversable;

/**
 * Sets a property from a callable that receives the whole input instead of the property's
 * value: Laravel's Request when mapping a request, the plain array for Mapper::fromArray(),
 * #[RequestBody] and #[RequestQuery], the config Repository for a config object, and the
 * client Response for an HTTP client response class. The property isn't read from the input or
 * validated. It must be the property's first map transform, and the ones after it apply to its
 * result. In an attribute the callable is a first-class callable or a static closure.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class MapFromInput implements MapTransform
{
    /**
     * The type of the callable's first parameter, or null when it is untyped or has none.
     */
    public ?ReflectionType $parameterType;

    /**
     * @param  Closure(mixed): mixed  $callback
     */
    public function __construct(public Closure $callback)
    {
        $this->parameterType = (new ReflectionFunction($callback)->getParameters()[0] ?? null)?->getType();
    }

    /**
     * Whether the callable's parameter accepts an input of `$inputType`, "array" or a class.
     *
     * @internal
     */
    public function accepts(string $inputType): bool
    {
        return $this->parameterType === null || self::typeAccepts($this->parameterType, $inputType);
    }

    public function mapValue(mixed $value): mixed
    {
        return ($this->callback)($value);
    }

    private static function typeAccepts(ReflectionType $type, string $inputType): bool
    {
        $isArray = $inputType === 'array';

        return match (true) {
            $type instanceof ReflectionUnionType => array_any($type->getTypes(), fn (ReflectionType $member) => self::typeAccepts($member, $inputType)),
            $type instanceof ReflectionIntersectionType => array_all($type->getTypes(), fn (ReflectionType $member) => self::typeAccepts($member, $inputType)),
            $type instanceof ReflectionNamedType => match ($type->getName()) {
                'mixed' => true,
                'array' => $isArray,
                'iterable' => $isArray || is_a($inputType, Traversable::class, true),
                'object' => ! $isArray,
                default => ! $type->isBuiltin() && ! $isArray && is_a($inputType, $type->getName(), true),
            },
            default => false,
        };
    }
}
