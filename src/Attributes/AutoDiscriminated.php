<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Makes an interface or abstract class a discriminated union of all concrete classes that
 * extends or implements it in the roma.discover directories. Otherwise it behaves as
 * #[Discriminated]. A production deploy must run `php artisan optimize` or `roma:cache`
 * after a member is added, since the cached member list is used as is.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class AutoDiscriminated
{
    public function __construct(public string $key = 'type') {}
}
