<?php

namespace BYanelli\Roma\Http\Request\Sources;

final readonly class Cookie extends RequestSource
{
    public function getKey(): string
    {
        return 'cookie';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->cookies->all();
    }
}
