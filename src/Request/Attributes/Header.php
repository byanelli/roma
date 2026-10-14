<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\String_;
use BYanelli\Roma\Request\Sources;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use Illuminate\Support\Str;

/**
 * A header: the source of a property when mapping a request, and its destination when
 * serializing, where the property is left out of the body.
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Header implements DestinationAttribute, ErrorKeyAttribute, KeyAttribute, RulesAttribute, SourceAttribute, WireKeyAttribute
{
    public function __construct(public string $name) {}

    public function getWireKey(): string
    {
        return $this->name;
    }

    public function getKey(): string
    {
        // We need to combine both to turn e.g. "Content-Type" into "content_type"
        return Str::snake(Str::camel($this->name));
    }

    public function getErrorKey(): string
    {
        // Errors reference the header by its real, un-normalized name.
        return "{$this->getSource()->getKey()}.{$this->name}";
    }

    public function getSource(): Source
    {
        return new Sources\Header;
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return [];
    }

    public function getType(): Type
    {
        return new String_;
    }

    public function getFullKey(): string
    {
        return "{$this->getSource()->getKey()}.{$this->getKey()}";
    }

    public function destination(): Destination
    {
        return Destination::Header;
    }

    public function destinationKey(): string
    {
        return $this->name;
    }
}
