<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Serialization\DestinationAttribute;
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
        Present::class,
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
