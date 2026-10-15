<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Data\ObjectMapper;
use Illuminate\Support\Facades\Facade;

/**
 * @method static T fromArray<T of object>(class-string<T> $class, array<array-key, mixed> $data)
 *
 * @see ObjectMapper
 */
class Mapper extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ObjectMapper::class;
    }
}
