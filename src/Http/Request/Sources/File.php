<?php

namespace BYanelli\Roma\Http\Request\Sources;

final readonly class File extends RequestSource
{
    public function getKey(): string
    {
        return 'file';
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        return $input->files->all();
    }
}
