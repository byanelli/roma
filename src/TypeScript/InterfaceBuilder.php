<?php

namespace BYanelli\Roma\TypeScript;

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
                type: $stringValued ? new String_ : $this->buildType($property->type, $isPropertyOptional),
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
     * @param  Closure(PhpProperty): bool  $optional
     */
    private function buildType(PhpType $type, Closure $optional): Type
    {
        return match (true) {
            // A nested object emits all of its properties (no top-level filter)
            // but inherits the same optionality policy as its parent.
            $type instanceof Class_ => $this->buildInterface($type, $optional),
            $type instanceof PhpString => new String_,
            $type instanceof PhpInteger, $type instanceof PhpFloat => new Number,
            $type instanceof PhpBoolean => new Boolean,
            $type instanceof PhpDate => new Date,
            $type instanceof PhpEnum => new Enum($type->class),
            $type instanceof PhpArray => new Array_($this->buildType($type->memberType, $optional)),
            $type instanceof PhpFile => new File,
            $type instanceof PhpMixed => new Mixed_,
            default => new Mixed_,
        };
    }
}
