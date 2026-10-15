<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

final readonly class Header extends RequestSource
{
    public function getOwnKey(): string
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
    protected function readRequest(Request $request): array
    {
        return collect($request->server->getHeaders())
            ->mapWithKeys(fn ($value, $key) => [Str::lower($key) => $value])
            ->all();
    }
}
