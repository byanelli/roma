<?php

namespace BYanelli\Roma\Eloquent;

use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Facades\Serializer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Creates and updates a model from a mapped object, serialized with Profile::storage(). Both
 * fill the model, so its $fillable and $guarded apply. A model lists the classes it accepts
 * with `@use MapsDataFromObjects<CreateRequest, UpdateRequest>`; `never` for one of them makes
 * PHPStan reject calls to that method.
 *
 * @template TCreateFrom of object = object
 * @template TUpdateFrom of object = object
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
     */
    public static function createFromObject(object $data): static
    {
        return static::query()->create(Serializer::toArray($data, operation: Operation::Create));
    }

    /**
     * Missing properties are left out, so their columns keep their values. An object in a
     * #[MergeOnUpdate] property is merged over its column's stored JSON object.
     *
     * @param  TUpdateFrom  $data
     *
     * @throws InvalidArgumentException
     */
    public function updateFromObject(object $data): bool
    {
        $attributes = Serializer::toArray($data, operation: Operation::Update);

        foreach (Serializer::mergePaths($data) as $path) {
            $attributes = $this->mergeOverStoredValue($attributes, $path, $data);
        }

        return $this->update($attributes);
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
