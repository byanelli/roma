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
 * The request body: the source of a property when mapping a request, and its destination when
 * serializing.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Body implements ExplicitKeyAttribute, FallsBackToBody, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Sources\Body;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function destination(): Destination
    {
        return Destination::Body;
    }

    /**
     * The key from getKey() applies.
     */
    public function destinationKey(): ?string
    {
        return null;
    }
}
