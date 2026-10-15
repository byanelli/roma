<?php

namespace BYanelli\Roma\JsonSchema;

use BackedEnum;
use BYanelli\Roma\Attributes\Description;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Role;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types;
use BYanelli\Roma\Data\Types\Class_;
use Illuminate\JsonSchema\Types as Schema;
use RuntimeException;
use UnitEnum;

/**
 * Builds Laravel JsonSchema types from the definition a class is mapped with from a plain
 * array, such as the arguments of an MCP or AI SDK tool.
 */
final readonly class ClassSchema
{
    private RuleKeywords $rules;

    /**
     * @param  class-string  $root  the class being described, for error messages
     */
    private function __construct(private string $root)
    {
        $this->rules = new RuleKeywords;
    }

    /**
     * The property schemas, keyed by input key: what a tool's `schema()` method returns.
     *
     * @param  class-string  $class
     * @return array<string, Schema\Type>
     */
    public static function properties(string $class): array
    {
        return new self($class)->propertiesOf(self::definition($class), [$class => true]);
    }

    /**
     * @param  class-string  $class
     */
    public static function object(string $class): Schema\ObjectType
    {
        return new self($class)->objectOf(self::definition($class), []);
    }

    /**
     * @param  class-string  $class
     */
    private static function definition(string $class): Class_
    {
        return new ClassDefinitionBuilder(new ArrayPolicy)->buildClassDefinition($class);
    }

    /**
     * @param  array<class-string, true>  $ancestors  the classes being described, which may not contain themselves
     * @param  array<string, Schema\Type>  $leading  properties placed first, such as a discriminator
     */
    private function objectOf(Class_ $class, array $ancestors, array $leading = []): Schema\ObjectType
    {
        if (isset($ancestors[$class->class])) {
            throw new RuntimeException(
                "Roma can't build a JSON Schema for {$this->root}: {$class->class} contains itself, and Laravel's "
                .'JsonSchema types have no $ref to refer back to it.'
            );
        }

        $object = new Schema\ObjectType([...$leading, ...$this->propertiesOf($class, [...$ancestors, $class->class => true])]);

        $description = Description::ofClass($class->class);

        return $description !== null ? $object->description($description) : $object;
    }

    /**
     * @param  array<class-string, true>  $ancestors
     * @return array<string, Schema\Type>
     */
    private function propertiesOf(Class_ $class, array $ancestors): array
    {
        $properties = [];

        foreach ($class->properties as $property) {
            if ($property->role === Role::ValidationOnly || $property->isVirtual) {
                continue;
            }

            $properties[$property->source->getOwnKey()] = $this->propertySchema($property, $ancestors);
        }

        return $properties;
    }

    /**
     * @param  array<class-string, true>  $ancestors
     */
    private function propertySchema(Property $property, array $ancestors): Schema\Type
    {
        $schema = $this->typeOf($property->type, $ancestors, $property->attribute(Description::class)?->text);

        $this->rules->apply($schema, $property->rules);
        $this->applyDefault($schema, $property->default);

        if ($property->nullable) {
            $schema->nullable();
        }

        if ($property->isRequired) {
            $schema->required();
        }

        return $schema;
    }

    /**
     * @param  array<class-string, true>  $ancestors
     * @param  ?string  $description  from #[Description] on the property, which replaces the class's own
     */
    private function typeOf(Type $type, array $ancestors, ?string $description = null): Schema\Type
    {
        if ($type instanceof Types\Date) {
            $schema = new Schema\StringType;

            return $this->rules->dateFormat($description !== null ? $schema->description($description) : $schema, $type->format);
        }

        if ($type instanceof Types\Array_ && $type->keyType instanceof Types\String_ && ($values = $this->typeName($type->memberType)) !== null) {
            $description = ($description !== null ? rtrim($description, '. ').'. ' : '')."Values: {$values}";
        }

        $schema = match (true) {
            $type instanceof Types\String_ => new Schema\StringType,
            $type instanceof Types\Integer => new Schema\IntegerType,
            $type instanceof Types\Float_ => new Schema\NumberType,
            $type instanceof Types\Boolean => new Schema\BooleanType,
            $type instanceof Types\Enum => $this->enumOf($type->class),
            $type instanceof Class_ && $type->parsesStringValue() => new Schema\StringType,
            $type instanceof Class_ => $this->objectOf($type, $ancestors),
            $type instanceof Types\Array_ => $this->arrayOf($type, $ancestors),
            $type instanceof Types\Shape => $this->shapeOf($type, $ancestors),
            $type instanceof Types\Union => $this->unionOf($type, $ancestors),
            $type instanceof Types\File => throw $this->fileUpload(),
            default => new Schema\UnionType(Schema\UnionType::SUPPORTED),
        };

        return $description !== null ? $schema->description($description) : $schema;
    }

    private function fileUpload(): RuntimeException
    {
        return new RuntimeException(
            "Roma can't build a JSON Schema for {$this->root}: it contains a file upload. A schema describes MCP or AI "
            .'tool input, whose arguments are JSON, and JSON can\'t carry an uploaded file.'
        );
    }

    /**
     * A readable name for the values of a map, which have no schema: a class's short name or a
     * JSON type. Null for mixed.
     */
    private function typeName(Type $type): ?string
    {
        return match (true) {
            $type instanceof Types\String_, $type instanceof Class_ && $type->parsesStringValue() => 'string',
            $type instanceof Types\Integer => 'integer',
            $type instanceof Types\Float_ => 'number',
            $type instanceof Types\Boolean => 'boolean',
            $type instanceof Types\Date => 'date',
            $type instanceof Types\Enum => class_basename($type->class),
            $type instanceof Class_ => class_basename($type->class),
            $type instanceof Types\Shape => 'object',
            $type instanceof Types\Array_ => ($type->keyType instanceof Types\String_ ? 'map' : 'array')
                .(($values = $this->typeName($type->memberType)) !== null ? " of {$values}" : ''),
            $type instanceof Types\Union => $type->contract !== null
                ? class_basename($type->contract)
                : implode(' | ', array_map(fn (Class_ $member) => class_basename($member->class), $type->members)),
            $type instanceof Types\File => throw $this->fileUpload(),
            default => null,
        };
    }

    /**
     * Mapping also accepts a backed enum as `{name, value}`; the schema asks for the value.
     *
     * @param  class-string<UnitEnum>  $class
     */
    private function enumOf(string $class): Schema\Type
    {
        $cases = $class::cases();

        if (! is_a($class, BackedEnum::class, true)) {
            return new Schema\StringType()->enum(array_map(fn (UnitEnum $case) => $case->name, $cases));
        }

        $values = array_map(fn (UnitEnum $case) => $case instanceof BackedEnum ? $case->value : $case->name, $cases);
        $schema = $values !== [] && is_int($values[0]) ? new Schema\IntegerType : new Schema\StringType;

        return $schema->enum($values);
    }

    /**
     * A map with string keys is an object whose values have no schema, since Laravel's
     * ObjectType only supports `additionalProperties: false`. Its description gives the value type.
     *
     * @param  array<class-string, true>  $ancestors
     */
    private function arrayOf(Types\Array_ $type, array $ancestors): Schema\Type
    {
        if ($type->keyType instanceof Types\String_) {
            return new Schema\ObjectType;
        }

        $array = new Schema\ArrayType;

        return $type->memberType instanceof Types\Mixed_ ? $array : $array->items($this->typeOf($type->memberType, $ancestors));
    }

    /**
     * @param  array<class-string, true>  $ancestors
     */
    private function shapeOf(Types\Shape $type, array $ancestors): Schema\ObjectType
    {
        $properties = [];

        foreach ($type->items as $item) {
            $schema = $this->typeOf($item->type, $ancestors);

            if ($item->nullable) {
                $schema->nullable();
            }

            if (! $item->optional) {
                $schema->required();
            }

            $properties[$item->key] = $schema;
        }

        return new Schema\ObjectType($properties);
    }

    /**
     * Each member is an object whose discriminator key allows only its own value.
     *
     * @param  array<class-string, true>  $ancestors
     */
    private function unionOf(Types\Union $type, array $ancestors): Schema\AnyOfType
    {
        $members = [];

        foreach ($type->members as $value => $member) {
            $discriminator = new Schema\StringType()->enum([(string) $value])->required();

            $members[] = $this->objectOf($member, $ancestors, [$type->key => $discriminator]);
        }

        $union = new Schema\AnyOfType($members);
        $description = $type->contract !== null ? Description::ofClass($type->contract) : null;

        return $description !== null ? $union->description($description) : $union;
    }

    private function applyDefault(Schema\Type $schema, mixed $default): void
    {
        $value = $this->json($default);

        if ($value === null || ($value === [] && $schema instanceof Schema\ObjectType)) {
            return;
        }

        // The default() setters are typed per type; Laravel's Deserializer assigns it the same way.
        (fn () => $this->default = $value)->call($schema);
    }

    /**
     * The JSON form of a default value, or null when it has none.
     */
    private function json(mixed $value): mixed
    {
        if (is_array($value)) {
            $items = array_map($this->json(...), $value);

            return in_array(null, $items, true) ? null : $items;
        }

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            is_scalar($value) => $value,
            default => null,
        };
    }
}
