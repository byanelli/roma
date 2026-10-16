<?php

namespace BYanelli\Roma\Data;

/**
 * A plain array, mapped with ArrayPolicy. Use Facades\Mapper::fromArray().
 *
 * @internal
 */
readonly class ArrayInput
{
    /**
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(public array $data) {}
}
