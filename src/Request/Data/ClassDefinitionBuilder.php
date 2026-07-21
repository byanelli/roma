<?php

namespace BYanelli\Roma\Request\Data;

use BYanelli\Roma\Request\Attributes\AccessorAttribute;
use BYanelli\Roma\Request\Attributes\AttributeTarget;
use BYanelli\Roma\Request\Attributes\ErrorKeyAttribute;
use BYanelli\Roma\Request\Attributes\ExplicitKeyAttribute;
use BYanelli\Roma\Request\Attributes\Header as HeaderAttribute;
use BYanelli\Roma\Request\Attributes\Key;
use BYanelli\Roma\Request\Attributes\KeyAttribute;
use BYanelli\Roma\Request\Attributes\RulesAttribute;
use BYanelli\Roma\Request\Attributes\SourceAttribute;
use BYanelli\Roma\Request\Data\Sources\File;
use BYanelli\Roma\Request\Data\Sources\Header;
use BYanelli\Roma\Request\Data\Sources\Input;
use BYanelli\Roma\Request\Data\Sources\Property as PropertySource;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Data\Types\Mixed_;
use BYanelli\Roma\Request\Enums\HasRequestSource;
use BYanelli\Roma\Response\Attributes\Header as ResponseHeaderAttribute;
use BYanelli\Roma\Response\Attributes\Key as ResponseKey;
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
            ->first() ?: new Input;
    }

    /**
     * The explicit request key an attribute declares, if any. A KeyAttribute
     * (header/accessor) always supplies one; an ExplicitKeyAttribute (Body /
     * Query / Input) may supply one or leave it null to use the property name.
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

        // A nested object always inherits its location from the parent, so a
        // property inside one cannot declare its own source: a source attribute
        // (query/body/input/header/route/cookie/accessor) or a self-sourcing
        // metadata type would silently relocate it, surfacing as a baffling
        // "required" validation error. To override a nested property's key
        // (e.g. a literal dotted key) use #[Key] instead.
        $declaresOwnSource = collect($attributes)->whereInstanceOf(SourceAttribute::class)->isNotEmpty()
            || ($typeName !== null && (enum_exists($typeName) || class_exists($typeName)) && is_a($typeName, HasRequestSource::class, true));

        // Reject a source on a nested property up front with an actionable
        // message rather than let it silently misbehave.
        if ($this->parentSource !== null && $declaresOwnSource) {
            $declaringClass = $obj->getDeclaringClass()?->getShortName() ?? '';

            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is inside a nested request object and "
                .'cannot declare its own source; sources (query/body/input/header/route/cookie/accessors and '
                .'metadata enums) are only available on top-level request classes. To override a nested '
                .'property\'s key (e.g. a key containing a literal dot) use #[Key] instead.'
            );
        }

        // #[Key] is the inverse: it overrides a key only inside a nested object.
        // On a top-level property there is no inherited location to override, so
        // reject it and point at the source attribute's key argument.
        if ($this->parentSource === null && collect($attributes)->contains(fn (object $attr) => $attr instanceof Key)) {
            $declaringClass = $obj->getDeclaringClass()?->getShortName() ?? '';

            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is on a top-level request class, where "
                .'#[Key] does not apply; pass the key to the source attribute instead, e.g. #[Input(\'a.b\')].'
            );
        }

        // Nested file uploads are not supported for 1.0: the file-source
        // override conflicts with the parent's key-path slicing, so the array
        // slice would be assigned straight to the UploadedFile property and
        // TypeError. Reject up front with an actionable message.
        if ($this->parentSource !== null && in_array($typeName, [UploadedFile::class, SymfonyUploadedFile::class])) {
            $declaringClass = $obj->getDeclaringClass()?->getShortName() ?? '';

            throw new RuntimeException(
                "Roma property \"{$declaringClass}::\${$obj->getName()}\" is a file upload inside a nested request "
                .'object, which is not supported; declare the UploadedFile property on the top-level request class.'
            );
        }

        // Self-sourcing metadata types: a property typed as one of these (a
        // metadata enum like Method/ContentType, or a value object like
        // Authorization) with no explicit source attribute infers its source
        // from the type, exactly as if the equivalent source attribute had been
        // written.
        if ($typeName !== null
            && collect($attributes)->whereInstanceOf(SourceAttribute::class)->isEmpty()
            && (enum_exists($typeName) || class_exists($typeName))
            && is_a($typeName, HasRequestSource::class, true)) {
            $attributes = [...$attributes, $typeName::requestSourceAttribute()];
        }

        $parent = in_array($typeName, [UploadedFile::class, SymfonyUploadedFile::class])
            ? new File
            : ($this->parentSource ?: $this->getSourceFromAttributes($attributes));

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
            // A virtual (hooked, backing-less) property is a computed value, not
            // a stored one. On the request side it is never a mappable input; the
            // request mapper, validator and request TypeScript all skip it.
            isVirtual: $obj instanceof ReflectionProperty && $obj->isVirtual(),
        );
    }

    /**
     * The key a property appears under on the wire, when an attribute relocates
     * it off its property name. A request #[Header] normalizes its lookup key
     * (e.g. "X-Api-Key" -> "x_api_key"), but the client sends the original
     * header name, so emit that; a response #[Header] likewise emits its real
     * header name, and a response #[Key] renames the serialized key. Everything
     * else uses the property's own key.
     *
     * @param  list<object>  $attributes
     */
    private function getWireKey(array $attributes): ?string
    {
        foreach ($attributes as $attribute) {
            if ($attribute instanceof HeaderAttribute || $attribute instanceof ResponseHeaderAttribute) {
                return $attribute->name;
            }

            if ($attribute instanceof ResponseKey) {
                return $attribute->key;
            }
        }

        return null;
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
            'array' => new Types\Array_($this->getTypeByName($parent, $key, $obj, $this->phpDocTypeParser->getArrayElementTypeName($obj))),
            UploadedFile::class, SymfonyUploadedFile::class => new Types\File,
            default => match (true) {
                // Any DateTimeInterface implementor is a date. The bare interface
                // has no concrete target class; a concrete class carries its own.
                is_a($name, \DateTimeInterface::class, true) => new Types\Date(interface_exists($name) ? null : $name),
                enum_exists($name) => new Types\Enum($name),
                class_exists($name) => (new ClassDefinitionBuilder(new PropertySource($parent, $key)))->buildClassDefinition($name),
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
        // once per class per process. Only top-level builds are cached — a
        // nested definition bakes its parent's key path into every property
        // source, so it is only valid inside that parent tree (and is cached as
        // part of it). Class_ definitions are immutable, so reuse is safe.
        // (A method-static, not a static property, because a readonly class may
        // not declare one.)
        /** @var array<class-string, Class_> $cache */
        static $cache = [];

        if ($this->parentSource === null && isset($cache[$className])) {
            return $cache[$className];
        }

        $definition = new Class_(
            class: $className,
            properties: [
                ...$this->getConstructorParameterAndClassProperties($class),
                ...$this->getValidationOnlyProperties($class),
            ],
        );

        if ($this->parentSource === null) {
            $cache[$className] = $definition;
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
