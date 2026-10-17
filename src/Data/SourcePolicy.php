<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Serialization\Destination;

/**
 * The rules of one mapping context, such as an HTTP request. A source is allowed when
 * the policy's input type is the source's input type or a subtype of it.
 */
interface SourcePolicy
{
    /**
     * Separates definitions built under different policies in the definition cache.
     */
    public function cacheKey(): string;

    /**
     * The class of the input this policy maps from.
     *
     * @return class-string
     */
    public function inputType(): string;

    /**
     * The source of a top-level property with no source attribute.
     */
    public function defaultSource(): Source;

    /**
     * Attributes added to a property of this type when it has no source attribute of its own.
     *
     * @return list<object>
     */
    public function attributesForType(string $typeName): array;

    /**
     * The source a property of this type always maps from. A source attribute on one throws.
     */
    public function sourceForType(string $typeName): ?Source;

    /**
     * The key of a property with no key attribute.
     */
    public function defaultKey(string $propertyName): string;

    /**
     * The key path of a property's key within its source or nested object.
     */
    public function keyPath(string $key): KeyPath;

    /**
     * Whether a top-level property may have a destination attribute, such as #[Status], for
     * this destination.
     */
    public function allowsDestination(Destination $destination): bool;
}
