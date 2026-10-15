<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class Input extends RequestSource
{
    public function getKey(): string
    {
        return 'input';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readRequest(Request $request): array
    {
        return $request->input();
    }
}
