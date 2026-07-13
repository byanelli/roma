<?php

namespace BYanelli\Roma\Request\ContextualBinding;

use Attribute;
use BYanelli\Roma\Request\Contracts\RequestMapper;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Marks a class as a Roma request. Apply it at the parameter level as an
 * injection hint — `fn (#[Request] Foo $foo) => ...` — or at the class level,
 * which additionally lets the class be resolved by type-hint alone (see
 * RomaServiceProvider) and be auto-detected by the TypeScript generator.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_CLASS)]
class Request implements ContextualAttribute
{
    /** @var array<string, bool> */
    private static array $markedCache = [];

    /**
     * Whether the given name is a class carrying a class-level #[Request]
     * attribute. Accepts any string (e.g. a container abstract) and returns
     * false for non-classes. Cached per process, since a class's attributes
     * cannot change at runtime.
     */
    public static function isMarkedOn(string $class): bool
    {
        if (isset(self::$markedCache[$class])) {
            return self::$markedCache[$class];
        }

        if (! class_exists($class)) {
            return self::$markedCache[$class] = false;
        }

        return self::$markedCache[$class] = new ReflectionClass($class)->getAttributes(self::class) !== [];
    }

    /**
     * @throws ContextualBindingException
     * @throws \ReflectionException
     * @throws BindingResolutionException
     * @throws ValidationException
     */
    public static function resolve(self $attribute, Container $container, ReflectionParameter $parameter): mixed
    {
        $type = $parameter->getType();

        ($type instanceof ReflectionNamedType) ||
            throw new ContextualBindingException("the parameter \${$parameter->getName()} must be type-hinted with a class");

        class_exists($className = $type->getName()) ||
            throw new ContextualBindingException("$className does not exist");

        /** @var RequestMapper $mapper */
        $mapper = $container->make(RequestMapper::class);

        return $mapper->mapRequest($className);
    }
}
