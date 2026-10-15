<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Serialization\Destination;

/**
 * The rules of one mapping context, such as an HTTP request. A root source is allowed when
 * the policy's payload type is the source's payload type or a subtype of it.
 */
interface SourcePolicy
{
    /**
     * Separates definitions built under different policies in the definition cache.
     */
    public function cacheKey(): string;

    /**
     * The payload this policy maps from.
     *
     * @return class-string<Payload>
     */
    public function payloadType(): string;

    /**
     * The source of a top-level property with no source attribute.
     */
    public function defaultSource(): RootSource;

    /**
     * Attributes added to a property of this type when it has no source attribute of its own.
     *
     * @return list<object>
     */
    public function attributesForType(string $typeName): array;

    /**
     * The source a property of this type always maps from. A source attribute on one throws.
     */
    public function sourceForType(string $typeName): ?RootSource;

    /**
     * Whether a top-level property may have a destination attribute, such as #[Status], for
     * this destination.
     */
    public function allowsDestination(Destination $destination): bool;
}
