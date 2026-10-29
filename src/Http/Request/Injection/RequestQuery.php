<?php

namespace BYanelli\Roma\Http\Request\Injection;

use Attribute;
use BYanelli\Roma\Http\Request\Contracts\RequestMapper;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Container\ContextualAttribute;
use Illuminate\Validation\ValidationException;
use ReflectionParameter;

/**
 * Maps the request's query string onto the parameter's class as a plain array:
 * `fn (#[RequestQuery] Foo $foo) => ...`. The class can't read other parts of the request,
 * so it can also be mapped with Mapper::fromArray().
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class RequestQuery implements ContextualAttribute
{
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

        return $mapper->mapQuery(Request::parameterClass($parameter, 'RequestQuery'));
    }
}
