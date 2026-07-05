<?php

namespace BYanelli\Roma\Request\Attributes\Accessors;

use Attribute;
use BYanelli\Roma\Request\Attributes\Accessor;
use BYanelli\Roma\Request\Attributes\AttributeTarget;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types\Boolean;
use Illuminate\Http\Request;

/**
 * @see Request::ajax()
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Ajax extends Accessor
{
    public function __construct(private ?bool $mustBe = null) {}

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
