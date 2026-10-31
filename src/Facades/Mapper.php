<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Config\ConfigMapper;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @see ObjectMapper
 */
class Mapper extends Facade
{
    /**
     * Maps a plain array, such as a tool's arguments or a stored value.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @param  array<array-key, mixed>  $data
     * @return T
     *
     * @throws MappingValidationException
     * @throws \ReflectionException
     */
    public static function fromArray(string $class, array $data, Operation $operation = Operation::None): object
    {
        return self::mapper()->fromArray($class, $data, $operation);
    }

    /**
     * Validates an object constructed directly, such as with `new`, and runs its guards.
     *
     * @template T of object
     *
     * @param  T  $object
     * @return T
     *
     * @throws MappingValidationException
     * @throws \ReflectionException
     */
    public static function validate(object $object, Operation $operation = Operation::None): object
    {
        return self::mapper()->validate($object, $operation);
    }

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

    /**
     * The facade root, which may be an instance passed to swap(). The type is only in PHPDoc,
     * since swap() accepts any object.
     *
     * @return ObjectMapper
     */
    private static function mapper(): object
    {
        return static::getFacadeRoot() ?? throw new RuntimeException('The Mapper facade has no root.');
    }

    protected static function getFacadeAccessor(): string
    {
        return ObjectMapper::class;
    }
}
