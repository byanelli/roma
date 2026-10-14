<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Serialization\Profile;
use BYanelli\Roma\Serialization\Serialized;
use BYanelli\Roma\Serialization\Serializer;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Serialized serialize(object $object, ?Profile $profile = null)
 * @method static array<string, mixed> toArray(object $object, ?Profile $profile = null)
 *
 * @see Serializer
 */
class Roma extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Serializer::class;
    }
}
