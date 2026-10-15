<?php

namespace BYanelli\Roma\Data\Attributes;

use BYanelli\Roma\Attributes\AutoDiscriminated;
use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Key;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\Reflection\ReflectionParamOrProperty;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Serialization\Destination;
use BYanelli\Roma\Serialization\DestinationAttribute;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Collection;
use ReflectionNamedType;
use RuntimeException;

/**
 * The instantiated attributes of a parameter or property. Its checks reject combinations that
 * have no meaning; building a definition and planning serialization both run them.
 *
 * @internal
 *
 * @extends Collection<int, object>
 */
final class AttributesCollection extends Collection
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
     * @param  Arrayable<int, object>|iterable<int, object>|null  $items
     */
    public function __construct($items, private readonly ReflectionParamOrProperty $property)
    {
        parent::__construct($items);
    }

    /**
     * Checks the attribute names first, since PHP throws a generic Error when it instantiates an
     * attribute on a target the attribute doesn't allow.
     *
     * @param  ?list<class-string>  $classes  the attribute classes and interfaces to instantiate, or null for all
     */
    public static function instantiate(ReflectionParamOrProperty $property, ?array $classes = null): self
    {
        $attributes = $property->reflection->getAttributes();

        foreach ($attributes as $attribute) {
            if ($attribute->getName() === AutoDiscriminated::class) {
                throw new RuntimeException(
                    "Roma property \"{$property->printableName}\" has #[AutoDiscriminated], which applies only to an interface or abstract "
                    .'class, since its members are found and cached per contract. Put it on the interface or abstract class, '
                    .'or list the classes on the property with #[Discriminated([...])].'
                );
            }
        }

        $instances = [];

        foreach ($attributes as $attribute) {
            if ($classes === null || array_any($classes, fn (string $class) => is_a($attribute->getName(), $class, true))) {
                $instances[] = $attribute->newInstance();
            }
        }

        return new self($instances, $property);
    }

    /**
     * Collection methods that return a new collection, such as filter() and map(), call this.
     *
     * @param  Arrayable<int, object>|iterable<int, object>|null  $items
     */
    protected function newInstance($items = []): static
    {
        return new self($items, $this->property);
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    public function firstInstanceOf(string $class): ?object
    {
        foreach ($this->items as $attribute) {
            if ($attribute instanceof $class) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * @param  class-string  $class
     */
    public function hasInstanceOf(string $class): bool
    {
        return $this->firstInstanceOf($class) !== null;
    }

    public function sourceAttribute(): ?SourceAttribute
    {
        return $this->firstInstanceOf(SourceAttribute::class);
    }

    public function source(): ?Source
    {
        return $this->sourceAttribute()?->getSource();
    }

    public function destinationAttribute(): ?DestinationAttribute
    {
        return $this->firstInstanceOf(DestinationAttribute::class);
    }

    public function discriminated(): ?Discriminated
    {
        return $this->firstInstanceOf(Discriminated::class);
    }

    public function dateFormat(): ?string
    {
        return $this->firstInstanceOf(DateFormat::class)?->format;
    }

    public function errorKey(): ?string
    {
        return $this->firstInstanceOf(ErrorKeyAttribute::class)?->getErrorKey();
    }

    public function wireKey(): ?string
    {
        return $this->firstInstanceOf(WireKeyAttribute::class)?->getWireKey();
    }

    /**
     * @return list<mixed>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->items as $attribute) {
            if ($attribute instanceof RulesAttribute) {
                $rules = [...$rules, ...$attribute->getRules(AttributeTarget::Property)];
            }
        }

        return $rules;
    }

    /**
     * The first key an attribute declares, such as a header's name or #[Key]'s key.
     */
    public function declaredKey(): ?string
    {
        foreach ($this->items as $attribute) {
            if (($key = self::keyOf($attribute)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * The key from #[Key] or a source attribute such as #[Query('x')], which applies when
     * mapping and when serializing.
     */
    public function explicitKey(): ?string
    {
        foreach ($this->items as $attribute) {
            if ($attribute instanceof ExplicitKeyAttribute && ($key = self::keyOf($attribute)) !== null) {
                return $key;
            }
        }

        return null;
    }

    public function assertCombinable(): void
    {
        $this->assertNoEmptyKey();
        $this->assertAtMostOne(SourceAttribute::class, 'source');
        $this->assertAtMostOne(DestinationAttribute::class, 'destination');

        $keyed = array_values(array_filter($this->items, fn (object $attribute) => self::keyOf($attribute) !== null));

        if (count($keyed) > 1) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" gets a key from both #[".class_basename($keyed[0]).'] and #['
                .class_basename($keyed[1]).']. Give the key in only one of them.'
            );
        }

        $destination = $this->destinationAttribute();

        if ($destination !== null && ! $destination->destination()->hasKey() && $keyed !== []) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has both #[".class_basename($destination).'] and #['.class_basename($keyed[0]).'], '
                .'but #['.class_basename($destination).'] is not written under a key. Remove #['.class_basename($keyed[0]).'].'
            );
        }

        if ($this->hasInstanceOf(Optional::class) && $this->hasInstanceOf(Present::class)) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has both #[Optional] and #[Present]. #[Present] requires the key and "
                .'#[Optional] allows it to be absent, so remove one of them.'
            );
        }
    }

    /**
     * #[Present] requires the key, so a default would never be used.
     */
    public function assertNoPresentWithDefault(): void
    {
        if ($this->property->hasDefault && $this->hasInstanceOf(Present::class)) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has #[Present] and a default value, which is never used since #[Present] "
                .'requires the key. Remove the default or #[Present].'
            );
        }
    }

    public function assertNoPresentWithMissing(): void
    {
        if ($this->property->acceptsMissing && $this->hasInstanceOf(Present::class)) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" is typed with Missing, which allows the key to be absent, "
                .'and has #[Present], which requires it. Remove one of them.'
            );
        }
    }

    /**
     * A status is always written, so its property is a plain int. A status that is usually the
     * same takes a default, such as 200, instead of null or Missing.
     */
    public function assertStatusType(): void
    {
        $destination = $this->destinationAttribute();

        if ($destination?->destination() !== Destination::Status) {
            return;
        }

        $type = $this->property->type;

        if (! $type instanceof ReflectionNamedType || $type->getName() !== 'int' || $type->allowsNull()) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has #[".class_basename($destination).'], so its type must be int'
                .($type !== null ? ", not {$type}" : '').'. For a status that is usually the same, give the property a default such as 200.'
            );
        }
    }

    /**
     * A virtual property is never mapped. Attributes that only affect serialization or
     * TypeScript, such as #[Key] and #[Header], still apply. The type's attributes are exempt.
     */
    public function assertAllowedOnVirtual(): void
    {
        foreach ($this->declared() as $attribute) {
            if (($attribute instanceof SourceAttribute || $attribute instanceof RulesAttribute)
                && ! $attribute instanceof DestinationAttribute) {
                throw new RuntimeException(
                    "Roma property \"{$this->property->printableName}\" is virtual (a hooked property with no backing value), so it is never "
                    .'mapped and #['.class_basename($attribute).'] has no effect. Remove it.'
                );
            }
        }
    }

    /**
     * Headers and the status belong to the top-level class. A header that the type implies, such
     * as Authorization's, is reported as a source in a nested object instead.
     */
    public function assertAllowedInNestedObject(): void
    {
        $destination = $this->declared()->destinationAttribute();

        if ($destination !== null) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has #[".class_basename($destination).'] but is inside a nested object. '
                .'Headers and #[Status] can only be declared on the top-level class.'
            );
        }
    }

    /**
     * For #[DateFormat] on a property whose type has no date.
     */
    public function dateFormatWithoutDate(): RuntimeException
    {
        return new RuntimeException(
            "Roma property \"{$this->property->printableName}\" has #[DateFormat], but its type is not a date or an array, map or shape "
            .'of dates. A nested object sets the formats of its own dates.'
        );
    }

    /**
     * The attributes other than those the type implies.
     */
    private function declared(): static
    {
        return $this->reject(fn (object $attribute) => in_array($attribute, $this->property->typeAttributes, true));
    }

    private function assertNoEmptyKey(): void
    {
        foreach ($this->items as $attribute) {
            if (self::keyOf($attribute) !== '') {
                continue;
            }

            $name = class_basename($attribute);

            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has #[{$name}('')], an empty key. ".match (true) {
                    $attribute instanceof Key => 'Give a key, or remove #[Key] to use the property name.',
                    $attribute instanceof ExplicitKeyAttribute => "Give a key, or use #[{$name}] without one to use the property name.",
                    default => 'Give a key.',
                }
            );
        }
    }

    /**
     * @param  class-string  $class
     */
    private function assertAtMostOne(string $class, string $kind): void
    {
        $matching = array_values(array_filter($this->items, fn (object $attribute) => $attribute instanceof $class));

        if (count($matching) > 1) {
            throw new RuntimeException(
                "Roma property \"{$this->property->printableName}\" has more than one {$kind}: #[".class_basename($matching[0]).'] and #['
                .class_basename($matching[1]).']. Remove one of them.'
            );
        }
    }

    /**
     * A KeyAttribute (e.g. a header or accessor) always declares a key; an ExplicitKeyAttribute
     * (e.g. a source attribute or #[Key]) may leave it null to use the property name.
     */
    private static function keyOf(object $attribute): ?string
    {
        return match (true) {
            $attribute instanceof KeyAttribute => $attribute->getKey(),
            $attribute instanceof ExplicitKeyAttribute => $attribute->getKey(),
            default => null,
        };
    }
}
