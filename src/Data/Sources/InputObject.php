<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;

/**
 * The input itself, as SourcePolicy::mapFromInputValue() gives it, for a property with
 * #[MapFromInput].
 */
final readonly class InputObject extends Source
{
    public function __construct(private SourcePolicy $policy) {}

    public function getKey(): string
    {
        return 'input object';
    }

    public function inputType(): string
    {
        return $this->policy->inputType();
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function isInjected(): bool
    {
        return true;
    }

    protected function read(object $input): mixed
    {
        return $this->policy->mapFromInputValue($input);
    }
}
