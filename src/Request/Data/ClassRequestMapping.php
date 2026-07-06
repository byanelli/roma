<?php

namespace BYanelli\Roma\Request\Data;

use BackedEnum;
use BYanelli\Roma\Request\Data\Sources\Body;
use BYanelli\Roma\Request\Data\Sources\Cookie;
use BYanelli\Roma\Request\Data\Sources\File;
use BYanelli\Roma\Request\Data\Sources\Header;
use BYanelli\Roma\Request\Data\Sources\Input;
use BYanelli\Roma\Request\Data\Sources\Query;
use BYanelli\Roma\Request\Data\Sources\RequestObject_;
use BYanelli\Roma\Request\Data\Sources\RouteParameter;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Enums\NormalizesRawValue;
use BYanelli\Roma\Request\Validation\ValidationRules;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Arr;
use Illuminate\Support\DateFactory;
use Illuminate\Support\ItemNotFoundException;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

class ClassRequestMapping
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

    private ?ValidationRules $validationRules = null;

    /**
     * @param  array<string, mixed>|null  $data
     */
    public function __construct(
        private readonly Class_ $class,
        private readonly Request $request,
        private readonly ?Source $source = null,
        ?array $data = null,
        private readonly DateFactory $dateFactory = new DateFactory,
    ) {
        if ($data == null) {
            $this->data = $this->flattenRequest();
            $this->addRequestObjectValuesToData();
            $this->addValidationOnlyValuesToData();
        } else {
            $this->data = $data;
        }

        $this->castData();
    }

    /**
     * @return array<string, mixed>
     */
    private function flattenRequest(): array
    {
        return [
            (new Input)->getKey() => $this->request->input(),
            (new Query)->getKey() => $this->request->query->all(),
            (new Header)->getKey() => collect($this->request->server->getHeaders())
                ->mapWithKeys(fn ($val, $key) => [Str::lower($key) => $val])
                ->all(),
            (new Body)->getKey() => $this->request->isJson()
                ? $this->request->json()->all()
                : $this->request->request->all(),
            (new File)->getKey() => $this->request->files->all(),
            (new RouteParameter)->getKey() => $this->routeParameters(),
            (new Cookie)->getKey() => $this->request->cookies->all(),
        ];
    }

    /**
     * The bound route's parameters, or an empty bucket when no route is bound.
     * A request may have no route resolver (route() returns null) or a route
     * that has not yet been bound (parameters() throws); either way a missing
     * route param should surface as a clean "required" validation error rather
     * than crash the mapping.
     *
     * @return array<string, mixed>
     */
    private function routeParameters(): array
    {
        $route = $this->request->route();

        if (! $route instanceof Route) {
            return [];
        }

        try {
            return $route->parameters();
        } catch (\LogicException) {
            return [];
        }
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
                ->filter(fn (Property $p) => $p->role == Role::Property)
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
     * @return list<mixed>
     */
    public function getConstructorValuesArray(): array
    {
        return array_values(Arr::map($this->getConstructorProperties(), $this->getValue(...)));
    }

    /**
     * @return array<string, mixed>
     */
    public function getClassPropertiesMap(): array
    {
        return Arr::mapWithKeys($this->getClassProperties(), fn (Property $p) => [$p->name => $this->getValue($p)]);
    }

    private function toBoolean(mixed $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }

        if (! is_string($val)) {
            throw new CoercionException('Expected boolean, got '.get_debug_type($val));
        }

        return match ($val) {
            'true' => true,
            'false' => false,
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
            $date = $this->dateFactory->parse($val);
        } catch (\Throwable $e) {
            // Any failure to parse a date is bad input, not a bug. The broad
            // catch is deliberate: larastan's Carbon stub declares no @throws.
            throw new CoercionException('Invalid date: '.get_debug_type($val), previous: $e);
        }

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
            return $class::parse($val);
        }

        throw new CoercionException('Cannot coerce date to '.$class);
    }

    /**
     * @return array<mixed>
     */
    private function toArrayOfType(Property $property, Types\Array_ $type, mixed $rawValue): array
    {
        if (! is_array($rawValue)) {
            throw new CoercionException('Expected array, got '.get_debug_type($rawValue));
        }

        return Arr::map($rawValue, fn ($value) => $this->castValue($property, $type->memberType, $value));
    }

    private function toEnum(Types\Enum $type, mixed $val): mixed
    {
        // Backed enums accept their scalar backing directly (e.g. a JSON int for
        // an int-backed enum); anything else is bad input, not a bug.
        if (! is_string($val) && ! is_int($val)) {
            throw new CoercionException('Expected enum value, got '.get_debug_type($val));
        }

        $class = $type->class;

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
            [$role, $type, $keySegments] = [$property->role, $property->type, $this->relativeKeySegments($property)];

            if ($role == Role::ValidationOnly) {
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
                $typedValue = $this->castValue($property, $type, $rawValue);
            } catch (CoercionException $e) {
                // Invalid input for the declared type: keep the raw value so
                // validation rejects it with a proper message. Any other
                // exception is a genuine error and propagates.
                $typedValue = $rawValue;
            }

            $this->dataSet($this->data, $keySegments, $typedValue);
        }
    }

    private function castValue(Property $property, Type $type, mixed $rawValue): mixed
    {
        return match (true) {
            $type instanceof Types\Boolean => $this->toBoolean($rawValue),
            $type instanceof Types\Integer => $this->toInteger($rawValue),
            $type instanceof Types\Float_ => $this->toFloat($rawValue),
            $type instanceof Types\Date => $this->toDate($type, $rawValue),
            $type instanceof Types\String_ => $rawValue,
            $type instanceof Types\Enum => $this->toEnum($type, $rawValue),
            $type instanceof Types\Array_ => $this->toArrayOfType($property, $type, $rawValue),
            $type instanceof Class_ => is_array($rawValue)
                ? $this->toNestedClass($property, $type, $rawValue)
                : throw new CoercionException('Expected object, got '.get_debug_type($rawValue)),
            $type instanceof Types\File => $rawValue,
            $type instanceof Types\Mixed_ => $rawValue,
            default => throw new RuntimeException('Unsupported type: '.$type::class),
        };
    }

    private function addRequestObjectValuesToData(): void
    {
        foreach ($this->class->properties as $property) {
            $parent = $property->source->parent;

            if ($parent === null || get_class($parent) != RequestObject_::class) {
                continue;
            }

            $value = call_user_func($property->accessor, $this->request);

            $this->dataSet($this->data, $property->getKeySegments(), $value);
        }
    }

    private function addValidationOnlyValuesToData(): void
    {
        foreach ($this->getValidationOnlyProperties() as $property) {
            $keySegments = $property->getKeySegments();

            $this->dataSet(
                $this->data,
                ['__request', ...$keySegments],
                $this->dataGet($this->data, $keySegments),
            );
        }
    }

    /**
     * This mapping's key keySegments relative to its own source. At the top level
     * (no source) these are the property's absolute keySegments; inside a nested
     * object the parent source's keySegments are dropped so lookups land in the
     * object's own data slice.
     *
     * @return list<string>
     */
    private function relativeKeySegments(Property $property): array
    {
        $keySegments = $property->getKeySegments();

        if ($this->source !== null) {
            $keySegments = array_slice($keySegments, count($this->source->getKeySegments()));
        }

        return $keySegments;
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

    public function getValue(Property $property): mixed
    {
        return $this->dataGet($this->data, $this->relativeKeySegments($property), $property->default);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->normalizeData($this->data);
    }

    /**
     * Recursively convert nested ClassRequestMapping instances (including
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
            $val instanceof ClassRequestMapping => $val->toArray(),
            is_array($val) => $this->normalizeData($val),
            default => $val,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function toNestedClass(
        Property $property,
        Class_ $class_,
        array $data
    ): ClassRequestMapping {
        return new ClassRequestMapping(
            $class_,
            $this->request,
            $property->source,
            $data,
            $this->dateFactory
        );
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
        return $this->validationRules ??= new ValidationRules($this->class);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->toArray();
    }
}
