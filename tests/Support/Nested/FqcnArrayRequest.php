<?php

namespace BYanelli\Roma\Tests\Support\Nested;

readonly class FqcnArrayRequest
{
    // The element type is written as a fully-qualified name (a different
    // namespace than this class), the exact shape bug 4's regex must parse.
    /** @var array<BYanelli\Roma\Tests\Support\NamespacedItem> */
    public array $items;
}
