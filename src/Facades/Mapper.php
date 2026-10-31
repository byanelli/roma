<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Config\ConfigMapper;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static T fromArray<T of object>(class-string<T> $class, array<array-key, mixed> $data, Operation $operation = Operation::None)
 * @method static T validate<T of object>(T $object, Operation $operation = Operation::None)
 *
 * @see ObjectMapper
 */
class Mapper extends Facade
{
    /**
     * Maps a config object from the config repository.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  ?string  $prefix  overrides the class's #[ConfigObject] prefix
     * @return T
     *
     * @throws ConfigValidationException
     * @throws \ReflectionException
     */
    public static function fromConfig(string $class, ?string $prefix = null): object
    {
        $app = static::getFacadeApplication() ?? throw new RuntimeException('The Mapper facade has no application.');

        return $app->make(ConfigMapper::class)->map($class, $prefix);
    }

    protected static function getFacadeAccessor(): string
    {
        return ObjectMapper::class;
    }
}
