<?php

namespace BYanelli\Roma\Http\Request\Injection;

use Attribute;
use BYanelli\Roma\Http\Request\Contracts\RequestMapper;
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
        /** @var RequestMapper $mapper */
        $mapper = $container->make(RequestMapper::class);

        return $mapper->mapRequest(self::parameterClass($parameter, 'Request'));
    }

    /**
     * The class the parameter is type-hinted with.
     *
     * @internal
     *
     * @param  string  $attribute  the short name of the attribute on the parameter, for the error message
     * @return class-string
     *
     * @throws ContextualBindingException
     */
    public static function parameterClass(ReflectionParameter $parameter, string $attribute): string
    {
        $type = $parameter->getType();

        ($type instanceof ReflectionNamedType) ||
            throw new ContextualBindingException("the parameter \${$parameter->getName()} must be type-hinted with a class", attribute: $attribute);

        class_exists($className = $type->getName()) ||
            throw new ContextualBindingException("$className does not exist", attribute: $attribute);

        return $className;
    }
}
