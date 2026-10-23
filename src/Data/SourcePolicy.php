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
     * The source of a top-level property or class-level constraint whose attributes give
     * `$declared` as its source and `$destination` as its destination, either of which may be
     * null, or null for the default source. A policy that reads the same parts from another kind
     * of input, such as an HTTP client response's headers, returns its own source.
     */
    public function sourceFor(?Source $declared, ?Destination $destination): ?Source;

    /**
     * The key of a property with no key attribute.
     */
    public function defaultKey(string $propertyName): string;

    /**
     * Whether a top-level property may have a destination attribute, such as #[Status], for
     * this destination.
     */
    public function allowsDestination(Destination $destination): bool;

    /**
     * Whether the input creates a resource, which makes the #[RequiredOnCreate] properties
     * required.
     */
    public function isCreate(object $input): bool;
}
