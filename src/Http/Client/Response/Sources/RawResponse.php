<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Http\Client\Response\ResponseInput;
use Illuminate\Http\Client\Response;

/**
 * Laravel's response, for a constructor parameter typed as Illuminate\Http\Client\Response.
 */
final readonly class RawResponse extends ResponseSource
{
    public function getKey(): string
    {
        return 'response';
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function isInjected(): bool
    {
        return true;
    }

    /**
     * @param  ResponseInput  $input
     */
    protected function read(object $input): Response
    {
        return $input->response;
    }
}
