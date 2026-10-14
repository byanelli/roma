<?php

namespace BYanelli\Roma\Response;

use BYanelli\Roma\Facades\Roma;

/**
 * Serializes the object with the Roma serializer and the response profile.
 * Use on a class that implements Arrayable, and JsonSerializable where
 * Laravel calls json_encode on the object directly.
 */
trait IsArrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return Roma::toArray($this);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
