<?php

namespace BYanelli\Roma\Request\Attributes;

use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types\Boolean;

abstract readonly class BooleanAccessor extends Accessor
{
    public function __construct(protected ?bool $mustBe = null) {}

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        if ($target == AttributeTarget::Class_ && is_null($this->mustBe)) {
            return ['accepted'];
        }

        return match ($this->mustBe) {
            true => ['accepted'],
            false => ['declined'],
            null => [],
        };
    }

    public function getType(): Type
    {
        return new Boolean;
    }
}
