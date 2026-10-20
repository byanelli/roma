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
use BYanelli\Roma\Data\Types\Interval as PhpInterval;
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
 * the policies that differ between them: how a property decides it is
 * optional, which properties are omitted at all levels, and (at the top level)
 * which properties are emitted at all.
 */
readonly class InterfaceBuilder
{
    /**
     * Properties are keyed by their wire paths, so dotted keys become inline objects,
     * and always keep their declared nullability. `$isPropertyOptional` decides the `?`
     * (it threads into nested objects); `$includeProperty` restricts which top-level properties are
     * emitted — request source bucketing, or dropping response properties lifted
     * out of the body. `$stringValued` forces all fields to `string` regardless
     * of its PHP type, for HTTP header interfaces: header values are strings on
     * the wire, and the caller sends / receives them as strings with no coercion
     * layer of its own. `$omitProperty` removes properties in nested objects as
     * well. Validation-only pseudo-properties and file uploads are never emitted.
     * `$responseVariants` lists the nested classes and union contracts whose
     * types are marked as response variants.
     *
     * @param  Closure(PhpProperty): bool  $isPropertyOptional
     * @param  ?Closure(PhpProperty): bool  $includeProperty
     * @param  ?Closure(PhpProperty): bool  $omitProperty
     * @param  array<class-string, true>  $responseVariants
     */
    public function buildInterface(
        Class_ $class,
        Closure $isPropertyOptional,
        ?Closure $includeProperty = null,
        ?string $name = null,
        bool $stringValued = false,
        ?Closure $omitProperty = null,
        array $responseVariants = [],
    ): Interface_ {
        $omitProperty ??= fn (PhpProperty $property): bool => false;

        return $this->interface($class, $isPropertyOptional, $omitProperty, $responseVariants, $includeProperty, $name, $stringValued, []);
    }

    /**
     * @param  Closure(PhpProperty): bool  $isPropertyOptional
     * @param  Closure(PhpProperty): bool  $omitProperty
     * @param  array<class-string, true>  $variants
     * @param  ?Closure(PhpProperty): bool  $includeProperty
     * @param  array<class-string, true>  $ancestors  the nested classes being built, which a class
     *                                                that contains itself refers to by name
     */
    private function interface(
        Class_ $class,
        Closure $isPropertyOptional,
        Closure $omitProperty,
        array $variants,
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

            if ($property->role === PhpRole::ValidationOnly || $property->source?->isInjected() || $property->type instanceof PhpFile || $omitProperty($property)) {
                continue;
            }

            if ($includeProperty !== null && ! $includeProperty($property)) {
                continue;
            }

            $segments = $property->wirePath->segments;

            $properties[] = [$segments, new Property(
                key: $segments[count($segments) - 1] ?? $property->name,
                type: $stringValued ? new String_ : $this->buildType($property->type, $isPropertyOptional, $omitProperty, $variants, $ancestors),
                optional: $isPropertyOptional($property),
                nullable: $property->nullable,
                description: $property->attribute(Description::class)?->text,
            )];
        }

        return new Interface_(
            name: $name ?: TypeScriptName::for($class->class),
            properties: self::nest($properties),
            phpFqcn: $class->class,
            description: Description::ofClass($class->class),
            isResponseVariant: $name === null && isset($variants[$class->class]),
        );
    }

    /**
     * Properties whose wire paths start with the same key, such as "error.message" and
     * "error.code", become one inline object under that key, in the position of the first. The
     * object is optional when all of its members are.
     *
     * @param  list<array{list<string>, Property}>  $entries  the wire path of each property, and the property under its last key
     * @return list<Property>
     */
    private static function nest(array $entries): array
    {
        $groups = [];

        foreach ($entries as $index => $entry) {
            $groups[count($entry[0]) > 1 ? "nested:{$entry[0][0]}" : "leaf:{$index}"][] = $entry;
        }

        $properties = [];

        foreach ($groups as $group) {
            [$segments, $property] = $group[0];

            if (count($segments) <= 1) {
                $properties[] = $property;

                continue;
            }

            $members = self::nest(array_map(fn (array $entry) => [array_slice($entry[0], 1), $entry[1]], $group));

            $properties[] = new Property(
                key: $segments[0],
                type: new Object_($members),
                optional: array_all($members, fn (Property $member) => $member->optional),
                nullable: false,
            );
        }

        return $properties;
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
     * @param  Closure(PhpProperty): bool  $omit
     * @param  array<class-string, true>  $variants
     * @param  array<class-string, true>  $ancestors
     */
    private function buildType(PhpType $type, Closure $optional, Closure $omit, array $variants, array $ancestors): Type
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
            $type instanceof Class_ => $this->nested($type, $optional, $omit, $variants, $ancestors),
            $type instanceof PhpString => new String_,
            $type instanceof PhpInteger, $type instanceof PhpFloat => new Number,
            $type instanceof PhpBoolean => new Boolean,
            $type instanceof PhpDate => new Date,
            $type instanceof PhpInterval => new String_,
            $type instanceof PhpEnum => new Enum($type->class),
            $type instanceof PhpArray && $type->keyType !== null => new Record_(
                $type->keyType instanceof PhpInteger ? new Number : new String_,
                $this->buildType($type->memberType, $optional, $omit, $variants, $ancestors),
            ),
            $type instanceof PhpArray => new Array_($this->buildType($type->memberType, $optional, $omit, $variants, $ancestors)),
            $type instanceof PhpShape => new Object_(array_map(
                fn (PhpShapeItem $item) => new Property(
                    key: $item->key,
                    type: $this->buildType($item->type, $optional, $omit, $variants, $ancestors),
                    optional: $item->optional,
                    nullable: $item->nullable,
                ),
                $type->items,
            )),
            $type instanceof PhpUnion => new Union(
                $type->key,
                array_map(fn (Class_ $member) => $this->nested($member, $optional, $omit, $variants, $ancestors), $type->members),
                $type->contract,
                $type->contract !== null && isset($variants[$type->contract]),
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
     * @param  Closure(PhpProperty): bool  $omit
     * @param  array<class-string, true>  $variants
     * @param  array<class-string, true>  $ancestors
     */
    private function nested(Class_ $class, Closure $optional, Closure $omit, array $variants, array $ancestors): Interface_
    {
        if (isset($ancestors[$class->class])) {
            return new Interface_(TypeScriptName::for($class->class), [], $class->class, isResponseVariant: isset($variants[$class->class]));
        }

        return $this->interface($class, $optional, $omit, $variants, null, null, false, [...$ancestors, $class->class => true]);
    }
}
