<?php

namespace BYanelli\Roma;

/**
 * The value of an #[Optional] constructor parameter typed `T|Missing` when its key is absent
 * from the input. The serializer omits a property with this value.
 */
final class Missing
{
    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self
    {
        return self::$instance ??= new self;
    }

    private function __clone() {}
}
