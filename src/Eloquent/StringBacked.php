<?php

namespace BYanelli\Roma\Eloquent;

/**
 * A value object stored as a single string, such as a postcode or an identifier with a
 * format. AsMappedObject stores it in a string column instead of as JSON.
 */
interface StringBacked
{
    /**
     * The value for the string, or null when the string isn't one.
     */
    public static function parse(string $value): ?static;

    /**
     * The string that parse() maps back to an equal value.
     */
    public function toString(): string;
}
