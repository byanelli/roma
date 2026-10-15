<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

final readonly class File extends RequestSource
{
    public function getKey(): string
    {
        return 'file';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function readRequest(Request $request): array
    {
        return $request->files->all();
    }
}
