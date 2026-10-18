<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use RuntimeException;

/**
 * Splits a string value of an array property on the delimiter, such as a comma-separated list
 * from an environment variable: "a, b,,c" maps as ['a', 'b', 'c']. Items are trimmed and empty
 * ones dropped. An array value is used as it is. On a property that isn't an array, this
 * throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Delimited
{
    /**
     * @var non-empty-string
     */
    public string $delimiter;

    public function __construct(string $delimiter = ',')
    {
        if ($delimiter === '') {
            throw new RuntimeException('Roma #[Delimited] needs a delimiter, such as ",".');
        }

        $this->delimiter = $delimiter;
    }

    /**
     * @return list<string>
     */
    public function split(string $value): array
    {
        return array_values(array_filter(
            array_map(trim(...), explode($this->delimiter, $value)),
            fn (string $item) => $item !== '',
        ));
    }
}
