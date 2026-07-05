<?php

namespace BYanelli\Roma\Request\ContextualBinding;

use Attribute;
use BYanelli\Roma\Request\RequestMapper;
use Illuminate\Container\BoundMethod;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Validation\ValidationException;
use ReflectionNamedType;
use ReflectionParameter;

#[Attribute(Attribute::TARGET_PARAMETER)]
class Request implements ContextualAttribute
{
    /**
     * @throws ContextualBindingException
     * @throws \ReflectionException
     * @throws BindingResolutionException
     * @throws ValidationException
     */
    public static function resolve(self $attribute, Container $container, ?ReflectionParameter $parameter = null)
    {
        // Laravel 13+ passes the ReflectionParameter being resolved directly.
        // On Laravel 10/11 it is not supplied, so we recover it from the
        // container's call stack.
        $parameter ??= self::findParameterInBacktrace();

        $type = $parameter->getType();

        ($type instanceof ReflectionNamedType) ||
            throw new ContextualBindingException("the parameter \${$parameter->getName()} must be type-hinted with a class");

        class_exists($className = $type->getName()) ||
            throw new ContextualBindingException("$className does not exist");

        /** @var RequestMapper $mapper */
        $mapper = $container->make(RequestMapper::class);

        return $mapper->mapRequest($className);
    }

    /**
     * @throws ContextualBindingException
     */
    private static function findParameterInBacktrace(): ReflectionParameter
    {
        foreach (debug_backtrace() as $frame) {
            /** @see BoundMethod::addDependencyForCallParameter() */
            if (($frame['class'] ?? null) !== BoundMethod::class
                || ($frame['function'] ?? null) !== 'addDependencyForCallParameter') {
                continue;
            }

            $parameter = $frame['args'][1] ?? null;

            ($parameter instanceof ReflectionParameter) ||
                throw new ContextualBindingException('could not introspect container call stack');

            return $parameter;
        }

        throw new ContextualBindingException('could not find request parameter');
    }
}
