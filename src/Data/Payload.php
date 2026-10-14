<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Types\Class_;

/**
 * The raw data a class is mapped from.
 */
interface Payload
{
    /**
     * The raw values keyed by root source key, e.g. ['input' => [...], 'header' => [...]].
     * `$class` is the class being mapped, for values that depend on its properties.
     *
     * @return array<string, mixed>
     */
    public function data(Class_ $class): array;
}
