<?php

namespace BYanelli\Roma\Serialization;

use BYanelli\Roma\Facades\Serializer;

/**
 * Serializes the object with the Roma serializer: toArray() with the storage profile, and
 * jsonSerialize() with the response profile. Use on a class that implements Arrayable, and
 * JsonSerializable where Laravel calls json_encode on the object directly.
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
     * The storage array without #[\SensitiveParameter] properties, for logs and other output
     * that leaves the system.
     *
     * @return array<array-key, mixed>
     */
    public function toSafeArray(): array
    {
        return $this->normalizedArray(Profile::storage()->withoutSensitive());
    }

    /**
     * The body as a response would send it.
     *
     * @return array<array-key, mixed>
     */
    public function toResponseBody(): array
    {
        return $this->normalizedArray(Profile::response());
    }

    /**
     * Laravel calls this for an object returned from a controller or passed to response()->json().
     *
     * @return array<array-key, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toResponseBody();
    }

    /**
     * @return array<array-key, mixed>
     */
    private function normalizedArray(Profile $profile): array
    {
        $array = $this->toArray($profile);

        return Serializer::overridesToArray($this) ? Serializer::normalize($array, $profile) : $array;
    }
}
