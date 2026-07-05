<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;
use BYanelli\Roma\Request\Data\Source;
use BYanelli\Roma\Request\Data\Sources;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types\String_;
use Illuminate\Support\Str;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Header implements ErrorKeyAttribute, KeyAttribute, RulesAttribute, SourceAttribute
{
    public function __construct(public string $name) {}

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
}
