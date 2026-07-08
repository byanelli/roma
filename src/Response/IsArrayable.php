<?php

namespace BYanelli\Roma\Response;

use BackedEnum;
use BYanelli\Roma\Response\Attributes\DateFormat;
use BYanelli\Roma\Response\Attributes\Header;
use BYanelli\Roma\Response\Attributes\Key;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Attributes\Status;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use ReflectionObject;
use ReflectionProperty;
use RuntimeException;
use UnitEnum;

/**
 * Serializes a response object's public properties to an array, converting
 * common value types to their JSON form and recursing through nested response
 * objects, Arrayables, and arrays. Use on a class that implements Arrayable.
 */
trait IsArrayable
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $result = [];

        foreach (new ReflectionObject($this)->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            // #[Status] and #[Header] properties are response metadata, not body:
            // their values are lifted out to the status code / response headers.
            if ($property->getAttributes(Status::class) !== [] || $property->getAttributes(Header::class) !== []) {
                continue;
            }

            // An unset #[Optional] property is omitted. Any other unset property
            // has no implicit default and is a mistake, so surface it clearly
            // instead of letting a raw uninitialized-property Error escape.
            if (! $property->isInitialized($this) && $property->getAttributes(Optional::class) !== []) {
                continue;
            }

            $this->requireInitialized($property);

            $result[$this->outputKey($property)] = $this->normalizeValue(
                $property->getValue($this),
                $this->dateFormat($property),
            );
        }

        return $result;
    }

    /**
     * Guard against serializing (or lifting to status/header) a typed property
     * that was never set, which would otherwise raise PHP's opaque
     * "must not be accessed before initialization" Error.
     */
    private function requireInitialized(ReflectionProperty $property): void
    {
        if ($property->isInitialized($this)) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Response property %s::$%s was never set. Mark it #[Optional] to omit it, or give it a default value.',
            $property->getDeclaringClass()->getName(),
            $property->getName(),
        ));
    }

    /**
     * The key a property serializes under: its #[Key] override if present, else
     * its PHP property name.
     */
    private function outputKey(ReflectionProperty $property): string
    {
        $attributes = $property->getAttributes(Key::class);

        // todo what if more than one #[Key]? something to check for elsewhere too?

        return $attributes === [] ? $property->getName() : $attributes[0]->newInstance()->key;
    }

    /**
     * The date format applied to a property's DateTimeInterface values.
     * Defaults to the property's #[DateFormat] attribute, else ATOM. Override
     * for a dynamic format.
     */
    protected function dateFormat(ReflectionProperty $property): string
    {
        $attributes = $property->getAttributes(DateFormat::class);

        return $attributes === [] ? DateTimeInterface::ATOM : $attributes[0]->newInstance()->format;
    }

    private function normalizeValue(mixed $value, string $dateFormat = DateTimeInterface::ATOM): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format($dateFormat),
            $value instanceof Arrayable => $this->normalizeValue($value->toArray(), $dateFormat),
            is_array($value) => array_map(fn (mixed $item): mixed => $this->normalizeValue($item, $dateFormat), $value),
            default => $value,
        };
    }
}
