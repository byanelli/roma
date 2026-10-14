<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Data\Attributes\AccessorAttribute;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;
use BYanelli\Roma\Data\Sources\Nested;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Mixed_;
use Closure;
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
     * `$nested` builds the definitions of nested objects, whose property sources are relative
     * to the object, into `$definitions`.
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
     * The key an attribute declares, if any. A KeyAttribute (e.g. a header or accessor) always
     * declares one; an ExplicitKeyAttribute (e.g. a source attribute or #[Key]) may leave it
     * null to use the property name.
     */
    private function declaredKey(object $attribute): ?string
    {
        $key = match (true) {
            $attribute instanceof KeyAttribute => $attribute->getKey(),
            $attribute instanceof ExplicitKeyAttribute => $attribute->getKey(),
            default => null,
        };

        return $key === '' ? null : $key;
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function getKeyFromAttributes(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if (($key = $this->declaredKey($attribute)) !== null) {
                return $key;
            }
        }

        return null;
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function assertSingleKey(array $attributes, string $subject): void
    {
        $keyed = array_values(array_filter($attributes, fn (object $attribute) => $this->declaredKey($attribute) !== null));

        if (count($keyed) > 1) {
            throw new RuntimeException(
                "Roma property \"{$subject}\" gets a key from both #[".class_basename($keyed[0]).'] and #['
                .class_basename($keyed[1]).']. Give the key in only one of them.'
            );
        }
    }

    /**
     * @param  array<int, object>  $attributes
     */
    private function getAccessorFromAttributes(array $attributes): ?Closure
    {
        return collect($attributes)
            ->whereInstanceOf(AccessorAttribute::class)
            ->map(fn (AccessorAttribute $attr) => $attr->getAccessor())
            ->first();
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
        $attributes = $this->getAttributeInstances($obj->getAttributes());

        $type = $obj->getType();
        $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
        $declaringClass = $obj->getDeclaringClass()?->getShortName() ?? '';

        $typeAttributes = $typeName !== null ? $this->policy->attributesForType($typeName) : [];
        $typeSource = $typeName !== null ? $this->policy->sourceForType($typeName) : null;

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

        if (! $this->hasSourceAttribute($attributes)) {
            $attributes = [...$attributes, ...$typeAttributes];
        }

        $this->assertSingleKey($attributes, "{$declaringClass}::\${$obj->getName()}");

        if ($this->nested) {
            $parent = new Nested;
        } else {
            $parent = $typeSource ?? $this->getSourceFromAttributes($attributes);
            $this->assertSourceAllowed($parent, "property \"{$declaringClass}::\${$obj->getName()}\"");
        }

        $key = $this->getKeyFromAttributes($attributes) ?: $obj->getName();

        return new Property(
            name: $obj->getName(),
            key: $key,
            wireKey: $this->getWireKey($attributes) ?: $key,
            type: $this->getTypeFromReflectionObject($obj, $this->getDiscriminated($attributes)),
            role: $this->getRole($obj),
            default: $this->getDefault($obj),
            parent: $parent,
            accessor: $this->getAccessorFromAttributes($attributes) ?: fn () => null,
            rules: $this->getRulesForParameterOrProperty($attributes),
            nullable: $obj->getType()?->allowsNull() ?? true,
            errorKey: $this->getErrorKeyFromAttributes($attributes),
            rawAttributes: $attributes,
            // A virtual (hooked, backing-less) property is a computed value with
            // no backing store. The mapper, validator and request TypeScript skip it.
            isVirtual: $obj instanceof ReflectionProperty && $obj->isVirtual(),
        );
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

    private function assertSourceAllowed(Source $source, string $subject): void
    {
        if (! $this->policy->allowsSource($source)) {
            throw new RuntimeException(
                "Roma {$subject} maps from the \"{$source->getKey()}\" source, which "
                .class_basename($this->policy).' does not allow.'
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
            $discriminator = Discriminator::fromAttribute($discriminated, $this->subject($obj), $contract);

            return $this->buildUnion($discriminator, null);
        }

        $classLevel = Discriminator::attributeOf($contract) ?? throw new RuntimeException(
            "Roma property \"{$this->subject($obj)}\" is typed as {$contract}, an interface or abstract class. "
            ."Add #[Discriminated] to {$contract} or to the property, or type the property with a concrete class."
        );

        return $this->buildUnion(Discriminator::fromAttribute($classLevel, $contract, $contract), $contract);
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
            "Roma property \"{$this->subject($obj)}\" is typed as the union ".implode('|', $names).'. '
            .'Add #[Discriminated(['.implode(', ', array_map(fn (string $c) => class_basename($c).'::class', $names)).'])] to the property.'
        );

        return $this->buildUnion(Discriminator::fromAttribute($discriminated, $this->subject($obj), union: $classes), null);
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
                    if (in_array($discriminator->key, [$property->source->getOwnKey(), $property->wireKey], true)) {
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

    private function subject(ReflectionParameter|ReflectionProperty $obj): string
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
            $declared instanceof ReflectionUnionType => $this->getUnionType($obj, $this->unionMemberNames($declared), $discriminated) ?? new Mixed_,
            default => new Mixed_,
        };

        if ($discriminated !== null && ! $this->appliesDiscriminator($type)) {
            throw new RuntimeException(
                "Roma #[Discriminated] on \"{$this->subject($obj)}\" applies only to a property typed as an interface, "
                .'an abstract class or a union of classes, or an array of one.'
            );
        }

        return $type;
    }

    /**
     * The names of a union type's members other than null, or an empty list when a member
     * is an intersection type.
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

            if ($member->getName() !== 'null') {
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
        return new Class_(
            class: $class->getName(),
            properties: [
                ...$this->getConstructorParameterAndClassProperties($class),
                ...$this->getValidationOnlyProperties($class),
            ],
        );
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
                key: $attr->getKey(),
                wireKey: $this->getWireKey($attributes) ?: $attr->getKey(),
                type: $attr->getType(),
                role: Role::ValidationOnly,
                default: new NoDefault,
                parent: $attr->getSource(),
                accessor: ($attr instanceof AccessorAttribute)
                    ? $attr->getAccessor()
                    : fn () => null,
                rules: $attr->getRules(AttributeTarget::Class_),
                errorKey: ($attr instanceof ErrorKeyAttribute) ? $attr->getErrorKey() : null,
                rawAttributes: $attributes,
            );
        }

        return $result;
    }
}
