<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Http\Client\Response\ResponseInput;

/**
 * The status code, for a #[Status] property.
 */
final readonly class Status extends ResponseSource
{
    public function getKey(): string
    {
        return 'status';
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function nestsKeys(): bool
    {
        return false;
    }

    /**
     * @param  ResponseInput  $input
     */
    protected function read(object $input): int
    {
        return $input->response->status();
    }
}
