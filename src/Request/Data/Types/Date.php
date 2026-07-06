<?php

namespace BYanelli\Roma\Request\Data\Types;

use BYanelli\Roma\Request\Data\Type;

final readonly class Date extends Type
{
    /**
     * The concrete DateTimeInterface class the value is assigned to, or null
     * when the property is typed as the DateTimeInterface interface itself.
     *
     * @param  class-string<\DateTimeInterface>|null  $class
     */
    public function __construct(public ?string $class = null) {}
}
