<?php

namespace BYanelli\Roma\Http\Attributes;

use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Request\Sources;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract readonly class Accessor implements ErrorKeyAttribute, KeyAttribute, RulesAttribute, SourceAttribute
{
    public function getKey(): string
    {
        return Str::camel(class_basename($this));
    }

    public function getErrorKey(): string
    {
        return $this->getSource()->getKey();
    }

    public function getSource(): Source
    {
        return new Sources\Accessor($this);
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return [];
    }

    public function read(Request $request): mixed
    {
        return $request->{$this->getKey()}();
    }
}
