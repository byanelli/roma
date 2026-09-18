<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Request\Data\ClassDefinitionBuilder as PhpClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\Property as PhpProperty;
use BYanelli\Roma\Request\Data\Role as PhpRole;
use BYanelli\Roma\Request\Data\Type as PhpType;
use BYanelli\Roma\Request\Data\Types\Array_ as PhpArray;
use BYanelli\Roma\Request\Data\Types\Boolean as PhpBoolean;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Data\Types\Date as PhpDate;
use BYanelli\Roma\Request\Data\Types\Enum as PhpEnum;
use BYanelli\Roma\Request\Data\Types\File as PhpFile;
use BYanelli\Roma\Request\Data\Types\Float_ as PhpFloat;
use BYanelli\Roma\Request\Data\Types\Integer as PhpInteger;
use BYanelli\Roma\Request\Data\Types\Mixed_ as PhpMixed;
use BYanelli\Roma\Request\Data\Types\Polymorphic as PhpPolymorphic;
use BYanelli\Roma\Request\Data\Types\String_ as PhpString;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\Types\Array_;
use BYanelli\Roma\TypeScript\Types\Boolean;
use BYanelli\Roma\TypeScript\Types\Date;
use BYanelli\Roma\TypeScript\Types\Enum;
use BYanelli\Roma\TypeScript\Types\File;
use BYanelli\Roma\TypeScript\Types\Interface_;
use BYanelli\Roma\TypeScript\Types\Mixed_;
use BYanelli\Roma\TypeScript\Types\Number;
use BYanelli\Roma\TypeScript\Types\String_;
use BYanelli\Roma\TypeScript\Types\Union;
use Closure;

/**
 * Builds one TypeScript interface from a PHP class definition. This is pure
 * mechanics: it knows nothing about requests vs responses. The caller supplies
 * the two policies that differ between them — how a property decides it is
 * optional, and (at the top level) which properties are emitted at all.
 */
readonly class InterfaceBuilder
{
    /**
     * The concrete classes that satisfy a contract, for properties typed as an
     * interface or abstract class. Supplied as a callback so this class stays
     * unaware of where implementations are found; the generator hands it the
     * lookup over the discovered classes. The default finds none, which is the
     * honest answer when nobody scanned anything.
     *
     * @var Closure(class-string): list<class-string>
     */
    private Closure $implementationsOf;

    /**
     * @param  ?Closure(class-string): list<class-string>  $implementationsOf
     */
    public function __construct(?Closure $implementationsOf = null)
    {
        $this->implementationsOf = $implementationsOf ?? fn () => [];
    }

    /**
     * Every property is keyed by its wire key and always carries its declared
     * nullability. `$isPropertyOptional` decides the `?` (it threads into nested
     * objects); `$includeProperty` restricts which top-level properties are
     * emitted — request source bucketing, or dropping response properties lifted
     * out of the body. `$stringValued` forces every field to `string` regardless
     * of its PHP type, for HTTP header interfaces: header values are strings on
     * the wire, and the caller sends / receives them as strings with no coercion
     * layer of its own. Validation-only pseudo-properties and file uploads are
     * never emitted.
     *
     * @param  Closure(PhpProperty): bool  $isPropertyOptional
     * @param  ?Closure(PhpProperty): bool  $includeProperty
     */
    public function buildInterface(
        Class_ $class,
        Closure $isPropertyOptional,
        ?Closure $includeProperty = null,
        ?string $name = null,
        bool $stringValued = false,
    ): Interface_ {
        $properties = [];

        foreach ($class->properties as $property) {
            if ($property->role === PhpRole::ValidationOnly || $property->type instanceof PhpFile) {
                continue;
            }

            if ($includeProperty !== null && ! $includeProperty($property)) {
                continue;
            }

            $properties[] = new Property(
                key: $property->wireKey,
                type: $stringValued ? new String_ : $this->buildType($property->type, $isPropertyOptional, $property),
                optional: $isPropertyOptional($property),
                nullable: $property->nullable,
            );
        }

        return new Interface_(
            name: $name ?: TypeScriptName::for($class->class),
            properties: $properties,
            phpFqcn: $class->class,
        );
    }

    /**
     * A property typed as a contract (an interface or abstract class) is the
     * union of every concrete implementation discovery found, each of which is
     * emitted as its own interface. Nothing is declared in PHP: adding an
     * implementation under a scanned directory widens the union on the next
     * run. With none found there is nothing to union — emit the contract itself
     * as an empty named interface, so the property still has a name to point at.
     *
     * @param  Closure(PhpProperty): bool  $optional
     */
    private function buildPolymorphicType(PhpPolymorphic $type, Closure $optional, ?PhpProperty $property): Type
    {
        $implementations = ($this->implementationsOf)($type->class);

        if ($implementations === []) {
            return new Interface_(
                name: TypeScriptName::for($type->class),
                properties: [],
                phpFqcn: $type->class,
            );
        }

        // Each implementation is defined under the same source as the property
        // holding it, exactly as a nested concrete class would be.
        $definitions = new PhpClassDefinitionBuilder($property?->source);

        // TODO: emit a discriminator. A polymorphic value is serialised without
        // one, so TypeScript narrows this union by shape. If two implementations
        // ever share a shape, that narrowing fails. Roma's serializer could add
        // a discriminator itself when writing a value held in an interface-typed
        // property — say the implementation's short class name under a fixed key
        // — and the generator would emit it here as a literal type, giving a
        // discriminated union without touching the PHP contract. Deliberately
        // not done yet.
        $members = [];

        foreach ($implementations as $implementation) {
            $members[] = $this->buildInterface($definitions->buildClassDefinition($implementation), $optional);
        }

        return new Union($members);
    }

    /**
     * `$property` is the property the type came from, where there is one: a
     * polymorphic type needs it to define its implementations under the same
     * source. Nested object types re-derive it from their own properties.
     *
     * @param  Closure(PhpProperty): bool  $optional
     */
    private function buildType(PhpType $type, Closure $optional, ?PhpProperty $property = null): Type
    {
        return match (true) {
            // A value object that parses from a single string (e.g. an
            // Authorization header) is that string on the wire, wherever it is
            // sourced from — so it is emitted as `string`, never descended into
            // as an object. This holds regardless of bucket, so it does not rely
            // on header interfaces already being forced to string values.
            $type instanceof Class_ && $type->parsesStringValue() => new String_,
            // A nested object emits all of its properties (no top-level filter)
            // but inherits the same optionality policy as its parent.
            $type instanceof Class_ => $this->buildInterface($type, $optional),
            $type instanceof PhpString => new String_,
            $type instanceof PhpInteger, $type instanceof PhpFloat => new Number,
            $type instanceof PhpBoolean => new Boolean,
            $type instanceof PhpDate => new Date,
            $type instanceof PhpEnum => new Enum($type->class),
            $type instanceof PhpArray => new Array_($this->buildType($type->memberType, $optional, $property)),
            $type instanceof PhpPolymorphic => $this->buildPolymorphicType($type, $optional, $property),
            $type instanceof PhpFile => new File,
            $type instanceof PhpMixed => new Mixed_,
            default => new Mixed_,
        };
    }
}
