<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

final readonly class Date extends Type
{
    /**
     * The concrete DateTimeInterface class the value is assigned to, or null
     * when the property is typed as the DateTimeInterface interface itself.
     *
     * @param  class-string<\DateTimeInterface>|null  $class
     * @param  ?string  $format  from #[DateFormat]: the input must match it exactly
     */
    public function __construct(
        public ?string $class = null,
        public ?string $format = null,
    ) {}
}
