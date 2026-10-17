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
     * @param  Request  $request
     * @return array<array-key, mixed>
     */
    protected function read(object $request): array
    {
        return $request->files->all();
    }
}
