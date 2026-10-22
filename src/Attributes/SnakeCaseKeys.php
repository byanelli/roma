<?php

namespace BYanelli\Roma\Attributes;

use Attribute;

/**
 * Gives the properties of a class, and of its subclasses, the snake_case of their names as
 * their default keys: `$firstName` maps from and serializes to `first_name`. A key attribute
 * such as #[Key] or #[Query('page_num')] still sets a property's key.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class SnakeCaseKeys {}
