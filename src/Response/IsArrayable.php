<?php

namespace BYanelli\Roma\Response;

use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Serialization\Profile;

/**
 * Serializes the object with the Roma serializer and the response profile.
 * Use on a class that implements Arrayable, and JsonSerializable where
 * Laravel calls json_encode on the object directly.
 */
trait IsArrayable
{
    /**
     * The serializer passes its profile when this object is nested in another, so an
     * override that calls Serializer::toArray($this, $profile) keeps the caller's settings.
     *
     * @return array<string, mixed>
     */
    public function toArray(?Profile $profile = null): array
    {
        return Serializer::toArray($this, $profile);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
