<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Http\Client\Response\ResponseInput;

/**
 * The response headers. Names are compared without case, hyphens or underscores, since
 * #[Header] normalizes its name to snake case and servers send names in any case.
 */
final readonly class Header extends ResponseSource
{
    public function getKey(): string
    {
        return 'header';
    }

    public function nestsKeys(): bool
    {
        return false;
    }

    public function normalizeKey(string $key): string
    {
        return strtolower(str_replace(['-', '_'], '', $key));
    }

    /**
     * A header sent more than once is joined with commas.
     *
     * @param  ResponseInput  $input
     * @return array<string, string>
     */
    protected function read(object $input): array
    {
        $headers = [];

        foreach ($input->response->headers() as $name => $values) {
            $headers[$this->normalizeKey((string) $name)] = implode(', ', (array) $values);
        }

        return $headers;
    }
}
