<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serialized;
use BYanelli\Roma\Serialization\Serializer as SerializerService;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Serialized serialize(object $object, ?Profile $profile = null, Operation $operation = Operation::None)
 * @method static array<string, mixed> toArray(object $object, ?Profile $profile = null, Operation $operation = Operation::None)
 * @method static array<string, mixed> toSafeArray(object $object)
 * @method static array<string, mixed> toResponseBody(object $object)
 * @method static array<array-key, mixed> normalize(array<array-key, mixed> $value, ?Profile $profile = null)
 * @method static bool overridesToArray(object $object)
 *
 * @see SerializerService
 */
class Serializer extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SerializerService::class;
    }
}
