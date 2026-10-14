<?php

namespace BYanelli\Roma\Serialization;

use BYanelli\Roma\Data\Discriminator;
use BYanelli\Roma\Data\Type;

/**
 * A position in a property's type where a value is written with its discriminator.
 *
 * @internal
 */
final readonly class DiscriminatedValue extends Type
{
    /**
     * @param  string  $subject  the property or contract the union is declared on, for error messages
     */
    public function __construct(
        public Discriminator $discriminator,
        public string $subject,
    ) {}
}
