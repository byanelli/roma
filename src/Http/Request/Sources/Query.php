<?php

namespace BYanelli\Roma\Http\Request\Sources;

final readonly class Query extends RequestSource
{
    public function getKey(): string
    {
        return 'query';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->query->all();
    }
}
