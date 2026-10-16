<?php

namespace BYanelli\Roma\Http\Request\Sources;

final readonly class Body extends RequestSource
{
    public function getKey(): string
    {
        return 'body';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->isJson() ? $input->json()->all() : $input->request->all();
    }
}
