<?php

namespace BYanelli\Roma\Http\Request\Sources;

use Illuminate\Http\Request;

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
     * Header names are compared in lowercase with underscores for hyphens, e.g. "Content-Type"
     * as "content_type". `$request->header()` also treats a hyphen and an underscore as the same.
     */
    public function normalizeKey(string $key): string
    {
        return strtolower(str_replace('-', '_', $key));
    }

    /**
     * @param  Request  $request
     * @return array<string, mixed>
     */
    protected function read(object $request): array
    {
        return collect($request->server->getHeaders())
            ->mapWithKeys(fn ($value, $key) => [$this->normalizeKey($key) => $value])
            ->all();
    }
}
