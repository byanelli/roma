<?php

namespace BYanelli\Roma\Serialization;

use BackedEnum;
use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Data\Discriminator;
use BYanelli\Roma\Data\PhpDocTypeParser;
use BYanelli\Roma\Data\PropertyAttributes;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types;
use BYanelli\Roma\Data\UnresolvableClassName;
use BYanelli\Roma\Missing;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use stdClass;
use Stringable;
use UnitEnum;

/**
 * Serializes an object's public properties, recursing through nested objects
 * and arrays with the same profile.
 */
class Serializer
{
    public function __construct(private PhpDocTypeParser $phpDocTypeParser = new PhpDocTypeParser) {}

    /**
     * @var array<class-string, list<PropertyPlan>>
     */
    private array $plans = [];

    /**
     * @var array<class-string, bool>
     */
    private array $usesTrait = [];

    /**
     * @var array<class-string, bool>
     */
    private array $overridesToArray = [];

    public function serialize(object $object, ?Profile $profile = null): Serialized
    {
        $profile ??= Profile::response();

        $body = $headers = $query = $path = [];
        $status = null;

        foreach ($this->values($object, $profile) as [$property, $value, $dateFormat]) {
            $key = $property->key;

            match ($property->destination) {
                Destination::Body => $body[$key] = $this->normalizeValue($value, $profile, $dateFormat, $property->discriminated),
                Destination::Header => $headers[$key] = $this->headerValue($value, $dateFormat),
                Destination::Status => $status = (int) $value,
                Destination::Query => $query[$key] = $this->queryValue($this->normalizeValue($value, $profile, $dateFormat, $property->discriminated), $profile),
                Destination::Path => $path[$key] = $this->normalizeValue($value, $profile, $dateFormat, $property->discriminated),
            };
        }

        return new Serialized($body, $headers, $status, $query, $path);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $object, ?Profile $profile = null): array
    {
        return $this->serialize($object, $profile)->body;
    }

    /**
     * Converts the values of an array, such as the output of an overridden toArray(), as the
     * serializer converts property values.
     *
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    public function normalize(array $value, ?Profile $profile = null): array
    {
        $profile ??= Profile::response();

        return array_map(fn (mixed $item): mixed => $this->normalizeValue($item, $profile, $profile->dateFormat), $value);
    }

    /**
     * The body of a nested object.
     *
     * @return array<string, mixed>
     */
    private function body(object $object, Profile $profile): array
    {
        $this->assertNestable($object);

        $body = [];

        foreach ($this->values($object, $profile) as [$property, $value, $dateFormat]) {
            if ($property->destination === Destination::Body) {
                $body[$property->key] = $this->normalizeValue($value, $profile, $dateFormat, $property->discriminated);
            }
        }

        return $body;
    }

    private function assertNestable(object $object): void
    {
        foreach ($this->plan($object::class) as $property) {
            if ($property->destination !== Destination::Body) {
                $reflection = $property->reflection;

                throw PropertyAttributes::topLevelOnly(
                    $reflection->getAttributes(DestinationAttribute::class, ReflectionAttribute::IS_INSTANCEOF)[0]->getName(),
                    $reflection->getDeclaringClass()->getShortName().'::$'.$reflection->getName(),
                );
            }
        }
    }

    /**
     * @return Generator<int, array{PropertyPlan, mixed, string}>
     */
    private function values(object $object, Profile $profile): Generator
    {
        foreach ($this->plan($object::class) as $property) {
            $reflection = $property->reflection;

            if (! $profile->allows($property->destination)) {
                throw new RuntimeException(sprintf(
                    'Property %s::$%s is written to the %s destination, which this serialization profile does not allow.',
                    $reflection->getDeclaringClass()->getName(),
                    $reflection->getName(),
                    $property->destination->name,
                ));
            }

            if (! $reflection->isInitialized($object)) {
                if ($property->optional) {
                    continue;
                }

                throw new RuntimeException(sprintf(
                    'Property %s::$%s was never set. Mark it #[Optional] to omit it, or give it a default value.',
                    $reflection->getDeclaringClass()->getName(),
                    $reflection->getName(),
                ));
            }

            $value = $reflection->getValue($object);

            if ($value instanceof Missing) {
                continue;
            }

            $dateFormat = ($object instanceof CustomizesSerialization ? $object->dateFormat($reflection, $profile) : null)
                ?? $property->dateFormat
                ?? $profile->dateFormat;

            yield [$property, $value, $dateFormat];
        }
    }

    /**
     * `$discriminated` is the part of the declared type that applies to `$value`.
     */
    private function normalizeValue(mixed $value, Profile $profile, string $dateFormat, ?Type $discriminated = null): mixed
    {
        if ($discriminated instanceof DiscriminatedValue && is_object($value)) {
            return $this->withDiscriminator($value, $discriminated, $profile, $dateFormat);
        }

        if (is_array($value) && ($discriminated instanceof Types\Array_ || $discriminated instanceof Types\Shape)) {
            return $this->normalizeElements($value, $profile, $dateFormat, $discriminated);
        }

        return match (true) {
            $value instanceof UnitEnum => $this->enumValue($value, $profile->enums),
            $value instanceof DateTimeInterface => $value->format($dateFormat),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->normalizeValue($item, $profile, $dateFormat), $value),
            ! is_object($value) => $value,
            // A nested object's dates use its own formats, so #[DateFormat] stops here.
            $this->overridesToArray($value) => $this->normalizeValue($this->ownToArray($value, $profile), $profile, $profile->dateFormat),
            $this->usesTrait($value) => $this->body($value, $profile),
            $value instanceof Arrayable => $this->normalizeValue($value->toArray(), $profile, $profile->dateFormat),
            // These already define their JSON form.
            $value instanceof JsonSerializable => $value,
            $value instanceof Stringable => (string) $value,
            $value instanceof stdClass => $this->normalizeValue(get_object_vars($value), $profile, $profile->dateFormat),
            default => $this->body($value, $profile),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function normalizeElements(array $value, Profile $profile, string $dateFormat, Types\Array_|Types\Shape $type): array
    {
        $items = $type instanceof Types\Shape
            ? collect($type->items)->mapWithKeys(fn (Types\ShapeItem $item) => [$item->key => $item->type])->all()
            : [];

        $result = [];

        foreach ($value as $key => $item) {
            $itemType = $type instanceof Types\Array_ ? $type->memberType : ($items[$key] ?? null);

            $result[$key] = $this->normalizeValue($item, $profile, $dateFormat, $itemType);
        }

        return $result;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function withDiscriminator(object $value, DiscriminatedValue $type, Profile $profile, string $dateFormat): array
    {
        $discriminator = $type->discriminator;

        $tag = $discriminator->valueFor($value) ?? throw new RuntimeException(sprintf(
            'Cannot serialize %s through %s: it is not a member of the discriminated union.',
            $value::class,
            $type->subject,
        ));

        $body = $this->normalizeValue($value, $profile, $dateFormat);

        if (! is_array($body) || array_key_exists($discriminator->key, $body)) {
            throw new RuntimeException(sprintf(
                'Cannot serialize %s through %s: its output must be an object without a "%s" key.',
                $value::class,
                $type->subject,
                $discriminator->key,
            ));
        }

        return [$discriminator->key => $tag, ...$body];
    }

    private function enumValue(UnitEnum $enum, EnumStyle $style): mixed
    {
        if (! $enum instanceof BackedEnum) {
            return $enum->name;
        }

        return match ($style) {
            EnumStyle::NameAndValue => ['name' => $enum->name, 'value' => $enum->value],
            EnumStyle::Value => $enum->value,
            EnumStyle::Name => $enum->name,
        };
    }

    private function headerValue(mixed $value, string $dateFormat): string
    {
        return match (true) {
            $value instanceof BackedEnum => (string) $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format($dateFormat),
            default => (string) $value,
        };
    }

    private function queryValue(mixed $value, Profile $profile): mixed
    {
        return match (true) {
            is_bool($value) && $profile->queryBools === BoolStyle::TrueFalseStrings => $value ? 'true' : 'false',
            is_array($value) => array_map(fn (mixed $item): mixed => $this->queryValue($item, $profile), $value),
            default => $value,
        };
    }

    /**
     * Whether the object uses IsArrayable and its class declares its own toArray().
     */
    public function overridesToArray(object $object): bool
    {
        if (! $this->usesTrait($object)) {
            return false;
        }

        return $this->overridesToArray[$object::class] ??= (function () use ($object): bool {
            $method = new ReflectionMethod($object, 'toArray');
            $trait = new ReflectionMethod(IsArrayable::class, 'toArray');

            return $method->getFileName() !== $trait->getFileName() || $method->getStartLine() !== $trait->getStartLine();
        })();
    }

    /**
     * An override declared without the profile parameter ignores the extra argument.
     */
    private function ownToArray(object $object, Profile $profile): mixed
    {
        $this->assertNestable($object);

        if (! method_exists($object, 'toArray')) {
            throw new RuntimeException($object::class.' has no toArray() method.');
        }

        return $object->toArray($profile);
    }

    private function usesTrait(object $object): bool
    {
        return $this->usesTrait[$object::class] ??= in_array(IsArrayable::class, class_uses_recursive($object), true);
    }

    /**
     * @param  class-string  $class
     * @return list<PropertyPlan>
     */
    private function plan(string $class): array
    {
        return $this->plans[$class] ??= $this->buildPlan($class);
    }

    /**
     * @param  class-string  $class
     * @return list<PropertyPlan>
     */
    private function buildPlan(string $class): array
    {
        $plan = [];

        foreach (new ReflectionClass($class)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $subject = $property->getDeclaringClass()->getShortName().'::$'.$property->getName();
            $attributes = $this->checkedAttributes($property);
            PropertyAttributes::assertCombinable($attributes, $subject);
            Missing::assertNotAlone($property->getType(), $subject);

            $destination = $this->attribute($property, DestinationAttribute::class);
            $key = PropertyAttributes::explicitKey($attributes);
            $dateFormat = $this->attribute($property, DateFormat::class)?->format;

            if ($dateFormat !== null && ! $this->mayHoldDate($property)) {
                throw PropertyAttributes::dateFormatWithoutDate($subject);
            }

            $plan[] = new PropertyPlan(
                reflection: $property,
                key: $destination?->destinationKey() ?? $key ?? $property->getName(),
                destination: $destination?->destination() ?? Destination::Body,
                dateFormat: $dateFormat,
                optional: $this->attribute($property, Optional::class) !== null || Missing::isInType($property->getType()),
                discriminated: $this->discriminatedType($property),
            );
        }

        return $plan;
    }

    /**
     * Whether the declared type allows a date, or an array that may contain one. An array
     * without a supported PHPDoc type may.
     */
    private function mayHoldDate(ReflectionProperty $property): bool
    {
        $type = $property->getType();

        $names = match (true) {
            $type instanceof ReflectionNamedType => [$type->getName()],
            $type instanceof ReflectionUnionType => array_map(fn ($member) => $member instanceof ReflectionNamedType ? $member->getName() : 'mixed', $type->getTypes()),
            default => ['mixed'],
        };

        foreach ($names as $name) {
            if (in_array($name, ['mixed', 'object'], true) || is_a($name, DateTimeInterface::class, true)) {
                return true;
            }

            if ($name === 'array' && $this->arrayMayHoldDate($property)) {
                return true;
            }
        }

        return false;
    }

    private function arrayMayHoldDate(ReflectionProperty $property): bool
    {
        try {
            $type = $this->phpDocTypeParser->getArrayType(
                $this->documentedProperty($property),
                fn (array $names): Type => array_any($names, fn (string $name) => $name === 'mixed' || is_a($name, DateTimeInterface::class, true))
                    ? new Types\Date
                    : new Types\String_,
            );
        } catch (RuntimeException) {
            return true;
        }

        return Types\Date::isIn($type);
    }

    /**
     * Where the property's declared type is a discriminated union. Unlike mapping, an
     * interface without #[Discriminated] or an undocumented array is serialized without
     * a discriminator rather than rejected.
     */
    private function discriminatedType(ReflectionProperty $property): ?Type
    {
        $attribute = $this->attribute($property, Discriminated::class);
        $declared = $property->getType();
        $subject = class_basename($property->getDeclaringClass()->getName()).'::$'.$property->getName();

        if ($declared instanceof ReflectionUnionType) {
            $names = array_values(array_filter(
                array_map(fn ($type) => $type instanceof ReflectionNamedType ? $type->getName() : '', $declared->getTypes()),
                fn (string $name) => ! in_array($name, ['null', Missing::class], true),
            ));

            return $this->discriminatedValue($names, $attribute, $subject);
        }

        if (! $declared instanceof ReflectionNamedType) {
            return null;
        }

        if ($declared->getName() !== 'array') {
            return $this->discriminatedValue([$declared->getName()], $attribute, $subject);
        }

        $error = null;
        $found = false;

        try {
            $type = $this->phpDocTypeParser->getArrayType(
                $this->documentedProperty($property),
                function (array $names) use ($attribute, $subject, &$error, &$found): Type {
                    try {
                        $value = $this->discriminatedValue($names, $attribute, $subject);
                    } catch (RuntimeException $e) {
                        $error = $e;
                    }

                    $found = $found || isset($value);

                    return $value ?? new Types\Mixed_;
                },
            );
        } catch (UnresolvableClassName $e) {
            throw $e;
        } catch (RuntimeException) {
            // Mapping requires a supported PHPDoc type on an array; serialization doesn't.
            return null;
        }

        if ($error !== null) {
            throw $error;
        }

        return $found ? $type : null;
    }

    /**
     * @param  list<string>  $names
     */
    private function discriminatedValue(array $names, ?Discriminated $attribute, string $subject): ?DiscriminatedValue
    {
        if (count($names) > 1) {
            return $attribute === null ? null : new DiscriminatedValue(
                Discriminator::fromAttribute($attribute, $subject, union: $this->classNames($names, $subject)),
                $subject,
            );
        }

        $name = $names[0] ?? '';

        if (! interface_exists($name) && ! (class_exists($name) && new ReflectionClass($name)->isAbstract())) {
            return null;
        }

        if ($attribute !== null) {
            return new DiscriminatedValue(Discriminator::fromAttribute($attribute, $subject, $name), $subject);
        }

        $classLevel = Discriminator::ofContract($name);

        return $classLevel === null ? null : new DiscriminatedValue($classLevel, $subject);
    }

    /**
     * @param  list<string>  $names
     * @return list<class-string>
     */
    private function classNames(array $names, string $subject): array
    {
        foreach ($names as $name) {
            if (! class_exists($name)) {
                throw new RuntimeException("Roma #[Discriminated] on {$subject} applies only to a union of classes.");
            }
        }

        /** @var list<class-string> $names */
        return $names;
    }

    /**
     * A promoted property's PHPDoc is the constructor's @param tag.
     */
    private function documentedProperty(ReflectionProperty $property): ReflectionProperty|ReflectionParameter
    {
        if (! $property->isPromoted()) {
            return $property;
        }

        foreach ($property->getDeclaringClass()->getConstructor()?->getParameters() ?? [] as $parameter) {
            if ($parameter->getName() === $property->getName()) {
                return $parameter;
            }
        }

        return $property;
    }

    /**
     * Instances of the attributes PropertyAttributes checks. Other attributes are left
     * uninstantiated, since their classes may not be loadable.
     *
     * @return list<object>
     */
    private function checkedAttributes(ReflectionProperty $property): array
    {
        $attributes = [];

        foreach ($property->getAttributes() as $attribute) {
            foreach (PropertyAttributes::CHECKED as $class) {
                if (is_a($attribute->getName(), $class, true)) {
                    $attributes[] = $attribute->newInstance();

                    break;
                }
            }
        }

        return $attributes;
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return ?T
     */
    private function attribute(ReflectionProperty $property, string $class): ?object
    {
        return ($property->getAttributes($class, ReflectionAttribute::IS_INSTANCEOF)[0] ?? null)?->newInstance();
    }
}
