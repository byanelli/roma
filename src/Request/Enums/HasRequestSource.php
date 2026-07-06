<?php

namespace BYanelli\Roma\Request\Enums;

use BYanelli\Roma\Request\Attributes\SourceAttribute;

/**
 * Implemented by request-metadata enums that know which request source they
 * map from. When a property is typed as one of these enums and carries no
 * explicit source attribute, Roma injects these attributes so the property
 * behaves as if the user had written them by hand.
 */
interface HasRequestSource
{
    /**
     * The source attribute instance(s) this enum is equivalent to.
     *
     * @return list<SourceAttribute>
     */
    public static function requestSourceAttributes(): array;
}
