<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Attributes\AccessorAttribute;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Data\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Data\Attributes\KeyAttribute;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use BYanelli\Roma\Data\Attributes\SourceAttribute;
use BYanelli\Roma\Data\Attributes\WireKeyAttribute;
use BYanelli\Roma\Data\Sources\Property as PropertySource;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Mixed_;
use Closure;
use Illuminate\Http\Resources\MissingValue;
use Illuminate\Http\UploadedFile;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use RuntimeException;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

readonly class ClassDefinitionBuilder
{
    public function __construct(
        private SourcePolicy $policy,
        private ?Source $parentSource = null,
        private PhpDocTypeParser $phpDocTypeParser = new PhpDocTypeParser,
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
     * The explicit key an attribute declares, if any. A KeyAttribute (e.g. a
     * header or accessor) always supplies one; an ExplicitKeyAttribute (e.g. a
     * source attribute or #[Key]) may supply one or leave it null to use the
     * property name.
     *
     * @param  array<int, object>  $attributes
     */
    private function getKeyFromAttributes(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            $key = match (true) {
                $attribute instanceof KeyAttribute => $attribute->getKey(),
                $attribute instanceof ExplicitKeyAttribute => $attribute->getKey(),
                default => null,
            };

            if ($key !== null && $key !== '') {
                return $key;
            }
        }

        return null;
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
            ? ($obj->isOptional() ? $obj->getDefaultValue() : new MissingValue)
            : ($obj->hasDefaultValue() ? $obj->getDefaultValue() : new MissingValue);
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
        if ($this->parentSource !== null
            && ($this->hasSourceAttribute($attributes) || $this->hasSourceAttribute($typeAttributes))) {
            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is inside a nested object and "
                .'cannot declare its own source; source attributes and types that imply a source are only '
                .'available on top-level classes. To override a nested property\'s key (e.g. a key containing '
                .'a literal dot) use #[Key] instead.'
            );
        }

        // A key attribute with no source (#[Key]) only overrides a key inside a
        // nested object; a top-level property has no inherited location to override.
        $keyWithoutSource = collect($attributes)->contains(
            fn (object $attr) => $attr instanceof ExplicitKeyAttribute && ! $attr instanceof SourceAttribute
        );

        if ($this->parentSource === null && $keyWithoutSource) {
            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is on a top-level class, where "
                .'#[Key] does not apply; pass the key to the source attribute instead, e.g. #[Input(\'a.b\')].'
            );
        }

        // A type-implied source would replace the parent's key path, so the
        // property would receive the whole slice of its parent's data.
        if ($this->parentSource !== null && $typeSource !== null) {
            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is inside a nested object, but its "
                ."type {$typeName} always maps from the \"{$typeSource->getKey()}\" source, which is not supported "
                .'there; declare the property on the top-level class.'
            );
        }

        if (! $this->hasSourceAttribute($attributes)) {
            $attributes = [...$attributes, ...$typeAttributes];
        }

        $parent = $typeSource ?? $this->parentSource ?? $this->getSourceFromAttributes($attributes);

        if ($this->parentSource === null) {
            $this->assertSourceAllowed($parent, "property \"{$declaringClass}::\${$obj->getName()}\"");
        }

        $key = $this->getKeyFromAttributes($attributes) ?: $obj->getName();

        return new Property(
            name: $obj->getName(),
            key: $key,
            wireKey: $this->getWireKey($attributes) ?: $key,
            type: $this->getTypeFromReflectionObject($parent, $key, $obj),
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

    private function getArrayType(Source $parent, string $key, ReflectionParameter|ReflectionProperty $obj): Types\Array_
    {
        $arrayType = $this->phpDocTypeParser->getArrayType($obj);

        return new Types\Array_(
            $this->getTypeByName($parent, $key, $obj, $arrayType['elementTypeName']),
            $arrayType['isList'],
        );
    }

    private function getTypeByName(
        Source $parent,
        string $key,
        ReflectionParameter|ReflectionProperty $obj,
        string $name,
    ): Type {
        return match ($name) {
            'string' => new Types\String_,
            'int' => new Types\Integer,
            'bool' => new Types\Boolean,
            'float' => new Types\Float_,
            'array' => $this->getArrayType($parent, $key, $obj),
            UploadedFile::class, SymfonyUploadedFile::class => new Types\File,
            default => match (true) {
                // Any DateTimeInterface implementor is a date. The bare interface
                // has no concrete target class; a concrete class carries its own.
                is_a($name, \DateTimeInterface::class, true) => new Types\Date(interface_exists($name) ? null : $name),
                enum_exists($name) => new Types\Enum($name),
                // A contract — an interface, or an abstract class — names a
                // shape without being one, so there is no class to descend
                // into. Which concrete classes satisfy it is left open here;
                // the TypeScript generator discovers them.
                interface_exists($name) || (class_exists($name) && new ReflectionClass($name)->isAbstract()) => new Types\Polymorphic($name),
                class_exists($name) => new ClassDefinitionBuilder($this->policy, new PropertySource($parent, $key), $this->phpDocTypeParser)
                    ->buildClassDefinition($name),
                default => throw new RuntimeException("Unsupported type $name"),
            },
        };
    }

    public function getTypeFromReflectionObject(
        Source $parent,
        string $key,
        ReflectionParameter|ReflectionProperty $obj,
    ): Type {
        return ($obj->getType() instanceof ReflectionNamedType)
            ? $this->getTypeByName($parent, $key, $obj, $obj->getType()->getName())
            : new Mixed_;
    }

    /**
     * @param  class-string|ReflectionClass<object>  $class
     */
    public function buildClassDefinition(string|ReflectionClass $class): Class_
    {
        if (is_string($class)) {
            $class = new ReflectionClass($class);
        }

        $className = $class->getName();

        // Tier-1 cache: a top-level definition is reflected and PHPDoc-parsed
        // once per class and policy per process. Only top-level builds are
        // cached — a nested definition bakes its parent's key path into every
        // property source, so it is only valid inside that parent tree (and is
        // cached as part of it). Class_ definitions are immutable, so reuse is
        // safe. (A method-static, not a static property, because a readonly
        // class may not declare one.)
        /** @var array<string, array<class-string, Class_>> $cache */
        static $cache = [];

        $policyKey = $this->policy->cacheKey();

        if ($this->parentSource === null && isset($cache[$policyKey][$className])) {
            return $cache[$policyKey][$className];
        }

        $definition = new Class_(
            class: $className,
            properties: [
                ...$this->getConstructorParameterAndClassProperties($class),
                ...$this->getValidationOnlyProperties($class),
            ],
        );

        if ($this->parentSource === null) {
            $cache[$policyKey][$className] = $definition;
        }

        return $definition;
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
                default: new MissingValue,
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
