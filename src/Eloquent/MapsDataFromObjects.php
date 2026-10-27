<?php

namespace BYanelli\Roma\Eloquent;

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Missing;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Creates and updates a model from a mapped object, serialized with Profile::storage(). Both
 * fill the model, so its $fillable and $guarded apply.
 *
 * @phpstan-require-extends Model
 */
trait MapsDataFromObjects
{
    /**
     * Throws when a property of `$data` is Missing, so the database doesn't fill it.
     *
     * @throws InvalidArgumentException
     */
    public static function createFrom(object $data): static
    {
        $missing = array_keys(array_filter(get_object_vars($data), fn (mixed $value) => $value instanceof Missing));

        if ($missing !== []) {
            throw new InvalidArgumentException(sprintf(
                'Roma cannot create %s from %s, since %s %s Missing.',
                static::class,
                $data::class,
                implode(', ', array_map(fn (string $name) => '$'.$name, $missing)),
                count($missing) === 1 ? 'is' : 'are',
            ));
        }

        return static::query()->create(Serializer::toArray($data));
    }

    /**
     * Missing properties are left out, so their columns keep their values.
     */
    public function updateFrom(object $data): bool
    {
        return $this->update(Serializer::toArray($data));
    }
}
