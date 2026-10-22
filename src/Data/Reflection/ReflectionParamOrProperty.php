<?php

namespace BYanelli\Roma\Data\Reflection;

use BYanelli\Roma\Attributes\SnakeCaseKeys;
use BYanelli\Roma\Data\Attributes\AttributesCollection;
use BYanelli\Roma\Data\NoDefault;
use BYanelli\Roma\Data\Role;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\SourcePolicy;
use BYanelli\Roma\Missing;
use DateTimeInterface;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use RuntimeException;
use SensitiveParameter;

/**
 * A constructor parameter or a property that Roma maps or serializes. A promoted property's
 * default and PHPDoc are the constructor parameter's.
 *
 * @internal
 */
final class ReflectionParamOrProperty
{
    /**
     * @param  ?SourcePolicy  $policy  provides the attributes and the source implied by the type
     * @param  ?list<class-string>  $instantiate  the attribute classes and interfaces to instantiate, or null for all
     */
    public function __construct(
        public readonly ReflectionParameter|ReflectionProperty $reflection,
        private readonly ?SourcePolicy $policy = null,
        private readonly ?array $instantiate = null,
    ) {}

    /**
     * The declared attributes, followed by the type's attributes from the policy unless one of
     * the declared attributes is a source attribute.
     */
    public private(set) AttributesCollection $attributes {
        get => $this->attributes ??= $this->instantiateAttributes();
    }

    /**
     * The attributes the policy adds for the type, which `attributes` may include.
     *
     * @var list<object>
     */
    public private(set) array $typeAttributes {
        get => $this->typeAttributes ??= ($this->policy !== null && $this->typeName !== null
            ? $this->policy->attributesForType($this->typeName)
            : []);
    }

    /**
     * The source that a property of this type always maps from under the policy, such as an
     * uploaded file's.
     */
    public ?Source $typeSource {
        get => $this->policy !== null && $this->typeName !== null ? $this->policy->sourceForType($this->typeName) : null;
    }

    public string $name {
        get => $this->reflection->getName();
    }

    /**
     * e.g. `Order::$id`
     */
    public string $printableName {
        get => ($this->declaringClass?->getShortName() ?? '').'::$'.$this->name;
    }

    /**
     * @var ?ReflectionClass<object>
     */
    public ?ReflectionClass $declaringClass {
        get => $this->reflection->getDeclaringClass();
    }

    public bool $isParameter {
        get => $this->reflection instanceof ReflectionParameter;
    }

    public Role $role {
        get => $this->isParameter ? Role::Constructor : Role::Property;
    }

    public bool $isPromoted {
        get => $this->reflection->isPromoted();
    }

    /**
     * A hooked property with no backing value.
     */
    public bool $isVirtual {
        get => $this->reflection instanceof ReflectionProperty && $this->reflection->isVirtual();
    }

    public ?ReflectionType $type {
        get => $this->reflection->getType();
    }

    /**
     * The declared type's name, ignoring null and Missing, or null when there isn't exactly one.
     */
    public ?string $typeName {
        get {
            $type = $this->type;

            if ($type instanceof ReflectionNamedType) {
                return $type->getName();
            }

            $names = $this->unionMemberNames;

            return count($names) === 1 ? $names[0] : null;
        }
    }

    /**
     * The names of a union type's members other than null and Missing. Empty when the type is
     * not a union or a member is an intersection type.
     *
     * @var list<string>
     */
    public array $unionMemberNames {
        get {
            $type = $this->type;

            if (! $type instanceof ReflectionUnionType) {
                return [];
            }

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
    }

    /**
     * Whether the type is a union with a date member, such as `DateTimeImmutable|string`.
     */
    public bool $isUnionWithDate {
        get {
            $type = $this->type;

            return $type instanceof ReflectionUnionType && array_any(
                $type->getTypes(),
                fn ($member) => $member instanceof ReflectionNamedType && is_a($member->getName(), DateTimeInterface::class, true),
            );
        }
    }

    /**
     * An untyped parameter or property allows null.
     */
    public bool $nullable {
        get => $this->type?->allowsNull() ?? true;
    }

    public bool $acceptsMissing {
        get => Missing::isInType($this->type);
    }

    /**
     * The key of a property without a key attribute: the snake_case of its name under
     * #[SnakeCaseKeys], else its name.
     */
    public string $defaultKey {
        get => $this->declaringClassHas(SnakeCaseKeys::class) ? Str::snake($this->name) : $this->name;
    }

    /**
     * Whether the class that declares the property, or one of its parents, has the attribute.
     *
     * @param  class-string  $attribute
     */
    public function declaringClassHas(string $attribute): bool
    {
        for ($class = $this->reflection->getDeclaringClass(); $class !== null && $class !== false; $class = $class->getParentClass()) {
            if ($class->getAttributes($attribute) !== []) {
                return true;
            }
        }

        return false;
    }

    public bool $hasDefault {
        get {
            $documented = $this->documented;

            return $documented instanceof ReflectionParameter ? $documented->isOptional() : $documented->hasDefaultValue();
        }
    }

    /**
     * The default value, or NoDefault when there is none.
     */
    public mixed $default {
        get => $this->hasDefault ? $this->documented->getDefaultValue() : new NoDefault;
    }

    /**
     * The parameter or property whose PHPDoc and default apply: a promoted property's
     * constructor parameter, else this one.
     */
    public ReflectionParameter|ReflectionProperty $documented {
        get {
            if ($this->reflection instanceof ReflectionParameter || ! $this->isPromoted) {
                return $this->reflection;
            }

            foreach ($this->declaringClass?->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $this->name) {
                    return $parameter;
                }
            }

            return $this->reflection;
        }
    }

    /**
     * Whether the parameter, or for a property the constructor parameter of the same name, has
     * #[\SensitiveParameter]. PHP doesn't copy the attribute to a promoted property.
     */
    public bool $isSensitive {
        get {
            if ($this->reflection instanceof ReflectionParameter) {
                return $this->reflection->getAttributes(SensitiveParameter::class) !== [];
            }

            foreach ($this->declaringClass?->getConstructor()?->getParameters() ?? [] as $parameter) {
                if ($parameter->getName() === $this->name) {
                    return $parameter->getAttributes(SensitiveParameter::class) !== [];
                }
            }

            return false;
        }
    }

    private function instantiateAttributes(): AttributesCollection
    {
        $declared = AttributesCollection::instantiate($this, $this->instantiate);

        return $declared->sourceAttribute() !== null ? $declared : $declared->merge($this->typeAttributes);
    }

    /**
     * The value of an absent optional parameter or property: its default, else Missing when its
     * type allows it. A parameter then takes null; a property is left unset, so it needs none.
     */
    public function optionalDefault(): mixed
    {
        if ($this->hasDefault) {
            return $this->default;
        }

        if ($this->acceptsMissing) {
            return Missing::instance();
        }

        if (! $this->isParameter || $this->nullable) {
            return new NoDefault;
        }

        throw new RuntimeException(
            "Roma parameter \"{$this->printableName}\" is #[Optional] but has no value for when it is absent. "
            .'Make it nullable (?string), add Missing to its type (string|Missing), or give it a default.'
        );
    }
}
