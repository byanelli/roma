<?php

namespace BYanelli\Roma\Request\Attributes\Headers;

use Attribute;
use BYanelli\Roma\Request\Attributes\AttributeTarget;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Enums\ContentType as ContentTypeEnum;
use Illuminate\Validation\Rule;

#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS)]
readonly class ContentType extends Header
{
    const string APPLICATION_JSON = 'application/json';

    /**
     * @var array<array-key, string>
     */
    protected array $mustBe;

    public function __construct(string|ContentTypeEnum ...$mustBe)
    {
        parent::__construct('Content-Type');

        $this->mustBe = array_map(
            fn (string|ContentTypeEnum $value) => $value instanceof ContentTypeEnum ? $value->value : $value,
            $mustBe,
        );
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
