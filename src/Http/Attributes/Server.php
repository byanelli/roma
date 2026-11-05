<?php

namespace BYanelli\Roma\Http\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Request\Sources;

/**
 * A server variable, as `$request->server($key)` returns it. The key is exact and case-sensitive.
 * Which variables exist depends on the web server.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Server implements ExplicitKeyAttribute, SourceAttribute
{
    public function __construct(public string $key) {}

    public function getSource(): Source
    {
        return new Sources\Server;
    }

    public function getKey(): string
    {
        return $this->key;
    }
}
