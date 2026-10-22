<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\Delimited;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Unit;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;
use BYanelli\Roma\Data\Reflection\ReflectionParamOrProperty;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Mixed_;
use BYanelli\Roma\Missing;
use DateInterval;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Throwable;

class ClassDefinitionBuilder
{
    /**
     * @var array<string, array<class-string, Class_>>
     */
    private static array $cache = [];

    /**
     * `$nested` builds the definitions of nested objects, whose properties have no source and
     * key paths relative to the object, into `$definitions`.
     */
    public function __construct(
        private readonly SourcePolicy $policy,
        private readonly PhpDocTypeParser $phpDocTypeParser = new PhpDocTypeParser,
        private readonly NestedDefinitions $definitions = new NestedDefinitions,
        private readonly bool $nested = false,
    ) {}

    private function getFromReflectionParameterOrProperty(ReflectionParameter|ReflectionProperty $obj): Property
    {
        $prop = new ReflectionParamOrProperty($obj, $this->policy);

        $this->checkAttributes($prop);

        $attributes = $prop->attributes;

        Missing::assertNotAlone($prop->type, $prop->printableName);

        $source = $this->nested ? null : $this->topLevelSource($prop);

        $type = $source?->isInjected() ? new Mixed_ : $this->propertyType($prop);
        $key = $attributes->declaredKey() ?: $this->policy->defaultKey($prop->defaultKey);
        $optional = $prop->acceptsMissing || $attributes->hasInstanceOf(Optional::class);

        return new Property(
            name: $prop->name,
            keyPath: $this->keyPath($source, $key, $prop->name),
            wirePath: $this->splitKey($source, $attributes->wireKey() ?: $key, $prop->name),
            type: $type,
            role: $prop->role,
            default: $optional ? $prop->optionalDefault() : $prop->default,
            source: $source,
            rules: $attributes->rules(),
            nullable: $prop->nullable,
            errorKey: $attributes->errorKey(),
            rawAttributes: $attributes->all(),
            // The mapper, validator and request TypeScript skip a virtual property.
            isVirtual: $prop->isVirtual,
            optional: $optional,
        );
    }

    /**
     * Checks where the attributes may appear and how they combine.
     */
    private function checkAttributes(ReflectionParamOrProperty $prop): void
    {
        $attributes = $prop->attributes;
        $typeSource = $prop->typeSource;

        if ($prop->isVirtual) {
            $attributes->assertAllowedOnVirtual();
        }

        if ($this->nested) {
            $attributes->assertAllowedInNestedObject();
            $this->assertNoSourceInNestedObject($prop);
        }

        $sourceAttribute = $attributes->sourceAttribute();

        if ($typeSource !== null && $sourceAttribute !== null) {
            throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" is typed as {$prop->typeName}, which always maps from the "
                ."\"{$typeSource->getKey()}\" source, so #[".class_basename($sourceAttribute).'] has no effect. Remove it.'
            );
        }

        $attributes->assertCombinable();

        $attributes->assertStatusType();

        $attributes->assertNoPresentWithMissing();
        $attributes->assertNoPresentWithDefault();
    }

    /**
     * A nested object always inherits its location from the parent, so a property inside one
     * can't declare its own source, which would relocate it and fail validation as "required".
     */
    private function assertNoSourceInNestedObject(ReflectionParamOrProperty $prop): void
    {
        $typeSource = $prop->typeSource;

        if ($prop->attributes->sourceAttribute() !== null) {
            throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" is inside a nested object and "
                .'cannot declare its own source; source attributes and types that imply a source are only '
                .'available on top-level classes. To override a nested property\'s key, use #[Key] instead.'
            );
        }

        // A type-implied source would replace the parent's key path, so the
        // property would receive the whole slice of its parent's data.
        if ($typeSource !== null) {
            throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" is inside a nested object, but its "
                ."type {$prop->typeName} always maps from the \"{$typeSource->getKey()}\" source, which is not supported "
                .'there; declare the property on the top-level class.'
            );
        }
    }

    /**
     * Also checks the destination attribute against the policy.
     */
    private function topLevelSource(ReflectionParamOrProperty $prop): Source
    {
        $attributes = $prop->attributes;
        $source = $prop->typeSource
            ?? $this->policy->sourceFor($attributes->source(), $attributes->destinationAttribute()?->destination())
            ?? $this->policy->defaultSource();

        $this->assertSourceAllowed($source, "property \"{$prop->printableName}\"");
        $this->assertDestinationAllowed($prop);

        return $source;
    }

    private function propertyType(ReflectionParamOrProperty $prop): Type
    {
        $attributes = $prop->attributes;
        $type = $this->buildType($prop, $attributes->discriminated());
        $dateFormat = $attributes->dateFormat();

        if ($dateFormat !== null && ! Types\Date::isIn($type) && ! $prop->isUnionWithDate) {
            throw $attributes->dateFormatWithoutDate();
        }

        if ($attributes->hasInstanceOf(Delimited::class) && ! $type instanceof Types\Array_) {
            throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" has #[Delimited], which splits a string into an array, but its type is not an array."
            );
        }

        $unit = $attributes->firstInstanceOf(Unit::class);

        if ($unit !== null) {
            $type instanceof Types\Interval || throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" has #[Unit], which applies only to a CarbonInterval or DateInterval property."
            );

            $type = new Types\Interval($type->class, $unit->unit);
        }

        return $this->withDateFormat($type, $dateFormat);
    }

    private function keyPath(?Source $source, string $key, string $name): KeyPath
    {
        // '*' is Laravel's array wildcard and can't be escaped in a rule key.
        if (str_contains($key, '*')) {
            throw new RuntimeException("Roma key \"$key\" (property \"$name\") may not contain '*'.");
        }

        return match (true) {
            $source === null => $this->splitKey(null, $key, $name),
            // A single-value source's property is the whole value, so its key path is empty.
            $source->isSingleValue() => KeyPath::empty(),
            default => $this->splitKey($source, $source->normalizeKey($key), $name),
        };
    }

    /**
     * The source decides whether a dot nests, since one class can map from both kinds. A
     * property of a nested object has no source and is always in nested data, so its dots nest.
     */
    private function splitKey(?Source $source, string $key, string $name): KeyPath
    {
        if ($source !== null && ! $source->nestsKeys()) {
            return new KeyPath([$key]);
        }

        try {
            return KeyPath::fromDotted($key);
        } catch (InvalidArgumentException) {
            throw new RuntimeException(
                "Roma key \"$key\" (property \"$name\") has an empty segment. A dot separates nested keys; "
                .'write a literal dot as "\\.".'
            );
        }
    }

    /**
     * Applies #[DateFormat] to a date, or to the dates in an array or shape. A nested object's
     * dates keep their own formats.
     */
    private function withDateFormat(Type $type, ?string $format): Type
    {
        return match (true) {
            $format === null => $type,
            $type instanceof Types\Date => new Types\Date($type->class, $format),
            $type instanceof Types\Array_ => new Types\Array_($this->withDateFormat($type->memberType, $format), $type->isList, $type->keyType),
            $type instanceof Types\Shape => new Types\Shape(
                array_map(fn (Types\ShapeItem $item) => new Types\ShapeItem($item->key, $this->withDateFormat($item->type, $format), $item->optional, $item->nullable), $type->items),
                $type->sealed,
            ),
            default => $type,
        };
    }

    private function assertSourceAllowed(Source $source, string $description): void
    {
        if (! is_a($this->policy->inputType(), $source->inputType(), true)) {
            $policy = class_basename($this->policy);

            throw new RuntimeException(
                "Roma {$description} maps from the \"{$source->getKey()}\" source, which {$policy} does not allow: "
                .'the source reads from '.class_basename($source->inputType()).", and {$policy} maps from "
                .class_basename($this->policy->inputType()).'.'
            );
        }
    }

    /**
     * Two sources under one key would give their properties the same error keys.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<Property>  $properties
     */
    private function assertDistinctSourceKeys(ReflectionClass $class, array $properties): void
    {
        $sources = [];

        foreach ($properties as $property) {
            $source = $property->source;

            if ($property->isVirtual || $source === null) {
                continue;
            }

            [$first, $firstProperty] = $sources[$source->getKey()] ??= [$source, $property];

            if (! $first->isSameAs($source)) {
                throw new RuntimeException(
                    "Roma class {$class->getShortName()} maps \${$firstProperty->name} and \${$property->name} from two "
                    ."different sources with the key \"{$source->getKey()}\" (".class_basename($first).' and '
                    .class_basename($source).'), so their error keys would collide. Give one of the sources another key.'
                );
            }
        }
    }

    /**
     * Two properties may not share a key, and one may not be inside another's value, as
     * "error.message" is inside "error". Properties of one accessor, and class-level
     * constraints, which only add rules, may share a key.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<Property>  $properties
     */
    private function assertDistinctKeys(ReflectionClass $class, array $properties): void
    {
        $checked = array_values(array_filter(
            $properties,
            fn (Property $property) => ! $property->isVirtual && $property->role !== Role::ValidationOnly && ! $property->source?->isSingleValue(),
        ));

        $conflict = KeyPath::firstConflict(array_map(fn (Property $property) => $property->fullKey(), $checked));

        if ($conflict === null) {
            return;
        }

        [$first, $second] = [$checked[$conflict[0]], $checked[$conflict[1]]];
        [$firstKey, $secondKey] = [$first->fullKey(), $second->fullKey()];

        if ($firstKey->toKey() === $secondKey->toKey()) {
            $same = $first->errorKey === $second->errorKey
                ? "the same key \"{$first->errorKey}\""
                : "the same key: \"{$first->errorKey}\" and \"{$second->errorKey}\" are both read as \"{$secondKey->toKey()}\"";

            throw new RuntimeException(
                "Roma class {$class->getShortName()} maps \${$first->name} and \${$second->name} from {$same}. "
                .'Give one of them another key.'
            );
        }

        [$outer, $inner] = $firstKey->isPrefixOf($secondKey) ? [$first, $second] : [$second, $first];

        throw new RuntimeException(
            "Roma class {$class->getShortName()} maps \${$inner->name} from \"{$inner->errorKey}\", which is inside the value of "
            ."\${$outer->name} at \"{$outer->errorKey}\". Give one of them another key."
        );
    }

    private function assertDestinationAllowed(ReflectionParamOrProperty $prop): void
    {
        $attribute = $prop->attributes->destinationAttribute();

        if ($attribute !== null && ! $this->policy->allowsDestination($destination = $attribute->destination())) {
            throw new RuntimeException(
                "Roma property \"{$prop->printableName}\" has #[".class_basename($attribute).'], which '.class_basename($this->policy)
                .' does not allow: the data it maps from has no '.strtolower($destination->name).'. Remove the attribute.'
            );
        }
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<Property>
     */
    private function getPropertiesFromConstructorParameters(ReflectionClass $class): array
    {
        $result = [];

        if (($constructor = $class->getConstructor()) != null) {
            $constructorParameters = $constructor->getParameters();

            foreach ($constructorParameters as $constructorParameter) {
                $result[] = $this->getFromReflectionParameterOrProperty($constructorParameter);
            }
        }

        return $result;
    }

    /**
     * An exception is built through its constructor only, since its parents' public properties,
     * such as Laravel's RequestException::$response, are set there.
     *
     * @param  ReflectionClass<object>  $class
     * @return list<Property>
     */
    private function getPropertiesFromClassProperties(ReflectionClass $class): array
    {
        $result = [];

        if ($class->implementsInterface(Throwable::class)) {
            return [];
        }

        $classProperties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($classProperties as $classProperty) {
            if ($classProperty->isStatic() || $classProperty->isPromoted()) {
                continue;
            }

            $result[] = $this->getFromReflectionParameterOrProperty($classProperty);
        }

        return $result;
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<Property>
     */
    private function getConstructorParameterAndClassProperties(ReflectionClass $class): array
    {
        return [
            ...$this->getPropertiesFromConstructorParameters($class),
            ...$this->getPropertiesFromClassProperties($class),
        ];
    }

    private function getArrayType(
        ReflectionParamOrProperty $prop,
        ?Discriminated $discriminated,
    ): Types\Array_|Types\Shape {
        return $this->phpDocTypeParser->getArrayType(
            $prop->documented,
            fn (array $names) => count($names) === 1
                ? $this->getTypeByName($prop, $names[0], $discriminated)
                : $this->getUnionType($prop, $names, $discriminated)
                    ?? throw new RuntimeException('Unsupported array element type: '.implode('|', $names)),
        );
    }

    private function getTypeByName(
        ReflectionParamOrProperty $prop,
        string $name,
        ?Discriminated $discriminated = null,
    ): Type {
        return match ($name) {
            'string' => new Types\String_,
            'int' => new Types\Integer,
            'bool' => new Types\Boolean,
            'float' => new Types\Float_,
            'mixed' => new Mixed_,
            'array' => $this->getArrayType($prop, $discriminated),
            UploadedFile::class, SymfonyUploadedFile::class => new Types\File,
            default => match (true) {
                // Any DateTimeInterface implementor is a date. The bare interface
                // has no concrete target class; a concrete class carries its own.
                is_a($name, \DateTimeInterface::class, true) => new Types\Date(interface_exists($name) ? null : $name),
                is_a($name, DateInterval::class, true) => new Types\Interval($name),
                enum_exists($name) => new Types\Enum($name),
                interface_exists($name) || (class_exists($name) && new ReflectionClass($name)->isAbstract()) => $this->getContractType($prop, $name, $discriminated),
                class_exists($name) => $this->nestedBuilder()->buildClassDefinition($name),
                default => throw new RuntimeException("Unsupported type $name"),
            },
        };
    }

    private function nestedBuilder(): self
    {
        return new self($this->policy, $this->phpDocTypeParser, $this->definitions, nested: true);
    }

    /**
     * @param  class-string  $contract
     */
    private function getContractType(
        ReflectionParamOrProperty $prop,
        string $contract,
        ?Discriminated $discriminated,
    ): Types\Union {
        if ($discriminated !== null) {
            $discriminator = Discriminator::fromAttribute($discriminated, $prop->printableName, $contract);

            return $this->buildUnion($discriminator, null);
        }

        $classLevel = Discriminator::ofContract($contract) ?? throw new RuntimeException(
            "Roma property \"{$prop->printableName}\" is typed as {$contract}, an interface or abstract class. "
            ."Add #[Discriminated] or #[AutoDiscriminated] to {$contract}, add #[Discriminated] to the property, "
            .'or type the property with a concrete class.'
        );

        return $this->buildUnion($classLevel, $contract);
    }

    /**
     * The union for a native or PHPDoc union of classes such as `Card|Voucher`, or null when
     * the union has a member that is not mapped as an object, such as `int|string`.
     *
     * @param  list<string>  $names
     */
    private function getUnionType(
        ReflectionParamOrProperty $prop,
        array $names,
        ?Discriminated $discriminated,
    ): ?Types\Union {
        $classes = [];

        foreach ($names as $name) {
            if (! $this->isObjectTypeName($name)) {
                return null;
            }

            $classes[] = $name;
        }

        $discriminated ??= throw new RuntimeException(
            "Roma property \"{$prop->printableName}\" is typed as the union ".implode('|', $names).'. '
            .'Add #[Discriminated(['.implode(', ', array_map(fn (string $c) => class_basename($c).'::class', $names)).'])] to the property.'
        );

        return $this->buildUnion(Discriminator::fromAttribute($discriminated, $prop->printableName, union: $classes), null);
    }

    /**
     * @phpstan-assert-if-true class-string $name
     */
    private function isObjectTypeName(string $name): bool
    {
        return (class_exists($name) || interface_exists($name))
            && ! enum_exists($name)
            && ! is_a($name, \DateTimeInterface::class, true)
            && ! is_a($name, DateInterval::class, true)
            && ! is_a($name, SymfonyUploadedFile::class, true);
    }

    /**
     * @param  ?class-string  $contract
     */
    private function buildUnion(Discriminator $discriminator, ?string $contract): Types\Union
    {
        $builder = $this->nestedBuilder();
        $members = [];

        foreach ($discriminator->classes as $value => $class) {
            $members[$value] = $builder->buildClassDefinition($class);

            // A member that contains this union is still being built.
            $this->definitions->whenBuilt($class, function (Class_ $definition) use ($class, $discriminator): void {
                foreach ($definition->properties as $property) {
                    if (in_array($discriminator->key, [$property->keyPath->segments[0] ?? null, $property->wirePath->segments[0] ?? null], true)) {
                        throw new RuntimeException(
                            'Roma property "'.class_basename($class)."::\${$property->name}\" uses the key \"{$discriminator->key}\", "
                            .'which is the discriminator key of a union the class belongs to.'
                        );
                    }
                }
            });
        }

        return new Types\Union($discriminator->key, $members, $contract);
    }

    private function buildType(ReflectionParamOrProperty $prop, ?Discriminated $discriminated): Type
    {
        $declared = $prop->type;

        $type = match (true) {
            $declared instanceof ReflectionNamedType => $this->getTypeByName($prop, $declared->getName(), $discriminated),
            $declared instanceof ReflectionUnionType => $this->getUnionMembersType($prop, $prop->unionMemberNames, $discriminated),
            default => new Mixed_,
        };

        if ($discriminated !== null && ! $this->appliesDiscriminator($type)) {
            throw new RuntimeException(
                "Roma #[Discriminated] on \"{$prop->printableName}\" applies only to a property typed as an interface, "
                .'an abstract class or a union of classes, or an array of one.'
            );
        }

        return $type;
    }

    /**
     * A union with one member besides null and Missing is that member's type.
     *
     * @param  list<string>  $names
     */
    private function getUnionMembersType(ReflectionParamOrProperty $prop, array $names, ?Discriminated $discriminated): Type
    {
        return match (count($names)) {
            0 => new Mixed_,
            1 => $this->getTypeByName($prop, $names[0], $discriminated),
            default => $this->getUnionType($prop, $names, $discriminated) ?? new Mixed_,
        };
    }

    private function appliesDiscriminator(Type $type): bool
    {
        return match (true) {
            $type instanceof Types\Union => true,
            $type instanceof Types\Array_ => $this->appliesDiscriminator($type->memberType),
            $type instanceof Types\Shape => collect($type->items)->contains(fn (Types\ShapeItem $item) => $this->appliesDiscriminator($item->type)),
            default => false,
        };
    }

    /**
     * A top-level definition is cached per class and policy. A nested definition doesn't depend
     * on where the object appears, so one is built per class within a top-level build.
     *
     * @param  class-string|ReflectionClass<object>  $class
     */
    public function buildClassDefinition(string|ReflectionClass $class): Class_
    {
        if (is_string($class)) {
            $class = new ReflectionClass($class);
        }

        if ($this->nested) {
            return $this->definitions->get($class->getName(), fn () => $this->define($class));
        }

        return self::$cache[$this->policy->cacheKey()][$class->getName()]
            ??= new self($this->policy, $this->phpDocTypeParser, new NestedDefinitions)->define($class);
    }

    /**
     * @param  ReflectionClass<object>  $class
     */
    private function define(ReflectionClass $class): Class_
    {
        Discriminator::assertNotOnConcreteClass($class);

        $properties = [
            ...$this->getConstructorParameterAndClassProperties($class),
            ...$this->getValidationOnlyProperties($class),
        ];

        if (! $this->nested) {
            $this->assertDistinctSourceKeys($class, $properties);
        }

        $this->assertDistinctKeys($class, $properties);

        return new Class_(class: $class->getName(), properties: $properties);
    }

    /**
     * @param  ReflectionClass<object>  $class
     * @return list<Property>
     */
    private function getValidationOnlyProperties(ReflectionClass $class): array
    {
        $attributes = array_map(fn (ReflectionAttribute $attribute) => $attribute->newInstance(), $class->getAttributes());
        $wireKey = collect($attributes)->whereInstanceOf(WireKeyAttribute::class)->first()?->getWireKey();

        $result = [];

        foreach ($attributes as $attr) {
            if (! ($attr instanceof KeyAttribute
                && $attr instanceof RulesAttribute
                && $attr instanceof SourceAttribute)) {
                continue;
            }

            $source = $this->policy->sourceFor($attr->getSource(), null) ?? $attr->getSource();

            $this->assertSourceAllowed($source, 'attribute #['.class_basename($attr)."] on {$class->getShortName()}");

            $result[] = new Property(
                name: $attr->getKey(),
                keyPath: $this->keyPath($source, $attr->getKey(), $attr->getKey()),
                wirePath: $this->splitKey($source, $wireKey ?: $attr->getKey(), $attr->getKey()),
                type: $attr->getType(),
                role: Role::ValidationOnly,
                default: new NoDefault,
                source: $source,
                rules: $attr->getRules(AttributeTarget::Class_),
                errorKey: ($attr instanceof ErrorKeyAttribute) ? $attr->getErrorKey() : null,
                rawAttributes: $attributes,
            );
        }

        return $result;
    }
}
