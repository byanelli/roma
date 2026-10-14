<?php

namespace BYanelli\Roma\Request\Sources;

use BYanelli\Roma\Data\Source;
use Illuminate\Support\Str;

final readonly class Header extends Source
{
    public function __construct()
    {
        parent::__construct();
    }

    public function getOwnKey(): string
    {
        return 'header';
    }

    /**
     * Header names are looked up snake-cased, e.g. "Content-Type" as "content_type".
     */
    public function normalizeKey(string $key): string
    {
        return Str::of($key)->camel()->snake()->toString();
    }
}
