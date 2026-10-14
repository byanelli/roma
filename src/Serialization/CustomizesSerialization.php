<?php

namespace BYanelli\Roma\Serialization;

use ReflectionProperty;

/**
 * Implemented by a class whose serialization depends on runtime state.
 */
interface CustomizesSerialization
{
    /**
     * The format for the property's dates, or null to use its #[DateFormat] or the profile default.
     */
    public function dateFormat(ReflectionProperty $property, Profile $profile): ?string;
}
