<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class Query extends RequestSource
{
    public function getKey(): string
    {
        return 'query';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readRequest(Request $request): array
    {
        return $request->query->all();
    }
}
