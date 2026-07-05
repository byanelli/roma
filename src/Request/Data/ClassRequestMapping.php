<?php

namespace BYanelli\Roma\Request\Data;

use BackedEnum;
use BYanelli\Roma\Request\Data\Sources\Body;
use BYanelli\Roma\Request\Data\Sources\File;
use BYanelli\Roma\Request\Data\Sources\Header;
use BYanelli\Roma\Request\Data\Sources\Input;
use BYanelli\Roma\Request\Data\Sources\Query;
use BYanelli\Roma\Request\Data\Sources\RequestObject_;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Validation\ValidationRules;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Str;
use RuntimeException;
use UnitEnum;

class ClassRequestMapping
{
    /**
     * @var array<string, mixed>
     */
    private array $data;

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
        ];
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

    private function toBoolean(string|bool $val): bool
    {
        if (is_bool($val)) {
            return $val;
        }

        return match ($val) {
            'true' => true,
            'false' => false,
            default => throw new RuntimeException("Invalid boolean: $val"),
        };
    }

    private function toInteger(string|int $val): int
    {
        if (is_int($val)) {
            return $val;
        }

        return (is_numeric($val) && ! str_contains($val, '.'))
            ? intval($val)
            : throw new RuntimeException("Invalid integer: $val");
    }

    private function toFloat(string|int|float $val): float
    {
        if (is_int($val) || is_float($val)) {
            return (float) $val;
        }

        return is_numeric($val)
            ? floatval($val)
            : throw new RuntimeException("Invalid float: $val");
    }

    /**
     * @return array<mixed>
     */
    private function toArrayOfType(Property $property, Types\Array_ $type, mixed $rawValue): array
    {
        if (! is_array($rawValue)) {
            throw new RuntimeException('Expected array, got '.get_debug_type($rawValue));
        }

        return Arr::map($rawValue, fn ($value) => $this->castValue($property, $type->memberType, $value));
    }

    private function toEnum(Types\Enum $type, string $val): mixed
    {
        $class = $type->class;

        if (is_a($class, BackedEnum::class, true)) {
            $backingType = new \ReflectionEnum($class)->getBackingType()?->getName();

            return $backingType == 'int'
                ? $class::from(intval($val))
                : $class::from($val);
        }

        return collect($class::cases())->firstOrFail(fn (UnitEnum $enum) => $enum->name == $val);
    }

    private function castData(): void
    {
        foreach ($this->class->properties as $property) {
            [$role, $type, $key] = [$property->role, $property->type, $this->getKey($property)];

            if ($role == Role::ValidationOnly) {
                continue;
            }

            if ($type instanceof Types\Mixed_) {
                continue;
            }

            if (! Arr::has($this->data, $key)) {
                continue;
            }

            $rawValue = Arr::get($this->data, $key);

            // Leave nulls alone; a nullable property keeps its null value and
            // coercion has nothing to do.
            if (is_null($rawValue)) {
                continue;
            }

            try {
                $typedValue = $this->castValue($property, $type, $rawValue);
            } catch (\Exception|\ValueError $e) {
                $typedValue = $rawValue;
            }

            Arr::set($this->data, $key, $typedValue);
        }
    }

    private function castValue(Property $property, Type $type, mixed $rawValue): mixed
    {
        return match (true) {
            $type instanceof Types\Boolean => $this->toBoolean($rawValue),
            $type instanceof Types\Integer => $this->toInteger($rawValue),
            $type instanceof Types\Float_ => $this->toFloat($rawValue),
            $type instanceof Types\Date => $this->dateFactory->parse($rawValue),
            $type instanceof Types\String_ => $rawValue,
            $type instanceof Types\Enum => $this->toEnum($type, $rawValue),
            $type instanceof Types\Array_ => $this->toArrayOfType($property, $type, $rawValue),
            $type instanceof Class_ => $this->toNestedClass($property, $type, $rawValue),
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

            Arr::set(
                $this->data,
                $property->getFullKey() /* todo: get own key, always go back to first level? */,
                $value
            );
        }
    }

    private function addValidationOnlyValuesToData(): void
    {
        foreach ($this->getValidationOnlyProperties() as $property) {
            Arr::set(
                $this->data,
                '__request.'.$property->getFullKey(),
                Arr::get($this->data, $property->getFullKey()),
            );
        }

    }

    private function getKey(Property $property): string
    {
        return ($this->source != null)
            ? Str::after($property->getFullKey(), $this->source->getKey().'.')
            : $property->getFullKey();
    }

    public function getValue(Property $property): mixed
    {
        return Arr::get($this->data, $this->getKey($property), $property->default);
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
        return new ValidationRules($this->class);
    }

    /**
     * @return array<string, mixed>
     */
    public function data(): array
    {
        return $this->toArray();
    }
}
