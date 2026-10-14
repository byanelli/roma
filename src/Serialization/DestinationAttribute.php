<?php

namespace BYanelli\Roma\Serialization;

/**
 * A property attribute that writes the property somewhere other than the body.
 */
interface DestinationAttribute
{
    public function destination(): Destination;

    /**
     * The key at the destination, or null to use the property's body key.
     */
    public function destinationKey(): ?string;
}
