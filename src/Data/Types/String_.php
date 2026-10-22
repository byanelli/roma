<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

final readonly class String_ extends Type
{
    /**
     * @param  bool  $acceptsNumbers  whether an int or float input maps to its string form
     */
    public function __construct(public bool $acceptsNumbers = true) {}
}
