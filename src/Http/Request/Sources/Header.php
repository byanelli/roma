<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Support\Str;

final readonly class Header extends RequestSource
{
    public function getKey(): string
    {
        return 'header';
    }

    /**
     * Header names are looked up snake-cased, e.g. "Content-Type" as "content_type".
     */
    public function normalizeKey(string $key): string
    {
        return Str::of($key)->camel()->snake()->toString();
    }

    /**
     * @return array<string, mixed>
     */
    protected function read(object $input): array
    {
        return collect($input->server->getHeaders())
            ->mapWithKeys(fn ($value, $key) => [Str::lower($key) => $value])
            ->all();
    }
}
