<?php

namespace BYanelli\Roma\Data;

use BackedEnum;
use BYanelli\Roma\Data\Enums\NormalizesRawValue;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Validation\ValidationRules;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\DateFactory;
use Illuminate\Support\ItemNotFoundException;
use RuntimeException;
use UnitEnum;

class ClassMapping
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

    private ?ValidationRules $validationRules = null;

    /**
     * At the top level `$data` is keyed by root source; a nested object's data is its own slice.
     *
     * @param  array<string, mixed>  $data
     */
    private function __construct(
        private readonly Class_ $class,
        array $data,
        private readonly bool $nested,
        private readonly DateFactory $dateFactory,
    ) {
        $this->data = $data;

        if (! $nested) {
            $this->addValidationOnlyValuesToData();
        }

        $this->castData();
    }

    public static function fromPayload(Class_ $class, Payload $payload, DateFactory $dateFactory = new DateFactory): self
    {
        return new self($class, $payload->data($class), false, $dateFactory);
    }

    /**
     * @return list<Property>
     */
    private function getConstructorProperties(): array
    {
        return array_values(
            collect($this->class->properties)
                ->filter(fn (Property $p) => $p->role == Role::Constructor)
                ->all()
        );
    }

    /**
     * @return list<Property>
     */
    private function getClassProperties(): array
    {
        return array_values(
            collect($this->class->properties)
                // A virtual (computed) property has no backing store to write to.
                ->filter(fn (Property $p) => $p->role == Role::Property && ! $p->isVirtual)
                ->all()
        );
    }

    /**
     * @return list<Property>
     */
    private function getValidationOnlyProperties(): array
    {
        return array_values(
            collect($this->class->properties)
                ->filter(fn (Property $p) => $p->role == Role::ValidationOnly)
                ->all()
        );
    }

    /**
     * Get an argument list for the constructor of values and/or nested class mappings.
     *
     * @return list<mixed>
     */
    public function getConstructorMappedValuesOrNestedClassesAsList(): array
    {
        return array_values(Arr::map($this->getConstructorProperties(), $this->getMappedValue(...)));
    }

    /**
     * Get a map/associative array of values and/or nested class mappings to be assigned to
     * class properties. An absent optional property is left out, so it stays unset, unless it
     * takes Missing.
     *
     * @return array<string, mixed>
     */
    public function getClassPropertiesMappedValuesOrNestedClassesAsMap(): array
    {
        $present = array_filter(
            $this->getClassProperties(),
            fn (Property $p) => ! $p->optional || $p->default instanceof Missing || $this->dataHas($this->data, $p->getKeySegments()),
        );

        return Arr::mapWithKeys($present, fn (Property $p) => [$p->name => $this->getMappedValue($p)]);
    }

    private function toBoolean(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }

        if ($val === 0 || $val === 1) {
            return $val === 1;
        }

        if (! is_string($val)) {
            throw new CoercionException('Expected boolean, got '.get_debug_type($val));
        }

        return match (strtolower($val)) {
            'true', '1', 'yes', 'on' => true,
            'false', '0', 'no', 'off' => false,
            default => throw new CoercionException("Invalid boolean: $val"),
        };
    }

    private function toInteger(mixed $val): int
    {
        if (is_int($val)) {
            return $val;
        }

        if (! is_string($val)) {
            throw new CoercionException('Expected integer, got '.get_debug_type($val));
        }

        return (is_numeric($val) && ! str_contains($val, '.'))
            ? intval($val)
            : throw new CoercionException("Invalid integer: $val");
    }

    private function toFloat(mixed $val): float
    {
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }

        if (! is_string($val)) {
            throw new CoercionException('Expected float, got '.get_debug_type($val));
        }

        return is_numeric($val)
            ? floatval($val)
            : throw new CoercionException("Invalid float: $val");
    }

    private function toDate(Types\Date $type, mixed $val): DateTimeInterface
    {
        try {
            $date = $type->format !== null
                ? $this->dateFactory->createFromFormat($this->exactFormat($type->format), $val)
                : $this->dateFactory->parse($val);
        } catch (\Throwable $e) {
            // Any failure to parse a date is bad input, not a bug. The broad
            // catch is deliberate: larastan's Carbon stub declares no @throws.
            throw new CoercionException('Invalid date: '.get_debug_type($val), previous: $e);
        }

        $date ?? throw new CoercionException('Invalid date: '.get_debug_type($val));

        $class = $type->class;

        // Property typed as the bare interface: the mutable Carbon the factory
        // returns already satisfies it.
        if ($class === null) {
            return $date;
        }

        // Immutable targets need an immutable instance; Carbon\CarbonImmutable
        // (and DateTimeImmutable) are satisfied by ->toImmutable().
        if (is_a($class, \DateTimeImmutable::class, true)) {
            $date = $date->toImmutable();
        }

        if ($date instanceof $class) {
            return $date;
        }

        // An exotic Carbon subclass the factory doesn't produce: reparse through
        // the target itself so the hydrated value matches the declared type.
        if (is_a($class, CarbonInterface::class, true)) {
            return ($type->format !== null ? $class::createFromFormat($this->exactFormat($type->format), $val) : $class::parse($val))
                ?? throw new CoercionException('Invalid date: '.get_debug_type($val));
        }

        throw new CoercionException('Cannot coerce date to '.$class);
    }

    /**
     * Fields the format doesn't contain are zero rather than the current time, as in Laravel's
     * date_format rule.
     */
    private function exactFormat(string $format): string
    {
        return str_contains($format, '!') || str_contains($format, '|') ? $format : "!{$format}";
    }

    /**
     * A date with a format stays a string until validation, since the date_format rule checks
     * the string.
     */
    private function parsesAfterValidation(Type $type): bool
    {
        return match (true) {
            $type instanceof Types\Date => $type->format !== null,
            $type instanceof Types\Array_ => $this->parsesAfterValidation($type->memberType),
            $type instanceof Types\Shape => collect($type->items)->contains(fn (Types\ShapeItem $item) => $this->parsesAfterValidation($item->type)),
            default => false,
        };
    }

    private function parseValidated(Type $type, mixed $value): mixed
    {
        return match (true) {
            $type instanceof Types\Date && is_string($value) => $this->toDate($type, $value),
            $type instanceof Types\Array_ && is_array($value) => array_map(fn (mixed $item) => $this->parseValidated($type->memberType, $item), $value),
            $type instanceof Types\Shape && is_array($value) => $this->parseValidatedShape($type, $value),
            default => $value,
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private function parseValidatedShape(Types\Shape $type, array $value): array
    {
        foreach ($type->items as $item) {
            if (array_key_exists($item->key, $value)) {
                $value[$item->key] = $this->parseValidated($item->type, $value[$item->key]);
            }
        }

        return $value;
    }

    /**
     * @return array<mixed>
     */
    private function toArrayOfType(Types\Array_ $type, mixed $rawValue): array
    {
        if (! is_array($rawValue)) {
            throw new CoercionException('Expected array, got '.get_debug_type($rawValue));
        }

        return Arr::map($rawValue, fn ($value) => $this->castOrKeep($type->memberType, $value));
    }

    /**
     * Coerces one array element or shape item, keeping the raw value when it is invalid so
     * that validation reports it without leaving the other elements uncoerced.
     */
    private function castOrKeep(Type $type, mixed $rawValue): mixed
    {
        try {
            return $this->castValue($type, $rawValue);
        } catch (CoercionException) {
            return $rawValue;
        }
    }

    /**
     * @return array<mixed>
     */
    private function toShape(Types\Shape $type, mixed $rawValue): array
    {
        if (! is_array($rawValue)) {
            throw new CoercionException('Expected array, got '.get_debug_type($rawValue));
        }

        $result = $type->sealed ? [] : $rawValue;

        foreach ($type->items as $item) {
            if (! array_key_exists($item->key, $rawValue)) {
                continue;
            }

            $value = $rawValue[$item->key];

            $result[$item->key] = $value === null ? null : $this->castOrKeep($item->type, $value);
        }

        return $result;
    }

    private function toEnum(Types\Enum $type, mixed $val): mixed
    {
        // Be liberal in what we accept: a backed enum serializes out as a
        // {name, value} object, so accept that shape back as well as the bare
        // scalar backing value.
        if (is_array($val)) {
            return $this->enumFromObject($type->class, $val);
        }

        return $this->enumFromScalar($type->class, $val);
    }

    /**
     * Coerce the {name, value} object a backed enum serializes to back into the
     * enum. The value is authoritative; a supplied name must match, so a
     * mismatched pair is rejected as malformed.
     *
     * @param  class-string<UnitEnum>  $class
     * @param  array<array-key, mixed>  $val
     */
    private function enumFromObject(string $class, array $val): mixed
    {
        if (! is_a($class, BackedEnum::class, true) || ! array_key_exists('value', $val)) {
            throw new CoercionException("Expected a {name, value} object for backed enum $class");
        }

        $enum = $this->enumFromScalar($class, $val['value']);

        if (array_key_exists('name', $val) && $val['name'] !== $enum->name) {
            throw new CoercionException("Enum object for $class has a name/value mismatch");
        }

        return $enum;
    }

    /**
     * @param  class-string<UnitEnum>  $class
     */
    private function enumFromScalar(string $class, mixed $val): mixed
    {
        // Backed enums accept their scalar backing directly (e.g. a JSON int for
        // an int-backed enum); anything else is bad input, not a bug.
        if (! is_string($val) && ! is_int($val)) {
            throw new CoercionException('Expected enum value, got '.get_debug_type($val));
        }

        if (is_string($val) && is_a($class, NormalizesRawValue::class, true)) {
            $val = $class::normalizeRawValue($val);
        }

        try {
            if (is_a($class, BackedEnum::class, true)) {
                $backingType = new \ReflectionEnum($class)->getBackingType()?->getName();

                return $backingType == 'int'
                    ? $class::from(intval($val))
                    : $class::from($val);
            }

            return collect($class::cases())->firstOrFail(fn (UnitEnum $enum) => $enum->name == $val);
        } catch (\ValueError|ItemNotFoundException $e) {
            throw new CoercionException("Invalid enum value for $class: $val", previous: $e);
        }
    }

    private function castData(): void
    {
        foreach ($this->class->properties as $property) {
            [$role, $type, $keySegments] = [$property->role, $property->type, $property->getKeySegments()];

            if ($property->isVirtual || $role == Role::ValidationOnly) {
                continue;
            }

            if ($type instanceof Types\Mixed_) {
                continue;
            }

            if (! $this->dataHas($this->data, $keySegments)) {
                continue;
            }

            $rawValue = $this->dataGet($this->data, $keySegments);

            // Leave nulls alone; a nullable property keeps its null value and
            // coercion has nothing to do.
            if (is_null($rawValue)) {
                continue;
            }

            try {
                $typedValue = $this->castValue($type, $rawValue);
            } catch (CoercionException $e) {
                // Invalid input for the declared type: keep the raw value so
                // validation rejects it with a proper message. Any other
                // exception is a genuine error and propagates.
                $typedValue = $rawValue;
            }

            $this->dataSet($this->data, $keySegments, $typedValue);
        }
    }

    private function castValue(Type $type, mixed $rawValue): mixed
    {
        return match (true) {
            $type instanceof Types\Boolean => $this->toBoolean($rawValue),
            $type instanceof Types\Integer => $this->toInteger($rawValue),
            $type instanceof Types\Float_ => $this->toFloat($rawValue),
            $type instanceof Types\Date => $type->format !== null ? $rawValue : $this->toDate($type, $rawValue),
            $type instanceof Types\String_ => $rawValue,
            $type instanceof Types\Enum => $this->toEnum($type, $rawValue),
            $type instanceof Types\Array_ => $this->toArrayOfType($type, $rawValue),
            $type instanceof Types\Shape => $this->toShape($type, $rawValue),
            $type instanceof Class_ => $this->castObjectValue($type, $rawValue),
            $type instanceof Types\Union => $this->toUnionMember($type, $rawValue),
            $type instanceof Types\File => $rawValue,
            $type instanceof Types\Mixed_ => $rawValue,
            default => throw new RuntimeException('Unsupported type: '.$type::class),
        };
    }

    private function addValidationOnlyValuesToData(): void
    {
        foreach ($this->getValidationOnlyProperties() as $property) {
            $keySegments = $property->getKeySegments();

            $this->dataSet(
                $this->data,
                [ValidationRules::VALIDATION_ONLY_KEY, ...$keySegments],
                $this->dataGet($this->data, $keySegments),
            );
        }
    }

    /**
     * Exact-key existence check for an ordered key-segment list. Unlike Arr::has,
     * a key segment such as "a.b" is looked up as one literal key, never walked as
     * nested "a" -> "b".
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keySegments
     */
    private function dataHas(array $data, array $keySegments): bool
    {
        $sub = $data;

        foreach ($keySegments as $keySegment) {
            if (! is_array($sub) || ! array_key_exists($keySegment, $sub)) {
                return false;
            }

            $sub = $sub[$keySegment];
        }

        return true;
    }

    /**
     * Exact-key read for an ordered key-segment list.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keySegments
     */
    private function dataGet(array $data, array $keySegments, mixed $default = null): mixed
    {
        $sub = $data;

        foreach ($keySegments as $keySegment) {
            if (! is_array($sub) || ! array_key_exists($keySegment, $sub)) {
                return $default;
            }

            $sub = $sub[$keySegment];
        }

        return $sub;
    }

    /**
     * Exact-key write for an ordered key-segment list, creating intermediate arrays
     * as needed.
     *
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $keySegments
     */
    private function dataSet(array &$data, array $keySegments, mixed $value): void
    {
        $ref = &$data;

        $last = count($keySegments) - 1;

        foreach ($keySegments as $i => $keySegment) {
            if ($i === $last) {
                $ref[$keySegment] = $value;

                return;
            }

            if (! isset($ref[$keySegment]) || ! is_array($ref[$keySegment])) {
                $ref[$keySegment] = [];
            }

            $ref = &$ref[$keySegment];
        }
    }

    public function getMappedValue(Property $property): mixed
    {
        $value = $this->dataGet($this->data, $property->getKeySegments(), $property->default);

        // A deferred string value (see castObjectValue) is parsed here, at
        // construction time, now that validation has accepted the raw string.
        if ($property->type instanceof Class_
            && is_string($value)
            && $property->type->defersStringParsing()) {
            return $this->toNestedClass($property->type, $property->type->class::parseString($value));
        }

        if ($this->parsesAfterValidation($property->type)) {
            return $this->parseValidated($property->type, $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->normalizeData($this->data);
    }

    /**
     * Recursively convert nested ClassMapping instances (including
     * those nested inside arrays) back to plain arrays, so the validator can
     * traverse the whole structure with dotted / "key.*" rules.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function normalizeData(array $data): array
    {
        return collect($data)
            ->map(fn ($val) => $this->normalizeValue($val))
            ->all();
    }

    private function normalizeValue(mixed $val): mixed
    {
        return match (true) {
            $val instanceof ClassMapping => $val->toArray(),
            is_array($val) => $this->normalizeData($val),
            default => $val,
        };
    }

    /**
     * Coerce a value bound for a nested-object property.
     *
     * A structured array hydrates the object directly. A raw string is a value
     * object that parses from a single string (ParsesStringValue, e.g., an
     * Authorization header): when the object also defers string parsing--i.e.,
     * emits its own validation rule--we leave the string untouched here so
     * validation can vet it before it is parsed (deferred to getMappedValue); without
     * a rule we parse eagerly, so the object's fields still validate individually
     * as a safety net.
     */
    private function castObjectValue(Class_ $type, mixed $rawValue): mixed
    {
        if (is_string($rawValue) && $type->defersStringParsing()) {
            return $rawValue;
        }

        if (is_string($rawValue) && $type->parsesStringValue()) {
            return $this->toNestedClass($type, $type->class::parseString($rawValue));
        }

        if (! is_array($rawValue)) {
            throw new CoercionException('Expected object, got '.get_debug_type($rawValue));
        }

        return $this->toNestedClass($type, $rawValue);
    }

    /**
     * The member's mapping keeps the discriminator in its data for validation; the member
     * class has no property under that key, so the value is not passed to it.
     */
    private function toUnionMember(Types\Union $type, mixed $rawValue): ClassMapping
    {
        if (! is_array($rawValue)) {
            throw new CoercionException('Expected object, got '.get_debug_type($rawValue));
        }

        $member = $type->memberFor($rawValue[$type->key] ?? null)
            ?? throw new CoercionException("Unknown or missing discriminator \"{$type->key}\"");

        return $this->toNestedClass($member, $rawValue);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toNestedClass(Class_ $class_, array $data): ClassMapping
    {
        return new ClassMapping($class_, $data, true, $this->dateFactory);
    }

    /**
     * @return class-string
     */
    public function getClassName(): string
    {
        return $this->class->class;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->validationRules()->toArray();
    }

    /**
     * Client-facing :attribute names, keyed by internal rule key.
     *
     * @return array<string, string>
     */
    public function attributeNames(): array
    {
        return $this->validationRules()->attributeNames();
    }

    private function validationRules(): ValidationRules
    {
        return $this->validationRules ??= new ValidationRules($this->class, $this->toArray());
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->toArray();
    }
}
