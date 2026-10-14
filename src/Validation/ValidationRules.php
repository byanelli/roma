<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Role;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Values\HasValidationRules;
use BYanelli\Roma\Data\Values\ParsesStringValue;
use BYanelli\Roma\Validation\Rules\DiscriminatorIn;
use BYanelli\Roma\Validation\Rules\IntegerKeys;
use BYanelli\Roma\Validation\Rules\RejectBlankString;
use BYanelli\Roma\Validation\Rules\RequiredWithinObject;
use Illuminate\Validation\Rule;

/**
 * @phpstan-type Entry array{keySegments: list<string>, rules: list<mixed>, name: string}
 */
readonly class ValidationRules
{
    /**
     * The private top-level key that validation-only values are copied under.
     */
    public const string VALIDATION_ONLY_KEY = '__validation_only';

    /**
     * @var array<string, mixed>
     */
    private array $rules;

    /**
     * Client-facing :attribute names, keyed by the rule key exactly as Laravel
     * emits it (literal dots unescaped). Used both as custom attribute names
     * and to re-key the error bag.
     *
     * @var array<string, string>
     */
    private array $attributeNames;

    /**
     * Whether the rules for a class's members depend on the data, keyed by class.
     *
     * @var array<class-string, bool>
     */
    private array $dataDependent;

    /**
     * `$data` is the coerced data being validated. The rules for a union depend on its
     * discriminator, and the rules for a class that contains itself are only as deep as the
     * data, so an array that contains either gets rules per element.
     *
     * @param  array<array-key, mixed>  $data
     */
    public function __construct(Class_ $class, private array $data)
    {
        $dataDependent = [];
        $this->findDataDependent($class, [], $dataDependent);
        $this->dataDependent = $dataDependent;

        $rules = [];
        $names = [];

        foreach ($this->entriesFromProperties($class->properties) as $entry) {
            // The rule key given to the validator escapes literal dots within
            // each key segment as "\." so Laravel matches the real (possibly dotted)
            // array key instead of walking structural nesting.
            $rules[$this->escapedKey($entry['keySegments'])] = $entry['rules'];

            // The attribute-name key is the unescaped join, matching what
            // Laravel puts in the error bag and looks up for :attribute. A
            // wildcard key gets no name, so messages show the concrete key.
            if (! in_array('*', $entry['keySegments'], true)) {
                $names[$this->plainKey($entry['keySegments'])] = $entry['name'];
            }
        }

        $this->rules = $rules;
        $this->attributeNames = $names;
    }

    /**
     * A value object that parses from a string and emits its own validation rule
     * is validated as a leaf string (its own rule vetting the raw value) rather
     * than descended into as a structured object.
     *
     * @param  class-string  $class
     */
    private function validatesAsString(string $class): bool
    {
        return is_a($class, ParsesStringValue::class, true)
            && is_a($class, HasValidationRules::class, true);
    }

    /**
     * The rules a leaf string value object contributes at its own key (empty
     * when it emits none). The is_a check narrows the class-string so the static
     * call resolves to HasValidationRules::validationRules().
     *
     * @param  class-string  $class
     * @return list<mixed>
     */
    private function stringValueRules(string $class): array
    {
        return is_a($class, HasValidationRules::class, true)
            ? $class::validationRules()
            : [];
    }

    /**
     * @return list<mixed>
     */
    private function getTypeValidationRules(Type $type): array
    {
        return match (true) {
            $type instanceof Types\Boolean => ['boolean'],
            $type instanceof Types\Integer => ['integer'],
            $type instanceof Types\Float_ => ['numeric'],
            $type instanceof Types\Date => [$type->format !== null ? "date_format:{$type->format}" : 'date'],
            $type instanceof Types\String_ => ['string'],
            $type instanceof Types\Array_ => match (true) {
                $type->isList => ['array', 'list'],
                $type->keyType instanceof Types\Integer => ['array', new IntegerKeys],
                default => ['array'],
            },
            $type instanceof Class_, $type instanceof Types\Shape, $type instanceof Types\Union => ['array'],
            $type instanceof Types\Enum => [Rule::enum($type->class)],
            default => [],
        };
    }

    /**
     * The presence rules that lead every value's rule list: "nullable"
     * (allow an explicit null) and the requiredness rule. A required member of
     * a nested object or shape becomes a RequiredWithinObject rule keyed on
     * that object, so it is only required when the object is actually present —
     * an absent or null object doesn't trip its members.
     *
     * @param  list<string>|null  $objectKeySegments
     * @return list<mixed>
     */
    private function leadingRules(bool $nullable, bool $required, ?array $objectKeySegments): array
    {
        $rules = [];

        if ($nullable) {
            $rules[] = 'nullable';
        }

        if ($required) {
            $rules[] = $objectKeySegments !== null
                ? new RequiredWithinObject($objectKeySegments)
                : 'required';
        }

        return $rules;
    }

    /**
     * @return list<Entry>
     */
    private function entryFromProperty(Property $property): array
    {
        $keySegments = $property->getKeySegments();

        // Validation-only values are copied under a private key so they don't
        // collide with mapped keys.
        if ($property->role == Role::ValidationOnly) {
            $keySegments = [self::VALIDATION_ONLY_KEY, ...$keySegments];
        }

        return $this->valueEntries(
            $property->type,
            $keySegments,
            $property->errorKey,
            [...$this->leadingRules($property->nullable, $property->isRequired, null), ...$property->rules],
        );
    }

    /**
     * The entries for a value of `$type` at `$keySegments`, followed by those
     * of its members. A '*' key segment stands for every member of an
     * enclosing array.
     *
     * @param  list<string>  $keySegments
     * @param  list<mixed>  $rules  the presence and declared rules, which precede the type's rules
     * @return list<Entry>
     */
    private function valueEntries(Type $type, array $keySegments, string $name, array $rules): array
    {
        // A value object that parses from a raw string and vets it with its own
        // rule is validated as a leaf string at its own key — the input is one
        // string (e.g. a header value), not a structured sub-object, so the
        // object's fields are not recursed into.
        if ($type instanceof Class_ && $this->validatesAsString($type->class)) {
            return [[
                'keySegments' => $keySegments,
                'rules' => [...$rules, 'string', ...$this->stringValueRules($type->class)],
                'name' => $name,
            ]];
        }

        $typeRules = $this->getTypeValidationRules($type);

        if ($type instanceof Types\Boolean && ! $this->hasPresenceRule($rules)) {
            $typeRules[] = new RejectBlankString('validation.boolean');
        }

        $entry = [
            'keySegments' => $keySegments,
            'rules' => [...$rules, ...$typeRules],
            'name' => $name,
        ];

        return match (true) {
            $type instanceof Class_ && $this->dependsOnData($type) && ! is_array($this->valueAt($keySegments)) => [$entry],
            $type instanceof Class_ => [$entry, ...$this->objectMemberEntries($type, $keySegments, $name)],
            $type instanceof Types\Shape => [$entry, ...$this->shapeItemEntries($type, $keySegments, $name)],
            $type instanceof Types\Union => [$entry, ...$this->unionEntries($type, $keySegments, $name)],
            $type instanceof Types\Array_ && $this->dependsOnData($type->memberType) => [$entry, ...$this->elementEntries($type, $keySegments, $name)],
            $type instanceof Types\Array_ => [$entry, ...$this->valueEntries($type->memberType, [...$keySegments, '*'], "$name.*", [])],
            default => [$entry],
        };
    }

    /**
     * The discriminator entry, followed by the entries of the member it selects.
     *
     * @param  list<string>  $keySegments
     * @return list<Entry>
     */
    private function unionEntries(Types\Union $union, array $keySegments, string $name): array
    {
        $object = $this->valueAt($keySegments);
        $member = is_array($object) ? $union->memberFor($object[$union->key] ?? null) : null;

        return [
            [
                'keySegments' => [...$keySegments, $union->key],
                'rules' => [new DiscriminatorIn($keySegments, $union->values())],
                'name' => "$name.$union->key",
            ],
            ...($member !== null ? $this->objectMemberEntries($member, $keySegments, $name) : []),
        ];
    }

    /**
     * Entries for each element present in the data, under its own key.
     *
     * @param  list<string>  $keySegments
     * @return list<Entry>
     */
    private function elementEntries(Types\Array_ $array, array $keySegments, string $name): array
    {
        $elements = $this->valueAt($keySegments);

        if (! is_array($elements)) {
            return [];
        }

        $entries = [];

        foreach (array_keys($elements) as $key) {
            $entries = [...$entries, ...$this->valueEntries($array->memberType, [...$keySegments, (string) $key], "$name.$key", [])];
        }

        return $entries;
    }

    /**
     * Whether `$type` contains a union or a class that contains itself.
     */
    private function dependsOnData(Type $type): bool
    {
        return match (true) {
            $type instanceof Types\Union => true,
            $type instanceof Types\Array_ => $this->dependsOnData($type->memberType),
            $type instanceof Types\Shape => collect($type->items)->contains(fn (Types\ShapeItem $item) => $this->dependsOnData($item->type)),
            $type instanceof Class_ => $this->dataDependent[$type->class] ?? false,
            default => false,
        };
    }

    /**
     * Fills `$found` for every class `$type` reaches. A class reached again while its own
     * members are being visited contains itself.
     *
     * @param  array<class-string, true>  $visiting
     * @param  array<class-string, bool>  $found
     */
    private function findDataDependent(Type $type, array $visiting, array &$found): bool
    {
        if ($type instanceof Class_) {
            if (isset($visiting[$type->class])) {
                return true;
            }

            if (! isset($found[$type->class])) {
                $visiting[$type->class] = true;
                $found[$type->class] = $this->anyDataDependent(array_map(fn (Property $property) => $property->type, $type->properties), $visiting, $found);
            }

            return $found[$type->class];
        }

        if ($type instanceof Types\Union) {
            $this->anyDataDependent(array_values($type->members), $visiting, $found);

            return true;
        }

        return match (true) {
            $type instanceof Types\Array_ => $this->findDataDependent($type->memberType, $visiting, $found),
            $type instanceof Types\Shape => $this->anyDataDependent(array_map(fn (Types\ShapeItem $item) => $item->type, $type->items), $visiting, $found),
            default => false,
        };
    }

    /**
     * Visits every type, so that `$found` covers all the classes they reach.
     *
     * @param  list<Type>  $types
     * @param  array<class-string, true>  $visiting
     * @param  array<class-string, bool>  $found
     */
    private function anyDataDependent(array $types, array $visiting, array &$found): bool
    {
        $any = false;

        foreach ($types as $type) {
            $any = $this->findDataDependent($type, $visiting, $found) || $any;
        }

        return $any;
    }

    /**
     * @param  list<string>  $keySegments
     */
    private function valueAt(array $keySegments): mixed
    {
        $value = $this->data;

        foreach ($keySegments as $keySegment) {
            if (! is_array($value) || ! array_key_exists($keySegment, $value)) {
                return null;
            }

            $value = $value[$keySegment];
        }

        return $value;
    }

    /**
     * @param  list<mixed>  $rules
     */
    private function hasPresenceRule(array $rules): bool
    {
        foreach ($rules as $rule) {
            if ($rule === 'required' || $rule instanceof RequiredWithinObject) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keySegments
     * @return list<Entry>
     */
    private function objectMemberEntries(Class_ $class, array $keySegments, string $name): array
    {
        $entries = [];

        foreach ($class->properties as $member) {
            // A virtual (computed) property is not an input, so it gets no
            // rules — otherwise it would validate as required.
            if ($member->isVirtual) {
                continue;
            }

            $key = $member->source->getOwnKey();

            $entries = [...$entries, ...$this->valueEntries(
                $member->type,
                [...$keySegments, $key],
                "$name.$key",
                [...$this->leadingRules($member->nullable, $member->isRequired, $keySegments), ...$member->rules],
            )];
        }

        return $entries;
    }

    /**
     * @param  list<string>  $keySegments
     * @return list<Entry>
     */
    private function shapeItemEntries(Types\Shape $shape, array $keySegments, string $name): array
    {
        $entries = [];

        foreach ($shape->items as $item) {
            $entries = [...$entries, ...$this->valueEntries(
                $item->type,
                [...$keySegments, $item->key],
                "$name.$item->key",
                $this->leadingRules($item->nullable, ! $item->optional, $keySegments),
            )];
        }

        return $entries;
    }

    /**
     * @param  list<Property>  $properties
     * @return list<Entry>
     */
    private function entriesFromProperties(array $properties): array
    {
        return array_values(
            collect($properties)
                // A virtual (computed) property is not an input, so it gets no
                // rules — otherwise it would validate as required.
                ->reject(fn (Property $property) => $property->isVirtual)
                ->flatMap(fn (Property $property) => $this->entryFromProperty($property))
                ->all()
        );
    }

    /**
     * Join keySegments into a rule key, escaping any literal dot within a segment
     * as "\." so Laravel treats it as one key rather than structural nesting.
     * The synthetic "*" wildcard key segment contains no dot and passes through.
     *
     * @param  list<string>  $keySegments
     */
    private function escapedKey(array $keySegments): string
    {
        return implode('.', array_map(
            fn (string $keySegment) => str_replace('.', '\\.', $keySegment),
            $keySegments
        ));
    }

    /**
     * Join keySegments into the unescaped key Laravel emits in the error bag and
     * matches for :attribute lookups.
     *
     * @param  list<string>  $keySegments
     */
    private function plainKey(array $keySegments): string
    {
        return implode('.', $keySegments);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributeNames(): array
    {
        return $this->attributeNames;
    }
}
