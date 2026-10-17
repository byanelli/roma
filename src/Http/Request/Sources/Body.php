<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class Body extends RequestSource
{
    public function getKey(): string
    {
        return 'body';
    }

    /**
     * @param  Request  $request
     * @return array<array-key, mixed>
     */
    protected function read(object $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }
}
