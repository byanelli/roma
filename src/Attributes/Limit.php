<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\MapTransform;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Cuts a string property to `$limit` characters after validation with Laravel's Str::limit(),
 * which appends `$end` to a cut string, so the result can be longer than `$limit`. A string
 * that fits and null pass through; any other value throws.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final readonly class Limit implements MapTransform
{
    public function __construct(public int $limit, public string $end = '...') {}

    public function mapValue(mixed $value): ?string
    {
        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('Roma #[Limit] applies to strings, but the value is '.get_debug_type($value).'.');
        }

        return $value === null ? null : Str::limit($value, $this->limit, $this->end);
    }
}
