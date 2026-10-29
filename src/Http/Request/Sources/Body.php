<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Source;
use Illuminate\Http\Request;

final readonly class Body extends RequestSource
{
    public function getKey(): string
    {
        return 'body';
    }

    /**
     * The input and the body both read the body's keys.
     */
    public function sharesKeysWith(Source $other): bool
    {
        return $other instanceof Input || $other instanceof Body;
    }

    /**
     * The body's keys.
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
        return self::body($request);
    }
}
