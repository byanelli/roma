<?php

namespace BYanelli\Roma\Facades;

use BYanelli\Roma\Config\ConfigMapper;
use BYanelli\Roma\Config\ConfigValidationException;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\ValidatedObjects;
use BYanelli\Roma\Eloquent\MapsDataFromObjects;
use BYanelli\Roma\Eloquent\UnvalidatedObjectException;
use Illuminate\Contracts\Foundation\Application;
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
        return self::app()->make(ConfigMapper::class)->map($class, $prefix);
    }

    /**
     * Makes the MapsDataFromObjects methods throw UnvalidatedObjectException for an object that
     * Roma didn't map or validate, as in `Mapper::requireValidation(! app()->isProduction())`.
     * Off by default.
     *
     * @see MapsDataFromObjects
     * @see UnvalidatedObjectException
     */
    public static function requireValidation(bool $require = true): void
    {
        self::validatedObjects()->require($require);
    }

    /**
     * Records an object as validated under `$operation` without checking it, for test fixtures.
     * Prefer validate(), which also checks the fixture.
     *
     * @template T of object
     *
     * @param  T  $object
     * @return T
     */
    public static function markValidated(object $object, Operation $operation = Operation::None): object
    {
        self::validatedObjects()->record($object, $operation);

        return $object;
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

    /**
     * Resolved from the container, not through the facade root, so it is the same after swap().
     */
    private static function validatedObjects(): ValidatedObjects
    {
        return self::app()->make(ValidatedObjects::class);
    }

    private static function app(): Application
    {
        return static::getFacadeApplication() ?? throw new RuntimeException('The Mapper facade has no application.');
    }

    protected static function getFacadeAccessor(): string
    {
        return ObjectMapper::class;
    }
}
