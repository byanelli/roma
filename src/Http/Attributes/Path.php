<?php

namespace BYanelli\Roma\Http\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Request\Sources;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\FallsBackToBody;

/**
 * A route parameter when mapping a request, and a path template parameter when serializing a
 * client request. Its key never nests.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Path implements ExplicitKeyAttribute, FallsBackToBody, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Sources\Path;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function destination(): Destination
    {
        return Destination::Path;
    }

    /**
     * The key from getKey() applies.
     */
    public function destinationKey(): ?string
    {
        return null;
    }
}
