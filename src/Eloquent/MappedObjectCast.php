<?php

namespace BYanelli\Roma\Eloquent;

use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\ValidatedObjects;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\Profile;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Casts a JSON object column to a class with Mapper::fromArray(), and stores the class with
 * Profile::storage(). The model's toArray() and toJson() leave out sensitive values. After
 * Mapper::preventStoringUnvalidatedObjects(), setting an object Roma didn't validate throws
 * UnvalidatedObjectException. Created by Mapped.
 *
 * @internal
 *
 * @template T of object
 *
 * @implements CastsAttributes<?T, T|array<array-key, mixed>|null>
 */
final class MappedObjectCast implements CastsAttributes, SerializesCastableAttributes
{
    // A cached object is written back through set() on save, which would rewrite the stored JSON or fill a null column.
    public bool $withoutObjectCaching = true;

    /**
     * @param  class-string<T>  $class
     */
    public function __construct(
        private readonly string $class,
        private readonly bool $defaultWhenNull = false,
        private readonly bool $nullWhenInvalid = false,
    ) {}

    /**
     * @return ?T
     *
     * @throws InvalidAttributeValue
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?object
    {
        if ($value === null && ! $this->defaultWhenNull) {
            return null;
        }

        $data = match (true) {
            $value === null => [],
            is_string($value) => json_decode($value, true),
            default => null,
        };

        if (! is_array($data) || (is_string($value) && $this->isNonEmptyJsonList($value, $data))) {
            return $this->invalid($model, $key, 'it is not a JSON object');
        }

        try {
            return Mapper::fromArray($this->class, $data);
        } catch (MappingValidationException $e) {
            return $this->invalid($model, $key, $e->getMessage(), $e);
        }
    }

    /**
     * @param  T|array<array-key, mixed>|null  $value
     *
     * @throws MappingValidationException
     * @throws UnvalidatedObjectException
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value) && $value !== [] && array_is_list($value)) {
            throw $this->unacceptable($model, $key, 'a non-empty list');
        }

        if (is_array($value)) {
            $value = Mapper::fromArray($this->class, $value);
        } elseif (! $value instanceof $this->class) {
            throw $this->unacceptable($model, $key, get_debug_type($value));
        } else {
            $this->ensureValidated($value, $model, $key);
        }

        return json_encode(Serializer::toArray($value, Profile::storage()), JSON_THROW_ON_ERROR);
    }

    /**
     * @param  T  $value
     * @return array<string, mixed>
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): array
    {
        return Serializer::toSafeArray($value);
    }

    /**
     * Decoding with `true` gives `{"0":"x"}` the same array as `["x"]`, so this also checks the
     * JSON. `[]` isn't treated as a list, since it is the same array as `{}`.
     *
     * @param  array<array-key, mixed>  $data
     */
    private function isNonEmptyJsonList(string $json, array $data): bool
    {
        return $data !== [] && array_is_list($data) && str_starts_with(ltrim($json), '[');
    }

    /**
     * The attribute is read with Operation::None, so an object validated for a Create or None reads
     * back. An Update lets required properties be Missing.
     *
     * @throws UnvalidatedObjectException
     */
    private function ensureValidated(object $value, Model $model, string $key): void
    {
        $validatedObjects = Container::getInstance()->make(ValidatedObjects::class);

        if (! $validatedObjects->preventsStoring()) {
            return;
        }

        $operation = $validatedObjects->operationOf($value);
        $attribute = $model::class.'::$'.$key;

        if ($operation === null) {
            throw UnvalidatedObjectException::notValidated($value, $attribute);
        }

        if ($operation === Operation::Update) {
            throw UnvalidatedObjectException::validatedForUpdate($value, $attribute);
        }
    }

    private function unacceptable(Model $model, string $key, string $given): InvalidArgumentException
    {
        return new InvalidArgumentException(
            InvalidAttributeValue::describeModel($model)."'s {$key} takes a {$this->class}, an array or null, not {$given}."
        );
    }

    /**
     * @throws InvalidAttributeValue
     */
    private function invalid(Model $model, string $key, string $reason, ?MappingValidationException $previous = null): null
    {
        return $this->nullWhenInvalid ? null : throw InvalidAttributeValue::on($model, $key, $reason, $previous);
    }
}
