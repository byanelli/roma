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
     * Each header as `$request->header($name)` returns it, which for a repeated header is the
     * first value. A header whose value is null is absent.
     *
     * @param  Request  $request
     * @return array<string, string>
     */
    protected function read(object $request): array
    {
        $headers = [];

        foreach ($request->headers->keys() as $name) {
            $value = $request->headers->get((string) $name);

            if ($value !== null) {
                $headers[$this->normalizeKey((string) $name)] = $value;
            }
        }

        return $headers;
    }
}
