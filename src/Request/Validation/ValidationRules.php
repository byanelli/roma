<?php

namespace BYanelli\Roma\Request\Validation;

use BYanelli\Roma\Request\Data\Property;
use BYanelli\Roma\Request\Data\Role;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Validation\Rules\RequiredWithinObject;
use BYanelli\Roma\Request\Values\HasValidationRules;
use BYanelli\Roma\Request\Values\ParsesStringValue;
use Illuminate\Validation\Rule;

readonly class ValidationRules
{
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
            $type instanceof Types\Array_ => ['array'],
            $type instanceof Types\Enum => [Rule::enum($type->class)],
            default => [],
        };
    }

    /**
     * The presence rules that lead every property's rule list: "nullable"
     * (allow an explicit null) and the requiredness rule. A required member of
     * a nested object becomes a RequiredWithinObject rule keyed on that object,
     * so it is only required when the object is actually present — an absent or
     * null object doesn't trip its members.
     *
     * @param  list<string>|null  $objectKeySegments
     * @return list<mixed>
     */
    private function leadingRules(Property $property, ?array $objectKeySegments): array
    {
        $rules = [];

        if ($property->nullable) {
            $rules[] = 'nullable';
        }

        if ($property->isRequired) {
            $rules[] = $objectKeySegments !== null
                ? new RequiredWithinObject($objectKeySegments)
                : 'required';
        }

        return $rules;
    }

    /**
     * @param  list<string>|null  $objectKeySegments
     * @return list<array{keySegments: list<string>, rules: list<mixed>, name: string}>
     */
    private function entryFromProperty(Property $property, ?array $objectKeySegments = null): array
    {
        [$type, $rules, $name] = [$property->type, $property->rules, $property->errorKey];

        $keySegments = $property->getKeySegments();

        // Validation-only values are copied under a private "__request" bucket
        // so they don't collide with real request keys.
        if ($property->role == Role::ValidationOnly) {
            $keySegments = ['__request', ...$keySegments];
        }

        // A value object that parses from a raw string and vets it with its own
        // rule is validated as a leaf string at its own key — the client sends
        // one header value, not a structured sub-object, so the object's shape is
        // not exposed to the validator and its fields are not recursed into.
        if ($type instanceof Class_ && $this->validatesAsString($type->class)) {
            return [[
                'keySegments' => $keySegments,
                'rules' => array_merge(
                    $this->leadingRules($property, $objectKeySegments),
                    $rules,
                    ['string'],
                    $this->stringValueRules($type->class),
                ),
                'name' => $name,
            ]];
        }

        // Nested object: validate the object's presence/shape, then recurse
        // into its properties. Each child already carries its own absolute
        // keySegments (e.g. input -> address -> city), so its rules slot straight
        // in. Their required members are gated on this object's presence, so an
        // absent or null object doesn't require them.
        if ($type instanceof Class_) {
            $entries = [[
                'keySegments' => $keySegments,
                'rules' => array_merge($this->leadingRules($property, $objectKeySegments), $rules, ['array']),
                'name' => $name,
            ]];

            return array_merge($entries, $this->entriesFromProperties($type->properties, $property->getKeySegments()));
        }

        $entries = [[
            'keySegments' => $keySegments,
            'rules' => array_merge($this->leadingRules($property, $objectKeySegments), $rules, $this->getTypeValidationRules($type)),
            'name' => $name,
        ]];

        if ($type instanceof Types\Array_) {
            $entries = array_merge($entries, $this->arrayMemberEntries($property, $type));
        }

        return $entries;
    }

    /**
     * @return list<array{keySegments: list<string>, rules: list<mixed>, name: string}>
     */
    private function arrayMemberEntries(Property $property, Types\Array_ $type): array
    {
        $arrayKeySegments = $property->getKeySegments();
        $arrayName = $property->errorKey;
        $memberType = $type->memberType;

        // Array of scalars/enums: a single "key.*" rule for every element.
        if (! $memberType instanceof Class_) {
            return [[
                'keySegments' => [...$arrayKeySegments, '*'],
                'rules' => $this->getTypeValidationRules($memberType),
                'name' => $arrayName.'.*',
            ]];
        }

        // Array of nested objects: re-key each object property under "key.*" by
        // splicing a "*" segment in after the array's own keySegments. Members keep
        // plain required rules; Laravel's "*" only applies them to present
        // elements.
        $result = [];

        foreach ($this->entriesFromProperties($memberType->properties) as $entry) {
            $relative = array_slice($entry['keySegments'], count($arrayKeySegments));

            $result[] = [
                'keySegments' => [...$arrayKeySegments, '*', ...$relative],
                'rules' => $entry['rules'],
                'name' => $arrayName.'.*.'.implode('.', $relative),
            ];
        }

        return $result;
    }

    /**
     * @param  list<Property>  $properties
     * @param  list<string>|null  $objectKeySegments
     * @return list<array{keySegments: list<string>, rules: list<mixed>, name: string}>
     */
    private function entriesFromProperties(array $properties, ?array $objectKeySegments = null): array
    {
        return array_values(
            collect($properties)
                // A virtual (computed) property is not a request input, so it
                // gets no rules — otherwise it would validate as required.
                ->reject(fn (Property $property) => $property->isVirtual)
                ->flatMap(fn (Property $property) => $this->entryFromProperty($property, $objectKeySegments))
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
