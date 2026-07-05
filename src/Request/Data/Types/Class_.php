<?php

namespace BYanelli\Roma\Request\Data\Types;

use BYanelli\Roma\Request\Data\Property;
use BYanelli\Roma\Request\Data\Type;

final readonly class Class_ extends Type
{
    /**
     * @param  class-string  $class
     * @param  list<Property>  $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
    ) {}
}
