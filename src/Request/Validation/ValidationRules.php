<?php

namespace BYanelli\Roma\Request\Validation;

use BYanelli\Roma\Request\Data\Property;
use BYanelli\Roma\Request\Data\Role;
use BYanelli\Roma\Request\Data\Type;
use BYanelli\Roma\Request\Data\Types;
use BYanelli\Roma\Request\Data\Types\Class_;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

readonly class ValidationRules
{
    /**
     * @var array<string, mixed>
     */
    private array $rules;

    public function __construct(Class_ $class)
    {
        $this->rules = $this->getValidationRulesFromProperties($class->properties);
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
     * @return array<string, mixed>
     */
    private function getValidationRulesFromProperty(Property $property): array
    {
        $result = [];

        [$type, $rules, $key] = [
            $property->type,
            $property->rules,
            $property->getFullKey(),
        ];

        if ($property->role == Role::ValidationOnly) {
            $key = "__request.$key";
        }

        // Nested object: validate the object's presence/shape, then recurse
        // into its properties. Those already carry absolute dotted keys
        // (e.g. "input.address.city"), so their rules slot straight in.
        if ($type instanceof Class_) {
            $objectRules = ['array'];

            if ($property->isRequired) {
                $objectRules[] = 'required';
            }

            $result[$key] = $objectRules;

            return array_merge($result, $this->getValidationRulesFromProperties($type->properties));
        }

        $rules = array_merge($rules, $this->getTypeValidationRules($type));

        if ($property->isRequired) {
            $rules[] = 'required';
        }

        $result[$key] = $rules;

        if ($type instanceof Types\Array_) {
            $result = array_merge($result, $this->getArrayMemberRules($key, $type));
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function getArrayMemberRules(string $key, Types\Array_ $type): array
    {
        $memberType = $type->memberType;

        // Array of scalars/enums: a single "key.*" rule for every element.
        if (! $memberType instanceof Class_) {
            return [$key.'.*' => $this->getTypeValidationRules($memberType)];
        }

        // Array of nested objects: re-key each object property under "key.*".
        // e.g. "input.items.label" => "input.items.*.label".
        $result = [];

        foreach ($this->getValidationRulesFromProperties($memberType->properties) as $memberKey => $memberRules) {
            $result[$key.'.*.'.Str::after($memberKey, $key.'.')] = $memberRules;
        }

        return $result;
    }

    /**
     * @param  list<Property>  $properties
     * @return array<string, mixed>
     */
    private function getValidationRulesFromProperties(array $properties): array
    {
        return collect($properties)
            ->flatMap(fn (Property $property) => $this->getValidationRulesFromProperty($property))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->rules;
    }
}
