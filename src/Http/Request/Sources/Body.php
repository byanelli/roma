<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class Body extends RequestSource
{
    public function getOwnKey(): string
    {
        return 'body';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readRequest(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }
}
