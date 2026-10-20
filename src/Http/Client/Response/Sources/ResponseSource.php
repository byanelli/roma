<?php

namespace BYanelli\Roma\Http\Client\Response\Sources;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Client\Response\ResponseInput;

/**
 * A source that reads part of an HTTP client response.
 *
 * @extends Source<ResponseInput>
 */
abstract readonly class ResponseSource extends Source
{
    public function inputType(): string
    {
        return ResponseInput::class;
    }
}
