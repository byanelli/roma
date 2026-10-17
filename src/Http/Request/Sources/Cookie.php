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
     * @param  Request  $request
     * @return array<array-key, mixed>
     */
    protected function read(object $request): array
    {
        return $request->cookies->all();
    }
}
