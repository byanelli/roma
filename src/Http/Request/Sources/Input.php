<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Source;
use Illuminate\Http\Request;

final readonly class Input extends RequestSource
{
    public function getKey(): string
    {
        return 'input';
    }

    /**
     * The input and the body both read the body's keys.
     */
    public function sharesKeysWith(Source $other): bool
    {
        return $other instanceof Input || $other instanceof Body;
    }

    /**
     * The body's keys: query string keys are never unknown.
     *
     * @param  Request  $input
     */
    public function checkedKeys(object $input): array
    {
        return $this->bodyKeys($input);
    }

    /**
     * @param  Request  $request
     * @return array<array-key, mixed>
     */
    protected function read(object $request): array
    {
        return $request->input();
    }
}
