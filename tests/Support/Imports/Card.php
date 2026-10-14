<?php

namespace BYanelli\Roma\Tests\Support\Imports;

/**
 * Shares its short name with an imported class, which takes precedence in this namespace's PHPDoc.
 */
final readonly class Card
{
    public function __construct(public string $number) {}
}
