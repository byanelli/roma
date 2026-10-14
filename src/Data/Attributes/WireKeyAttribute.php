<?php

namespace BYanelli\Roma\Data\Attributes;

interface WireKeyAttribute
{
    /**
     * The key the property appears under on the wire, when it differs from the
     * lookup key, e.g. "X-Api-Key" for a header looked up as "x_api_key".
     */
    public function getWireKey(): string;
}
