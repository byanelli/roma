<?php

namespace BYanelli\Roma\Http\Request\Sources;

final readonly class Input extends RequestSource
{
    public function getKey(): string
    {
        return 'input';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->input();
    }
}
