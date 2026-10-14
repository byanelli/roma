<?php

namespace BYanelli\Roma\Data;

/**
 * The sources available in one mapping context, such as an HTTP request, and
 * the rules for placing a property in one of them.
 */
interface SourcePolicy
{
    /**
     * Separates definitions built under different policies in the definition cache.
     */
    public function cacheKey(): string;

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

    public function allowsSource(Source $source): bool;
}
