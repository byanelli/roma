<?php

namespace BYanelli\Roma\Eloquent;

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
     * Missing properties are left out, so their columns keep their values.
     *
     * @param  TUpdateFrom  $data
     *
     * @throws InvalidArgumentException
     */
    public function updateFromObject(object $data): bool
    {
        return $this->update(Serializer::toArray($data, operation: Operation::Update));
    }
}
