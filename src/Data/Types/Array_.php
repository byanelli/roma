<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Type;

/**
 * `$keyType` is set for `array<K, T>`, which makes the array a map.
 */
final readonly class Array_ extends Type
{
    /**
     * @param  bool  $isList  declared as `list<T>`, so the input keys must be sequential
     */
    public function __construct(
        public Type $memberType,
        public bool $isList = false,
        public Integer|String_|null $keyType = null,
    ) {}
}
