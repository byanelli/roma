<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Http\Client\Response\ResponseInput;
use Throwable;

/**
 * The exception for the response, for a constructor parameter typed as Throwable, Exception or
 * Laravel's RequestException, usually passed on as `previous`.
 */
final readonly class Exception extends ResponseSource
{
    public function getKey(): string
    {
        return 'exception';
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
    protected function read(object $input): Throwable
    {
        return $input->exception;
    }
}
