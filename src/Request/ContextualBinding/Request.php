<?php

namespace BYanelli\Roma\Request\ContextualBinding;

use Attribute;
use BYanelli\Roma\Request\Contracts\RequestMapper;
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
