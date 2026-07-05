<?php

namespace BYanelli\Roma\Request\Attributes\Headers;

use Attribute;
use BYanelli\Roma\Request\Attributes\AttributeTarget;
use BYanelli\Roma\Request\Attributes\Header;
use Illuminate\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
readonly class ContentType extends Header
{
    const string APPLICATION_JSON = 'application/json';
    // todo: more

    /**
     * @var array<array-key, string>
     */
    protected array $mustBe;

    public function __construct(string ...$mustBe)
    {
        parent::__construct('Content-Type');

        $this->mustBe = $mustBe;
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        return ! empty($this->mustBe)
            ? [Rule::in($this->mustBe)]
            : [];
    }
}
