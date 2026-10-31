<?php

namespace BYanelli\Roma\Eloquent;

use RuntimeException;

/**
 * An object passed to a MapsDataFromObjects method that Roma didn't validate, or didn't validate
 * for a Create when the method may insert a row. Thrown only after Mapper::requireValidation().
 */
class UnvalidatedObjectException extends RuntimeException
{
    /**
     * @param  string  $method  such as "App\Models\Contact::createFromObject()"
     */
    public static function notValidated(object $object, string $method): self
    {
        return new self(sprintf(
            'Roma cannot store %s with %s, since it was not validated. Map it with Roma, or pass it to Mapper::validate() first.',
            $object::class,
            $method,
        ));
    }

    /**
     * @param  string  $method  such as "App\Models\Contact::createFromObject()"
     */
    public static function validatedForUpdate(object $object, string $method): self
    {
        return new self(sprintf(
            'Roma cannot store %s with %s, since it was validated for an Update, which lets required properties be Missing. '
            .'Map it with Operation::Create, or pass it to Mapper::validate() with Operation::Create.',
            $object::class,
            $method,
        ));
    }

    /**
     * @param  string  $method  such as "App\Models\Contact::createFromObject()"
     */
    public static function validatedWithoutOperation(object $object, string $method): self
    {
        return new self(sprintf(
            'Roma cannot store %1$s with %2$s, since it was validated with Operation::None, and %1$s or a class nested in it has '
            .'#[RequiredOnCreate] properties, which only a Create requires. Validate it with Operation::Create, as in '
            .'Mapper::validate($object, Operation::Create) or Mapper::fromArray(%1$s::class, $data, Operation::Create).',
            $object::class,
            $method,
        ));
    }
}
