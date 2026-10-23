<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Http\Client\Response\ResponseInput;

/**
 * The decoded JSON body. A body that isn't a JSON object or array has no keys.
 */
final readonly class Body extends ResponseSource
{
    public function getKey(): string
    {
        return 'body';
    }

    public function checkedKeys(object $input): ?array
    {
        return null;
    }

    /**
     * @param  ResponseInput  $input
     * @return array<array-key, mixed>
     */
    protected function read(object $input): array
    {
        $json = $input->response->json();

        return is_array($json) ? $json : [];
    }
}
