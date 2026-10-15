<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\DateFormat;
use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Attributes\Optional;
use BYanelli\Roma\Attributes\Present;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Mixed_;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Serialization\DestinationAttribute;
use Illuminate\Http\UploadedFile;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

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

    /**
     * @param  array<int, object>  $attributes
     */
    private function getSourceFromAttributes(array $attributes): Source
    {
        return collect($attributes)
            ->whereInstanceOf(SourceAttribute::class)
            ->map(fn (SourceAttribute $attr) => $attr->getSource())
            ->first() ?: $this->policy->defaultSource();
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function hasSourceAttribute(array $attributes): bool
    {
        return collect($attributes)->whereInstanceOf(SourceAttribute::class)->isNotEmpty();
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function getKeyFromAttributes(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if (($key = PropertyAttributes::declaredKey($attribute)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function getErrorKeyFromAttributes(array $attributes): ?string
    {
        return collect($attributes)
            ->whereInstanceOf(ErrorKeyAttribute::class)
            ->map(fn (ErrorKeyAttribute $attr) => $attr->getErrorKey())
            ->first();
    }

    private function getDefault(ReflectionParameter|ReflectionProperty $obj): mixed
    {
        return $obj instanceof ReflectionParameter
            ? ($obj->isOptional() ? $obj->getDefaultValue() : new NoDefault)
            : ($obj->hasDefaultValue() ? $obj->getDefaultValue() : new NoDefault);
    }

    /**
     * @param  array<int, object>  $attributes
     * @return array<int, mixed>
     */
    private function getRulesForParameterOrProperty(array $attributes): array
    {
        return collect($attributes)
            ->whereInstanceOf(RulesAttribute::class)
            ->flatMap(function (RulesAttribute $attr) {
                return $attr->getRules(AttributeTarget::Property);
            })
            ->all();
    }

    /**
     * @param  array<int, ReflectionAttribute<object>>  $attributes
     * @return list<object>
     */
    private function getAttributeInstances(array $attributes): array
    {
        return array_values(array_map(
            fn (ReflectionAttribute $attr) => $attr->newInstance(),
            $attributes,
        ));
    }

    private function getFromReflectionParameterOrProperty(ReflectionParameter|ReflectionProperty $obj): Property
    {
        PropertyAttributes::assertNotClassOnly($obj->getAttributes(), $this->printableName($obj));
        $attributes = $this->getAttributeInstances($obj->getAttributes());

        $typeName = $this->declaredTypeName($obj);
        $declaringClass = $obj->getDeclaringClass()?->getShortName() ?? '';

        $typeAttributes = $typeName !== null ? $this->policy->attributesForType($typeName) : [];
        $typeSource = $typeName !== null ? $this->policy->sourceForType($typeName) : null;

        $isVirtual = $obj instanceof ReflectionProperty && $obj->isVirtual();

        if ($isVirtual) {
            PropertyAttributes::assertAllowedOnVirtual($attributes, $this->printableName($obj));
        }

        if ($this->nested) {
            PropertyAttributes::assertAllowedInNestedObject($attributes, $this->printableName($obj));
        }

        // A nested object always inherits its location from the parent, so a
        // property inside one cannot declare its own source: it would silently
        // relocate the property and fail validation as "required". To override
        // a nested property's key (e.g. a literal dotted key) use #[Key] instead.
        if ($this->nested
            && ($this->hasSourceAttribute($attributes) || $this->hasSourceAttribute($typeAttributes))) {
            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is inside a nested object and "
                .'cannot declare its own source; source attributes and types that imply a source are only '
                .'available on top-level classes. To override a nested property\'s key (e.g. a key containing '
                .'a literal dot) use #[Key] instead.'
            );
        }

        // A type-implied source would replace the parent's key path, so the
        // property would receive the whole slice of its parent's data.
        if ($this->nested && $typeSource !== null) {
            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is inside a nested object, but its "
                ."type {$typeName} always maps from the \"{$typeSource->getKey()}\" source, which is not supported "
                .'there; declare the property on the top-level class.'
            );
        }

        $sourceAttribute = collect($attributes)->whereInstanceOf(SourceAttribute::class)->first();

        if ($typeSource !== null && $sourceAttribute !== null) {
            throw new RuntimeException(
                "Roma property \"{$this->printableName($obj)}\" is typed as {$typeName}, which always maps from the "
                ."\"{$typeSource->getKey()}\" source, so #[".class_basename($sourceAttribute).'] has no effect. Remove it.'
            );
        }

        if (! $this->hasSourceAttribute($attributes)) {
            $attributes = [...$attributes, ...$typeAttributes];
        }

        PropertyAttributes::assertCombinable($attributes, $this->printableName($obj));
        PropertyAttributes::assertStatusType($attributes, $obj->getType(), $this->printableName($obj));

        if ($this->nested) {
            $source = null;
        } else {
            $source = $typeSource ?? $this->getSourceFromAttributes($attributes);
            $this->assertSourceAllowed($source, "property \"{$declaringClass}::\${$obj->getName()}\"");
            $this->assertDestinationAllowed($attributes, $this->printableName($obj));
        }

        $key = $this->getKeyFromAttributes($attributes) ?: $obj->getName();

        Missing::assertNotAlone($obj->getType(), $this->printableName($obj));
        $acceptsMissing = Missing::isInType($obj->getType());
        $optional = $acceptsMissing || collect($attributes)->contains(fn (object $attr) => $attr instanceof Optional);

        if ($acceptsMissing && collect($attributes)->contains(fn (object $attr) => $attr instanceof Present)) {
            throw new RuntimeException(
                "Roma property \"{$this->printableName($obj)}\" is typed with Missing, which allows the key to be absent, "
                .'and has #[Present], which requires it. Remove one of them.'
            );
        }

        PropertyAttributes::assertPresentWithoutDefault($attributes, ! $this->getDefault($obj) instanceof NoDefault, $this->printableName($obj));

        $nullable = $obj->getType()?->allowsNull() ?? true;

        $type = $this->getTypeFromReflectionObject($obj, $this->getDiscriminated($attributes));
        $dateFormat = collect($attributes)->whereInstanceOf(DateFormat::class)->first()?->format;

        if ($dateFormat !== null && ! Types\Date::isIn($type) && ! $this->isUnionWithDate($obj)) {
            throw PropertyAttributes::dateFormatWithoutDate($this->printableName($obj));
        }

        return new Property(
            name: $obj->getName(),
            keyPath: $this->keyPath($source, $key, $obj->getName()),
            wireKey: $this->getWireKey($attributes) ?: $key,
            type: $this->withDateFormat($type, $dateFormat),
            role: $this->getRole($obj),
            default: $optional ? $this->getOptionalDefault($obj, $nullable) : $this->getDefault($obj),
            source: $source,
            rules: $this->getRulesForParameterOrProperty($attributes),
            nullable: $nullable,
            errorKey: $this->getErrorKeyFromAttributes($attributes),
            rawAttributes: $attributes,
            // The mapper, validator and request TypeScript skip a virtual property.
            isVirtual: $isVirtual,
            optional: $optional,
        );
    }

    /**
     * A single-value source's property is the whole value, so its key path is empty.
     */
    private function keyPath(?Source $source, string $key, string $name): KeyPath
    {
        // '*' is Laravel's array wildcard and can't be escaped in a rule key.
        if (str_contains($key, '*')) {
            throw new RuntimeException("Roma key \"$key\" (property \"$name\") may not contain '*'.");
        }

        return match (true) {
            $source === null => new KeyPath([$key]),
            $source->isSingleValue() => new KeyPath,
            default => new KeyPath([$source->normalizeKey($key)]),
        };
    }

    /**
     * An absent optional property takes its default, else Missing when its type allows it. A
     * parameter then takes null; a property is left unset, so it needs none.
     */
    private function getOptionalDefault(ReflectionParameter|ReflectionProperty $obj, bool $nullable): mixed
    {
        $default = $this->getDefault($obj);

        if (! $default instanceof NoDefault) {
            return $default;
        }

        if (Missing::isInType($obj->getType())) {
            return Missing::instance();
        }

        if (! $obj instanceof ReflectionParameter || $nullable) {
            return $default;
        }

        throw new RuntimeException(
            "Roma parameter \"{$this->printableName($obj)}\" is #[Optional] but has no value for when it is absent. "
            .'Make it nullable (?string), add Missing to its type (string|Missing), or give it a default.'
        );
    }

    /**
     * The declared type's name, ignoring null and Missing, or null when there isn't exactly one.
     */
    private function declaredTypeName(ReflectionParameter|ReflectionProperty $obj): ?string
    {
        $type = $obj->getType();

        if ($type instanceof ReflectionNamedType) {
            return $type->getName();
        }

        $names = $type instanceof ReflectionUnionType ? $this->unionMemberNames($type) : [];

        return count($names) === 1 ? $names[0] : null;
    }

    /**
     * Whether the declared type is a union with a date member, such as `DateTimeImmutable|string`,
     * which is mapped as mixed and serialized with the format.
     */
    private function isUnionWithDate(ReflectionParameter|ReflectionProperty $obj): bool
    {
        $type = $obj->getType();

        return $type instanceof ReflectionUnionType && array_any(
            $type->getTypes(),
            fn ($member) => $member instanceof ReflectionNamedType && is_a($member->getName(), \DateTimeInterface::class, true),
        );
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

    /**
     * @param  list<object>  $attributes
     */
    private function getWireKey(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof WireKeyAttribute) {
                return $attribute->getWireKey();
            }
        }

        return null;
    }

    private function assertSourceAllowed(Source $source, string $description): void
    {
        if (! is_a($this->policy->payloadType(), $source->payloadType(), true)) {
            $policy = class_basename($this->policy);

            throw new RuntimeException(
                "Roma {$description} maps from the \"{$source->getKey()}\" source, which {$policy} does not allow: "
                .'the source reads from '.class_basename($source->payloadType()).", and {$policy} maps from "
                .class_basename($this->policy->payloadType()).'.'
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
     * Properties of one accessor, and class-level constraints, which only add rules, may share
     * a key.
     *
     * @param  ReflectionClass<object>  $class
     * @param  list<Property>  $properties
     */
    private function assertDistinctKeys(ReflectionClass $class, array $properties): void
    {
        $seen = [];

        foreach ($properties as $property) {
            if ($property->isVirtual || $property->role === Role::ValidationOnly || $property->source?->isSingleValue()) {
                continue;
            }

            $key = $property->fullKey();
            $first = $seen[serialize($key->segments)] ??= $property;

            if ($first === $property) {
                continue;
            }

            $same = $first->errorKey === $property->errorKey
                ? "the same key \"{$first->errorKey}\""
                : "the same key: \"{$first->errorKey}\" and \"{$property->errorKey}\" are both read as \"{$key->toKey()}\"";

            throw new RuntimeException(
                "Roma class {$class->getShortName()} maps \${$first->name} and \${$property->name} from {$same}. "
                .'Give one of them another key.'
            );
        }
    }

    /**
     * @param  list<object>  $attributes
     */
    private function assertDestinationAllowed(array $attributes, string $printableName): void
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof DestinationAttribute && ! $this->policy->allowsDestination($destination = $attribute->destination())) {
                throw new RuntimeException(
                    "Roma property \"{$printableName}\" has #[".class_basename($attribute).'], which '.class_basename($this->policy)
                    .' does not allow: the data it maps from has no '.strtolower($destination->name).'. Remove the attribute.'
                );
            }
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
     * @param  ReflectionClass<object>  $class
     * @return list<Property>
     */
    private function getPropertiesFromClassProperties(ReflectionClass $class): array
    {
        $result = [];

        $classProperties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

        foreach ($classProperties as $classProperty) {
            if ($classProperty->isStatic() || $classProperty->isPromoted()) {
                continue;
            }

            $result[] = $this->getFromReflectionParameterOrProperty($classProperty);
        }

        return $result;
    }

    private function getRole(ReflectionParameter|ReflectionProperty $obj): Role
    {
        return ($obj instanceof ReflectionParameter)
            ? Role::Constructor
            : Role::Property;
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

    /**
     * @param  array<int, object>  $attributes
     */
    private function getDiscriminated(array $attributes): ?Discriminated
    {
        return collect($attributes)->whereInstanceOf(Discriminated::class)->first();
    }

    private function getArrayType(
        ReflectionParameter|ReflectionProperty $obj,
        ?Discriminated $discriminated,
    ): Types\Array_|Types\Shape {
        return $this->phpDocTypeParser->getArrayType(
            $obj,
            fn (array $names) => count($names) === 1
                ? $this->getTypeByName($obj, $names[0], $discriminated)
                : $this->getUnionType($obj, $names, $discriminated)
                    ?? throw new RuntimeException('Unsupported array element type: '.implode('|', $names)),
        );
    }

    private function getTypeByName(
        ReflectionParameter|ReflectionProperty $obj,
        string $name,
        ?Discriminated $discriminated = null,
    ): Type {
        return match ($name) {
            'string' => new Types\String_,
            'int' => new Types\Integer,
            'bool' => new Types\Boolean,
            'float' => new Types\Float_,
            'mixed' => new Mixed_,
            'array' => $this->getArrayType($obj, $discriminated),
            UploadedFile::class, SymfonyUploadedFile::class => new Types\File,
            default => match (true) {
                // Any DateTimeInterface implementor is a date. The bare interface
                // has no concrete target class; a concrete class carries its own.
                is_a($name, \DateTimeInterface::class, true) => new Types\Date(interface_exists($name) ? null : $name),
                enum_exists($name) => new Types\Enum($name),
                interface_exists($name) || (class_exists($name) && new ReflectionClass($name)->isAbstract()) => $this->getContractType($obj, $name, $discriminated),
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
        ReflectionParameter|ReflectionProperty $obj,
        string $contract,
        ?Discriminated $discriminated,
    ): Types\Union {
        if ($discriminated !== null) {
            $discriminator = Discriminator::fromAttribute($discriminated, $this->printableName($obj), $contract);

            return $this->buildUnion($discriminator, null);
        }

        $classLevel = Discriminator::ofContract($contract) ?? throw new RuntimeException(
            "Roma property \"{$this->printableName($obj)}\" is typed as {$contract}, an interface or abstract class. "
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
        ReflectionParameter|ReflectionProperty $obj,
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
            "Roma property \"{$this->printableName($obj)}\" is typed as the union ".implode('|', $names).'. '
            .'Add #[Discriminated(['.implode(', ', array_map(fn (string $c) => class_basename($c).'::class', $names)).'])] to the property.'
        );

        return $this->buildUnion(Discriminator::fromAttribute($discriminated, $this->printableName($obj), union: $classes), null);
    }

    /**
     * @phpstan-assert-if-true class-string $name
     */
    private function isObjectTypeName(string $name): bool
    {
        return (class_exists($name) || interface_exists($name))
            && ! enum_exists($name)
            && ! is_a($name, \DateTimeInterface::class, true)
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
                    if (in_array($discriminator->key, [$property->keyPath->toKey(), $property->wireKey], true)) {
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

    private function printableName(ReflectionParameter|ReflectionProperty $obj): string
    {
        return ($obj->getDeclaringClass()?->getShortName() ?? '').'::$'.$obj->getName();
    }

    public function getTypeFromReflectionObject(
        ReflectionParameter|ReflectionProperty $obj,
        ?Discriminated $discriminated = null,
    ): Type {
        $declared = $obj->getType();

        $type = match (true) {
            $declared instanceof ReflectionNamedType => $this->getTypeByName($obj, $declared->getName(), $discriminated),
            $declared instanceof ReflectionUnionType => $this->getUnionMembersType($obj, $this->unionMemberNames($declared), $discriminated),
            default => new Mixed_,
        };

        if ($discriminated !== null && ! $this->appliesDiscriminator($type)) {
            throw new RuntimeException(
                "Roma #[Discriminated] on \"{$this->printableName($obj)}\" applies only to a property typed as an interface, "
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
    private function getUnionMembersType(ReflectionParameter|ReflectionProperty $obj, array $names, ?Discriminated $discriminated): Type
    {
        return match (count($names)) {
            0 => new Mixed_,
            1 => $this->getTypeByName($obj, $names[0], $discriminated),
            default => $this->getUnionType($obj, $names, $discriminated) ?? new Mixed_,
        };
    }

    /**
     * The names of a union type's members other than null and Missing, or an empty list when
     * a member is an intersection type.
     *
     * @return list<string>
     */
    private function unionMemberNames(ReflectionUnionType $type): array
    {
        $names = [];

        foreach ($type->getTypes() as $member) {
            if (! $member instanceof ReflectionNamedType) {
                return [];
            }

            if (! in_array($member->getName(), ['null', Missing::class], true)) {
                $names[] = $member->getName();
            }
        }

        return $names;
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
        $attributes = $this->getAttributeInstances($class->getAttributes());

        $result = [];

        foreach ($attributes as $attr) {
            if (! ($attr instanceof KeyAttribute
                && $attr instanceof RulesAttribute
                && $attr instanceof SourceAttribute)) {
                continue;
            }

            $this->assertSourceAllowed($attr->getSource(), 'attribute #['.class_basename($attr)."] on {$class->getShortName()}");

            $result[] = new Property(
                name: $attr->getKey(),
                keyPath: $this->keyPath($attr->getSource(), $attr->getKey(), $attr->getKey()),
                wireKey: $this->getWireKey($attributes) ?: $attr->getKey(),
                type: $attr->getType(),
                role: Role::ValidationOnly,
                default: new NoDefault,
                source: $attr->getSource(),
                rules: $attr->getRules(AttributeTarget::Class_),
                errorKey: ($attr instanceof ErrorKeyAttribute) ? $attr->getErrorKey() : null,
                rawAttributes: $attributes,
            );
        }

        return $result;
    }
}
