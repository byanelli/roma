<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use ReflectionNamedType;
use ReflectionType;
use RuntimeException;

/**
 * Rejects combinations of attributes on a property that have no meaning. Building a
 * definition and planning serialization both run these checks.
 *
 * @internal
 */
final class PropertyAttributes
{
    /**
     * The attribute interfaces and classes the checks read.
     */
    public const array CHECKED = [
        SourceAttribute::class,
        DestinationAttribute::class,
        ExplicitKeyAttribute::class,
        KeyAttribute::class,
        Optional::class,
        RulesAttribute::class,
    ];

    /**
     * @param  list<object>  $attributes
     * @param  string  $subject  e.g. `Order::$id`
     */
    public static function assertCombinable(array $attributes, string $subject): void
    {
        self::assertAtMostOne($attributes, SourceAttribute::class, 'source', $subject);
        self::assertAtMostOne($attributes, DestinationAttribute::class, 'destination', $subject);

        $keyed = array_values(array_filter($attributes, fn (object $attribute) => self::declaredKey($attribute) !== null));

        if (count($keyed) > 1) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" gets a key from both #[".class_basename($keyed[0]).'] and #['
                .class_basename($keyed[1]).']. Give the key in only one of them.'
            );
        }

        $destination = self::first($attributes, DestinationAttribute::class);

        if ($destination !== null && ! $destination->destination()->hasKey() && $keyed !== []) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has both #[".class_basename($destination).'] and #['.class_basename($keyed[0]).'], '
                .'but #['.class_basename($destination).'] is not written under a key. Remove #['.class_basename($keyed[0]).'].'
            );
        }

        if (self::first($attributes, Optional::class) !== null && self::first($attributes, Present::class) !== null) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has both #[Optional] and #[Present]. #[Present] requires the key and "
                .'#[Optional] allows it to be absent, so remove one of them.'
            );
        }
    }

    /**
     * #[Present] requires the key, so a default would never be used.
     *
     * @param  list<object>  $attributes
     */
    public static function assertPresentWithoutDefault(array $attributes, bool $hasDefault, string $subject): void
    {
        if ($hasDefault && self::first($attributes, Present::class) !== null) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has #[Present] and a default value, which is never used since #[Present] "
                .'requires the key. Remove the default or #[Present].'
            );
        }
    }

    /**
     * A status is always written, so its property is a plain int. A status that varies takes a
     * default, such as 200, instead of null or Missing.
     *
     * @param  list<object>  $attributes
     */
    public static function assertStatusType(array $attributes, ?ReflectionType $type, string $subject): void
    {
        $destination = self::first($attributes, DestinationAttribute::class);

        if ($destination?->destination() !== Destination::Status) {
            return;
        }

        if (! $type instanceof ReflectionNamedType || $type->getName() !== 'int' || $type->allowsNull()) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has #[".class_basename($destination).'], so its type must be int'
                .($type !== null ? ", not {$type}" : '').'. For a status that is usually the same, give the property a default such as 200.'
            );
        }
    }

    /**
     * A virtual (hooked, backing-less) property is never mapped. Attributes that only affect
     * serialization or TypeScript, such as #[Key] and #[Header], still apply.
     *
     * @param  list<object>  $attributes
     */
    public static function assertAllowedOnVirtual(array $attributes, string $subject): void
    {
        foreach ($attributes as $attribute) {
            if (($attribute instanceof SourceAttribute || $attribute instanceof RulesAttribute)
                && ! $attribute instanceof DestinationAttribute) {
                throw new RuntimeException(
                    "Roma property \"{$subject}\" is virtual (a hooked property with no backing value), so it is never "
                    .'mapped and #['.class_basename($attribute).'] has no effect. Remove it.'
                );
            }
        }
    }

    /**
     * Headers and the status belong to the top-level class.
     *
     * @param  list<object>  $attributes
     */
    public static function assertAllowedInNestedObject(array $attributes, string $subject): void
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof DestinationAttribute) {
                throw self::topLevelOnly($attribute::class, $subject);
            }
        }
    }

    public static function topLevelOnly(string $attribute, string $subject): RuntimeException
    {
        return new RuntimeException(
            "Roma property \"{$subject}\" has #[".class_basename($attribute).'] but is inside a nested object. '
            .'Headers and #[Status] can only be declared on the top-level class.'
        );
    }

    public static function dateFormatWithoutDate(string $subject): RuntimeException
    {
        return new RuntimeException(
            "Roma property \"{$subject}\" has #[DateFormat], but its type is not a date or an array, map or shape "
            .'of dates. A nested object sets the formats of its own dates.'
        );
    }

    /**
     * The key an attribute declares, if any. A KeyAttribute (e.g. a header or accessor) always
     * declares one; an ExplicitKeyAttribute (e.g. a source attribute or #[Key]) may leave it
     * null to use the property name.
     */
    public static function declaredKey(object $attribute): ?string
    {
        $key = match (true) {
            $attribute instanceof KeyAttribute => $attribute->getKey(),
            $attribute instanceof ExplicitKeyAttribute => $attribute->getKey(),
            default => null,
        };

        return $key === '' ? null : $key;
    }

    /**
     * The key from #[Key] or a source attribute such as #[Query('x')], which applies when
     * mapping and when serializing.
     *
     * @param  list<object>  $attributes
     */
    public static function explicitKey(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof ExplicitKeyAttribute && ($key = self::declaredKey($attribute)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @template T of object
     *
     * @param  list<object>  $attributes
     * @param  class-string<T>  $class
     * @return ?T
     */
    private static function first(array $attributes, string $class): ?object
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  list<object>  $attributes
     * @param  class-string  $class
     */
    private static function assertAtMostOne(array $attributes, string $class, string $kind, string $subject): void
    {
        $matching = array_values(array_filter($attributes, fn (object $attribute) => $attribute instanceof $class));

        if (count($matching) > 1) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" has more than one {$kind}: #[".class_basename($matching[0]).'] and #['
                .class_basename($matching[1]).']. Remove one of them.'
            );
        }
    }
}
