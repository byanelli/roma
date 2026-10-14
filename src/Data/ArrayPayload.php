<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Sources\Data;
use BYanelli\Roma\Data\Types\Class_;

/**
 * A plain array, mapped with ArrayPolicy.
 */
readonly class ArrayPayload implements Payload
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(private array $data) {}

    public function data(Class_ $class): array
    {
        return [(new Data)->getKey() => $this->data];
    }
}
