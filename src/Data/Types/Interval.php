<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;
use DateInterval;

/**
 * A CarbonInterval or DateInterval, mapped from a string such as "90 seconds" or "PT90S".
 */
final readonly class Interval extends Type
{
    /**
     * @param  class-string<DateInterval>  $class
     * @param  ?string  $unit  from #[Unit]: the unit of a number
     */
    public function __construct(
        public string $class,
        public ?string $unit = null,
    ) {}
}
