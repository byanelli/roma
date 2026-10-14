<?php

namespace BYanelli\Roma\Serialization;

use BackedEnum;
use BYanelli\Roma\Response\Attributes\DateFormat;
use BYanelli\Roma\Response\Attributes\Key;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\IsArrayable;
use DateTimeInterface;
use Generator;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;
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
    /**
     * @var array<class-string, list<PropertyPlan>>
     */
    private array $plans = [];

    /**
     * @var array<class-string, bool>
     */
    private array $usesTrait = [];

    public function serialize(object $object, ?Profile $profile = null): Serialized
    {
        $profile ??= Profile::response();

        $body = $headers = $query = $path = [];
        $status = null;

        foreach ($this->values($object, $profile) as [$property, $value, $dateFormat]) {
            $key = $property->key;

            match ($property->destination) {
                Destination::Body => $body[$key] = $this->normalize($value, $profile, $dateFormat),
                Destination::Header => $headers[$key] = $this->headerValue($value, $dateFormat),
                Destination::Status => $status = (int) $value,
                Destination::Query => $query[$key] = $this->queryValue($this->normalize($value, $profile, $dateFormat), $profile),
                Destination::Path => $path[$key] = $this->normalize($value, $profile, $dateFormat),
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
     * The body of a nested object. Its other destinations apply only at the top level and are dropped.
     *
     * @return array<string, mixed>
     */
    private function body(object $object, Profile $profile): array
    {
        $body = [];

        foreach ($this->values($object, $profile) as [$property, $value, $dateFormat]) {
            if ($property->destination === Destination::Body) {
                $body[$property->key] = $this->normalize($value, $profile, $dateFormat);
            }
        }

        return $body;
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

            $dateFormat = ($object instanceof CustomizesSerialization ? $object->dateFormat($reflection, $profile) : null)
                ?? $property->dateFormat
                ?? $profile->dateFormat;

            yield [$property, $reflection->getValue($object), $dateFormat];
        }
    }

    private function normalize(mixed $value, Profile $profile, string $dateFormat): mixed
    {
        return match (true) {
            $value instanceof UnitEnum => $this->enumValue($value, $profile->enums),
            $value instanceof DateTimeInterface => $value->format($dateFormat),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->normalize($item, $profile, $dateFormat), $value),
            ! is_object($value) => $value,
            $this->usesTrait($value) => $this->body($value, $profile),
            $value instanceof Arrayable => $this->normalize($value->toArray(), $profile, $dateFormat),
            // These already define their JSON form.
            $value instanceof JsonSerializable => $value,
            $value instanceof Stringable => (string) $value,
            $value instanceof stdClass => $this->normalize(get_object_vars($value), $profile, $dateFormat),
            default => $this->body($value, $profile),
        };
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

            $destination = $this->attribute($property, DestinationAttribute::class);

            $plan[] = new PropertyPlan(
                reflection: $property,
                key: $destination?->destinationKey() ?? $this->attribute($property, Key::class)->key ?? $property->getName(),
                destination: $destination?->destination() ?? Destination::Body,
                dateFormat: $this->attribute($property, DateFormat::class)?->format,
                optional: $this->attribute($property, Optional::class) !== null,
            );
        }

        return $plan;
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
