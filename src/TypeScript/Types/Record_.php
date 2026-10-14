<?php

namespace BYanelli\Roma\TypeScript\Types;

use BYanelli\Roma\TypeScript\Type;

readonly class Record_ extends Type
{
    public function __construct(
        public Number|String_ $keyType,
        public Type $valueType,
    ) {}
}
