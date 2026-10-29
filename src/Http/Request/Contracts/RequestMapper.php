<?php

namespace BYanelli\Roma\Http\Request\Contracts;

use Illuminate\Validation\ValidationException;

interface RequestMapper
{
    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     */
    public function mapRequest(string $className);

    /**
     * Maps the JSON or form body as Mapper::fromArray() maps an array. POST is a Create, and PUT
     * and PATCH are an Update. Errors are keyed and worded as Laravel's validator does.
     *
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     */
    public function mapBody(string $className);
}
