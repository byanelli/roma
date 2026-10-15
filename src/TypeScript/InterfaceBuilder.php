<?php

namespace BYanelli\Roma\TypeScript;

use BYanelli\Roma\Attributes\Description;
use BYanelli\Roma\Data\Property as PhpProperty;
use BYanelli\Roma\Data\Role as PhpRole;
use BYanelli\Roma\Data\Type as PhpType;
use BYanelli\Roma\Data\Types\Array_ as PhpArray;
use BYanelli\Roma\Data\Types\Boolean as PhpBoolean;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Types\Date as PhpDate;
use BYanelli\Roma\Data\Types\Enum as PhpEnum;
use BYanelli\Roma\Data\Types\File as PhpFile;
use BYanelli\Roma\Data\Types\Float_ as PhpFloat;
use BYanelli\Roma\Data\Types\Integer as PhpInteger;
use BYanelli\Roma\Data\Types\Mixed_ as PhpMixed;
use BYanelli\Roma\Data\Types\Shape as PhpShape;
use BYanelli\Roma\Data\Types\ShapeItem as PhpShapeItem;
use BYanelli\Roma\Data\Types\String_ as PhpString;
use BYanelli\Roma\Data\Types\Union as PhpUnion;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptBody;
use BYanelli\Roma\TypeScript\Attributes\InputMapsToTypeScriptQuery;
use BYanelli\Roma\TypeScript\Attributes\TypeScriptName;
use BYanelli\Roma\TypeScript\Types\Array_;
use BYanelli\Roma\TypeScript\Types\Boolean;
use BYanelli\Roma\TypeScript\Types\Date;
use BYanelli\Roma\TypeScript\Types\Enum;
use BYanelli\Roma\TypeScript\Types\File;
use BYanelli\Roma\TypeScript\Types\Interface_;
use BYanelli\Roma\TypeScript\Types\Mixed_;
use BYanelli\Roma\TypeScript\Types\Number;
use BYanelli\Roma\TypeScript\Types\Object_;
use BYanelli\Roma\TypeScript\Types\Record_;
use BYanelli\Roma\TypeScript\Types\String_;
use BYanelli\Roma\TypeScript\Types\Union;
use Closure;
use RuntimeException;

/**
 * Builds one TypeScript interface from a PHP class definition. This is pure
 * mechanics: it knows nothing about requests vs responses. The caller supplies
 * the two policies that differ between them — how a property decides it is
 * optional, and (at the top level) which properties are emitted at all.
 */
readonly class InterfaceBuilder
{
    /**
     * Properties are keyed by their wire keys and always keep their declared
     * nullability. `$isPropertyOptional` decides the `?` (it threads into nested
     * objects); `$includeProperty` restricts which top-level properties are
     * emitted — request source bucketing, or dropping response properties lifted
     * out of the body. `$stringValued` forces all fields to `string` regardless
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
        return $this->interface($class, $isPropertyOptional, $includeProperty, $name, $stringValued, []);
    }

    /**
     * @param  Closure(PhpProperty): bool  $isPropertyOptional
     * @param  ?Closure(PhpProperty): bool  $includeProperty
     * @param  array<class-string, true>  $ancestors  the nested classes being built, which a class
     *                                                that contains itself refers to by name
     */
    private function interface(
        Class_ $class,
        Closure $isPropertyOptional,
        ?Closure $includeProperty,
        ?string $name,
        bool $stringValued,
        array $ancestors,
    ): Interface_ {
        $properties = [];

        foreach ($class->properties as $property) {
            if ($ancestors !== []) {
                $this->assertBucketAttribute($class->class, $property, false);
            }

            if ($property->role === PhpRole::ValidationOnly || $property->type instanceof PhpFile) {
                continue;
            }

            if ($includeProperty !== null && ! $includeProperty($property)) {
                continue;
            }

            $properties[] = new Property(
                key: $property->wireKey,
                type: $stringValued ? new String_ : $this->buildType($property->type, $isPropertyOptional, $ancestors),
                optional: $isPropertyOptional($property),
                nullable: $property->nullable,
                description: $property->attribute(Description::class)?->text,
            );
        }

        return new Interface_(
            name: $name ?: TypeScriptName::for($class->class),
            properties: $properties,
            phpFqcn: $class->class,
            description: Description::ofClass($class->class),
        );
    }

    /**
     * #[InputMapsToTypeScriptBody] and #[InputMapsToTypeScriptQuery] apply only to an #[Input]
     * property of a top-level request class.
     *
     * @param  class-string  $class
     */
    public function assertBucketAttribute(string $class, PhpProperty $property, bool $isRequestInput): void
    {
        $attributes = array_values(array_filter(
            $property->rawAttributes,
            fn (object $attribute) => $attribute instanceof InputMapsToTypeScriptBody || $attribute instanceof InputMapsToTypeScriptQuery,
        ));

        if ($attributes === [] || ($isRequestInput && count($attributes) === 1)) {
            return;
        }

        $printableName = class_basename($class).'::$'.$property->name;

        throw new RuntimeException(count($attributes) > 1
            ? "Roma property \"{$printableName}\" has both #[InputMapsToTypeScriptBody] and #[InputMapsToTypeScriptQuery]. Remove one of them."
            : "Roma property \"{$printableName}\" has #[".class_basename($attributes[0]).'], which applies only to an #[Input] property of a top-level request class. Remove it.'
        );
    }

    /**
     * @param  Closure(PhpProperty): bool  $optional
     * @param  array<class-string, true>  $ancestors
     */
    private function buildType(PhpType $type, Closure $optional, array $ancestors): Type
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
            $type instanceof Class_ => $this->nested($type, $optional, $ancestors),
            $type instanceof PhpString => new String_,
            $type instanceof PhpInteger, $type instanceof PhpFloat => new Number,
            $type instanceof PhpBoolean => new Boolean,
            $type instanceof PhpDate => new Date,
            $type instanceof PhpEnum => new Enum($type->class),
            $type instanceof PhpArray && $type->keyType !== null => new Record_(
                $type->keyType instanceof PhpInteger ? new Number : new String_,
                $this->buildType($type->memberType, $optional, $ancestors),
            ),
            $type instanceof PhpArray => new Array_($this->buildType($type->memberType, $optional, $ancestors)),
            $type instanceof PhpShape => new Object_(array_map(
                fn (PhpShapeItem $item) => new Property(
                    key: $item->key,
                    type: $this->buildType($item->type, $optional, $ancestors),
                    optional: $item->optional,
                    nullable: $item->nullable,
                ),
                $type->items,
            )),
            $type instanceof PhpUnion => new Union(
                $type->key,
                array_map(fn (Class_ $member) => $this->nested($member, $optional, $ancestors), $type->members),
                $type->contract,
            ),
            $type instanceof PhpFile => new File,
            $type instanceof PhpMixed => new Mixed_,
            default => new Mixed_,
        };
    }

    /**
     * A class that is already being built becomes a reference to its interface by name.
     *
     * @param  Closure(PhpProperty): bool  $optional
     * @param  array<class-string, true>  $ancestors
     */
    private function nested(Class_ $class, Closure $optional, array $ancestors): Interface_
    {
        if (isset($ancestors[$class->class])) {
            return new Interface_(TypeScriptName::for($class->class), [], $class->class);
        }

        return $this->interface($class, $optional, null, null, false, [...$ancestors, $class->class => true]);
    }
}
