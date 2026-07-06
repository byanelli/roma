<?php

namespace BYanelli\Roma\Request\Attributes;

use Attribute;

/**
 * Overrides the request key of a property inside a nested request object. This
 * is the only way to relabel a nested property — needed e.g. for a key that
 * contains a literal dot, which cannot be expressed as a PHP property name.
 *
 * It is nested-only: a nested property can never declare its own source, so
 * source attributes (#[Input], #[Query], #[Body], …) are rejected there and
 * #[Key] carries the key instead. On a top-level property #[Key] throws — there
 * you pass the key to the source attribute directly, e.g. #[Input('a.b')].
 */
#[Attribute(Attribute::TARGET_PARAMETER | Attribute::TARGET_PROPERTY)]
readonly class Key implements ExplicitKeyAttribute
{
    public function __construct(public string $key) {}

    public function getKey(): string
    {
        return $this->key;
    }
}
