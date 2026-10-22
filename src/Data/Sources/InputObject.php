<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\Source;

/**
 * The input itself, such as the HTTP request, for a property whose #[MapUsing] callable takes
 * the input.
 */
final readonly class InputObject extends Source
{
    /**
     * @param  class-string  $type  the class of the callable's parameter
     */
    public function __construct(private string $type) {}

    public function getKey(): string
    {
        return 'input object';
    }

    public function inputType(): string
    {
        return $this->type;
    }

    public function isSingleValue(): bool
    {
        return true;
    }

    public function isInjected(): bool
    {
        return true;
    }

    public function isSameAs(Source $other): bool
    {
        return parent::isSameAs($other) && $other->inputType() === $this->type;
    }

    protected function read(object $input): object
    {
        return $input;
    }
}
