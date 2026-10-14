<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Role;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Values\HasValidationRules;
use BYanelli\Roma\Data\Values\ParsesStringValue;
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

    public function __construct(Class_ $class)
    {
        $rules = [];
        $names = [];

        foreach ($this->entriesFromProperties($class->properties) as $entry) {
            // The rule key given to the validator escapes literal dots within
            // each key segment as "\." so Laravel matches the real (possibly dotted)
            // array key instead of walking structural nesting.
            $rules[$this->escapedKey($entry['keySegments'])] = $entry['rules'];

            // The attribute-name key is the unescaped join, matching what
            // Laravel puts in the error bag and looks up for :attribute.
            $names[$this->plainKey($entry['keySegments'])] = $entry['name'];
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
            $type instanceof Types\Date => ['date'],
            $type instanceof Types\String_ => ['string'],
            $type instanceof Types\Array_ => match (true) {
                $type->isList => ['array', 'list'],
                $type->keyType instanceof Types\Integer => ['array', new IntegerKeys],
                default => ['array'],
            },
            $type instanceof Class_, $type instanceof Types\Shape => ['array'],
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
            $type instanceof Class_ => [$entry, ...$this->objectMemberEntries($type, $keySegments, $name)],
            $type instanceof Types\Shape => [$entry, ...$this->shapeItemEntries($type, $keySegments, $name)],
            $type instanceof Types\Array_ => [$entry, ...$this->valueEntries($type->memberType, [...$keySegments, '*'], "$name.*", [])],
            default => [$entry],
        };
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
