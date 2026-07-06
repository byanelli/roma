<?php

namespace BYanelli\Roma\Response;

use BackedEnum;
use BYanelli\Roma\Response\Attributes\DateFormat;
use BYanelli\Roma\Response\Attributes\Header;
use BYanelli\Roma\Response\Attributes\Optional;
use BYanelli\Roma\Response\Attributes\Status;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use ReflectionObject;
use ReflectionProperty;
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
            // has no implicit default, so accessing it below throws — surfacing
            // a response field that was never populated.
            if (! $property->isInitialized($this) && $property->getAttributes(Optional::class) !== []) {
                continue;
            }

            $result[$property->getName()] = $this->normalizeValue(
                $property->getValue($this),
                $this->dateFormat($property),
            );
        }

        return $result;
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
