<?php

namespace BYanelli\Roma\Data\Types;

use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\RootSource;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Values\HasValidationRules;
use BYanelli\Roma\Data\Values\ParsesStringValue;

final readonly class Class_ extends Type
{
    /**
     * @param  class-string  $class
     * @param  list<Property>  $properties
     */
    public function __construct(
        public string $class,
        public array $properties,
    ) {}

    /**
     * The root sources the class's mapped properties read from, keyed by source key. A nested
     * object's properties are relative to it, so it has none.
     *
     * @return array<string, RootSource>
     */
    public function rootSources(): array
    {
        $sources = [];

        foreach ($this->properties as $property) {
            $root = $property->getRootSource();

            if (! $property->isVirtual && $root instanceof RootSource) {
                $sources[$root->getKey()] ??= $root;
            }
        }

        return $sources;
    }

    public function parsesStringValue(): bool
    {
        return is_a($this->class, ParsesStringValue::class, true);
    }

    public function defersStringParsing(): bool
    {
        return $this->parsesStringValue()
            && is_a($this->class, HasValidationRules::class, true);
    }
}
