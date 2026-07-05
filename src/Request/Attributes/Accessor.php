<?php

namespace BYanelli\Roma\Request\Attributes;

use BYanelli\Roma\Request\Data\Source;
use BYanelli\Roma\Request\Data\Sources\RequestObject_;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

abstract readonly class Accessor implements AccessorAttribute, KeyAttribute, RulesAttribute, SourceAttribute
{
    public function getKey(): string
    {
        return Str::camel(class_basename($this));
    }

    public function getFullKey(): string
    {
        return $this->getKey();
    }

    public function getSource(): Source
    {
        return new RequestObject_;
    }

    public function getAccessor(): Closure
    {
        return fn (Request $request) => $this->getFromRequest($request);
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return [];
    }

    protected function getFromRequest(Request $request): mixed
    {
        return $request->{$this->getKey()}();
    }
}
