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
 * The query string: the source of a property when mapping a request, and its destination when
 * serializing a client request.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Query implements ExplicitKeyAttribute, FallsBackToBody, SourceAttribute
{
    public function __construct(public ?string $key = null) {}

    public function getSource(): Source
    {
        return new Sources\Query;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function destination(): Destination
    {
        return Destination::Query;
    }

    /**
     * The key from getKey() applies.
     */
    public function destinationKey(): ?string
    {
        return null;
    }
}
