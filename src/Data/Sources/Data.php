<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\ArrayInput;
use BYanelli\Roma\Data\Source;

/**
 * The whole plain array mapped with ArrayPolicy.
 *
 * @internal
 *
 * @extends Source<ArrayInput>
 */
final readonly class Data extends Source
{
    public function getKey(): string
    {
        return 'data';
    }

    public function inputType(): string
    {
        return ArrayInput::class;
    }

    public function checkedKeys(object $input): ?array
    {
        return null;
    }

    /**
     * @param  ArrayInput  $input
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->data;
    }
}
