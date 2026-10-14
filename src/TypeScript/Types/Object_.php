<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Property;
use BYanelli\Roma\TypeScript\Type;

/**
 * An inline object type such as `{ input: number; output?: number }`.
 */
readonly class Object_ extends Type
{
    /**
     * @param  list<Property>  $properties
     */
    public function __construct(public array $properties) {}
}
