<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class Header extends RequestSource
{
    public function getKey(): string
    {
        return 'header';
    }

    public function nestsKeys(): bool
    {
        return false;
    }

    /**
     * Header names are looked up snake-cased, e.g. "Content-Type" as "content_type".
     */
    public function normalizeKey(string $key): string
    {
        return Str::of($key)->camel()->snake()->toString();
    }

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    protected function read(object $request): array
    {
        return collect($request->server->getHeaders())
            ->mapWithKeys(fn ($value, $key) => [Str::lower($key) => $value])
            ->all();
    }
}
