<?php

namespace BYanelli\Roma\Eloquent;

/**
 * A StringBacked class with a value for a null column. AsMappedObject's defaultWhenNull option
 * requires it for a StringBacked class.
 */
interface HasDefault
{
    /**
     * The value a null column reads as.
     */
    public static function default(): static;
}
