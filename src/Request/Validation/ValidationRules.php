<?php

namespace BYanelli\Roma\Request\Validation;

use BYanelli\Roma\Request\Data\Property;
use BYanelli\Roma\Request\Data\Role;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types;
use BYanelli\Roma\Request\Data\Types\Class_;
use BYanelli\Roma\Request\Validation\Rules\RequiredWithinObject;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

readonly class ValidationRules
{
    /**
     * @var array<string, mixed>
     */
    private array $rules;

    /**
     * Client-facing :attribute names, keyed by internal rule key.
     *
     * @var array<string, string>
     */
    private array $attributeNames;

    public function __construct(Class_ $class)
    {
        $entries = $this->entriesFromProperties($class->properties);

        $rules = [];
        $names = [];

        foreach ($entries as $key => $entry) {
            $rules[$key] = $entry['rules'];
            $names[$key] = $entry['name'];
        }

        $this->rules = $rules;
        $this->attributeNames = $names;
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
     * @return list<mixed>
     */
    private function leadingRules(Property $property, ?string $objectKey): array
    {
        $rules = [];

        if ($property->nullable) {
            $rules[] = 'nullable';
        }

        if ($property->isRequired) {
            $rules[] = $objectKey !== null
                ? new RequiredWithinObject($objectKey)
                : 'required';
        }

        return $rules;
    }

    /**
     * @return array<string, array{rules: list<mixed>, name: string}>
     */
    private function entryFromProperty(Property $property, ?string $objectKey = null): array
    {
        [$type, $rules, $key, $name] = [
            $property->type,
            $property->rules,
            $property->getFullKey(),
            $property->errorKey,
        ];

        if ($property->role == Role::ValidationOnly) {
            $key = "__request.$key";
        }

        // Nested object: validate the object's presence/shape, then recurse
        // into its properties. Those already carry absolute dotted keys
        // (e.g. "input.address.city"), so their rules slot straight in. Their
        // required members are gated on this object's presence, so an absent or
        // null object doesn't require them.
        if ($type instanceof Class_) {
            $entries = [$key => [
                'rules' => array_merge($this->leadingRules($property, $objectKey), $rules, ['array']),
                'name' => $name,
            ]];

            return array_merge($entries, $this->entriesFromProperties($type->properties, $key));
        }

        $entries = [$key => [
            'rules' => array_merge($this->leadingRules($property, $objectKey), $rules, $this->getTypeValidationRules($type)),
            'name' => $name,
        ]];

        if ($type instanceof Types\Array_) {
            $entries = array_merge($entries, $this->arrayMemberEntries($key, $name, $type));
        }

        return $entries;
    }

    /**
     * @return array<string, array{rules: list<mixed>, name: string}>
     */
    private function arrayMemberEntries(string $key, string $name, Types\Array_ $type): array
    {
        $memberType = $type->memberType;

        // Array of scalars/enums: a single "key.*" rule for every element.
        if (! $memberType instanceof Class_) {
            return [$key.'.*' => [
                'rules' => $this->getTypeValidationRules($memberType),
                'name' => $name.'.*',
            ]];
        }

        // Array of nested objects: re-key each object property under "key.*".
        // e.g. "input.items.label" => "input.items.*.label". Members keep plain
        // required rules; Laravel's "*" only applies them to present elements.
        $result = [];

        foreach ($this->entriesFromProperties($memberType->properties) as $memberKey => $entry) {
            $result[$key.'.*.'.Str::after($memberKey, $key.'.')] = [
                'rules' => $entry['rules'],
                'name' => $name.'.*.'.Str::after($entry['name'], $name.'.'),
            ];
        }

        return $result;
    }

    /**
     * @param  list<Property>  $properties
     * @return array<string, array{rules: list<mixed>, name: string}>
     */
    private function entriesFromProperties(array $properties, ?string $objectKey = null): array
    {
        return collect($properties)
            ->flatMap(fn (Property $property) => $this->entryFromProperty($property, $objectKey))
            ->all();
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
