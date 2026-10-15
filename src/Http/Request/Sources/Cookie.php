<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class Cookie extends RequestSource
{
    public function getKey(): string
    {
        return 'cookie';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readRequest(Request $request): array
    {
        return $request->cookies->all();
    }
}
