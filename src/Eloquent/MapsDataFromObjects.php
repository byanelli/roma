<?php

namespace BYanelli\Roma\Eloquent;

use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\ValidatedObjects;
use BYanelli\Roma\Facades\Serializer;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Creates, updates and upserts models from mapped objects, serialized with Profile::storage().
 * All methods fill a model, so its $fillable, $guarded and casts apply. A model lists the classes
 * it accepts with `@use MapsDataFromObjects<CreateRequest, UpdateRequest, UpsertRequest>`;
 * `never` for one of them makes PHPStan reject calls to that method. After
 * Mapper::requireValidation(), all methods throw UnvalidatedObjectException for an object Roma
 * didn't validate.
 *
 * @template TCreateFrom of object = object
 * @template TUpdateFrom of object = object
 * @template TUpsertFrom of object = object
 *
 * @phpstan-require-extends Model
 */
trait MapsDataFromObjects
{
    /**
     * Missing properties are left out, so their columns take their database defaults. A Missing
     * property that is required in a Create, such as one with #[RequiredOnCreate], throws.
     *
     * @param  TCreateFrom  $data
     *
     * @throws InvalidArgumentException
     * @throws UnvalidatedObjectException
     */
    public static function createFromObject(object $data): static
    {
        static::ensureValidated($data, __FUNCTION__, Operation::Create);

        return static::query()->create(Serializer::toArray($data, operation: Operation::Create));
    }

    /**
     * Missing properties are left out, so their columns keep their values. An object in a
     * #[MergeOnUpdate] property is merged over its column's stored JSON object.
     *
     * @param  TUpdateFrom  $data
     *
     * @throws InvalidArgumentException
     * @throws UnvalidatedObjectException
     */
    public function updateFromObject(object $data): bool
    {
        static::ensureValidated($data, __FUNCTION__, Operation::Update);

        $attributes = Serializer::toArray($data, operation: Operation::Update);

        foreach (Serializer::mergePaths($data) as $path) {
            $attributes = $this->mergeOverStoredValue($attributes, $path, $data);
        }

        return $this->update($attributes);
    }

    /**
     * Inserts a row from the object, or updates the row with the same $uniqueBy values. Missing
     * properties are left out, as in createFromObject(), and a Missing property that is required
     * in a Create throws. Returns the number of affected rows, as the driver reports it.
     *
     * @param  TUpsertFrom  $data
     * @param  list<string>  $uniqueBy
     * @param  ?list<string>  $update  The columns to update on conflict; null updates all of them.
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     * @throws UnvalidatedObjectException
     */
    public static function upsertFromObject(object $data, array $uniqueBy, ?array $update = null): int
    {
        return static::upsertObjects([$data], $uniqueBy, $update, __FUNCTION__);
    }

    /**
     * Upserts the objects in a single query, so a caller with many rows chunks them. The rows
     * must have the same columns, so a Missing property must be Missing in all objects or none.
     *
     * @param  iterable<TUpsertFrom>  $data
     * @param  list<string>  $uniqueBy
     * @param  ?list<string>  $update  The columns to update on conflict; null updates all of them.
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     * @throws UnvalidatedObjectException
     */
    public static function upsertManyFromObjects(iterable $data, array $uniqueBy, ?array $update = null): int
    {
        return static::upsertObjects($data, $uniqueBy, $update, __FUNCTION__);
    }

    /**
     * @param  iterable<object>  $data
     * @param  list<string>  $uniqueBy
     * @param  ?list<string>  $update
     * @param  string  $method  the public method called, for UnvalidatedObjectException
     *
     * @throws InvalidArgumentException
     * @throws QueryException
     * @throws UnvalidatedObjectException
     */
    private static function upsertObjects(iterable $data, array $uniqueBy, ?array $update, string $method): int
    {
        $query = static::query();
        $rows = [];

        foreach ($data as $object) {
            static::ensureValidated($object, $method, Operation::Create);

            $row = static::upsertRow($query->newModelInstance(), $object);

            if ($rows !== [] && array_keys($row) !== array_keys($rows[0])) {
                throw new InvalidArgumentException(sprintf(
                    'Roma cannot upsert %s rows with different columns: row %d has [%s], but row 0 has [%s].',
                    static::class,
                    count($rows),
                    implode(', ', array_keys($row)),
                    implode(', ', array_keys($rows[0])),
                ));
            }

            $rows[] = $row;
        }

        if ($rows === []) {
            return 0;
        }

        return $query->upsert($rows, $uniqueBy, $update);
    }

    /**
     * Does nothing unless Mapper::requireValidation() is on. A method that may insert a row needs
     * the object's #[OptionalOnUpdate] and #[RequiredOnCreate] properties checked as required.
     *
     * @throws UnvalidatedObjectException
     * @throws \ReflectionException
     */
    private static function ensureValidated(object $data, string $method, Operation $operation): void
    {
        $validatedObjects = Container::getInstance()->make(ValidatedObjects::class);

        if (! $validatedObjects->isRequired()) {
            return;
        }

        $validatedFor = $validatedObjects->operationOf($data);
        $method = static::class."::{$method}()";

        if ($validatedFor === null) {
            throw UnvalidatedObjectException::notValidated($data, $method);
        }

        if ($operation === Operation::Update) {
            return;
        }

        if ($validatedFor === Operation::Update) {
            throw UnvalidatedObjectException::validatedForUpdate($data, $method);
        }

        if ($validatedFor === Operation::None && $validatedObjects->hasRequiredOnCreate($data::class)) {
            throw UnvalidatedObjectException::validatedWithoutOperation($data, $method);
        }
    }

    /**
     * The query builder's upsert() skips casts and mass assignment, so the row is filled into a
     * model first. Its default attributes are cleared, so they don't overwrite columns on update.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private static function upsertRow(Model $model, object $data): array
    {
        $model->setRawAttributes([]);
        $model->fill(Serializer::toArray($data, operation: Operation::Create));

        $row = $model->getAttributes();
        ksort($row);

        return $row;
    }

    /**
     * Reads the raw attribute, since a cast may fill a null column with a default.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    private function mergeOverStoredValue(array $attributes, KeyPath $path, object $data): array
    {
        $column = $path->segments[0];
        $fail = fn (string $reason) => new InvalidArgumentException(sprintf(
            "Roma cannot merge %s's %s into %s's %s, since %s.",
            $data::class,
            $path->toKey(),
            InvalidAttributeValue::describeModel($this),
            $column,
            $reason,
        ));

        $value = $path->getFrom($attributes);

        if (! is_array($value)) {
            throw $fail('the object does not serialize to an array');
        }

        if (! $this->isJsonCastable($column) && ! $this->isClassCastable($column)) {
            throw $fail('the attribute has no array, JSON or class cast');
        }

        $raw = $this->getAttributes();

        if (! array_key_exists($column, $raw)) {
            throw $fail('the attribute is not loaded');
        }

        $stored = is_string($raw[$column]) ? json_decode($raw[$column], true) : $raw[$column];

        if ($raw[$column] !== null && ! is_array($stored)) {
            throw $fail('its stored value is not a JSON object');
        }

        $current = is_array($stored) ? new KeyPath(array_slice($path->segments, 1))->getFrom($stored) : null;

        if (is_array($current)) {
            $path->setIn($attributes, array_replace($current, $value));
        }

        return $attributes;
    }
}
