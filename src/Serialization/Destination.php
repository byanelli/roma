<?php

namespace BYanelli\Roma\Serialization;

/**
 * Where a serialized property's value is written.
 */
enum Destination
{
    case Body;
    case Header;
    case Status;
    case Query;
    case Path;

    /**
     * Whether a value written here has a key, which #[Key] may set.
     */
    public function hasKey(): bool
    {
        return $this !== self::Status;
    }
}
