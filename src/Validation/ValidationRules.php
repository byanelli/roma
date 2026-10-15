<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Source;
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
 * The rules for the data of one source, keyed relative to the source: "email",
 * "address.city", "items.*.code". Error keys are the source's prefix followed by the rule key.
 *
 * @phpstan-type Entry array{path: KeyPath, rules: list<mixed>, name: string}
 */
readonly class ValidationRules
{
    /**
     * The data given to the validator.
     *
     * @var array<array-key, mixed>
     */
    private array $data;

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
     * The prefix of the error keys, e.g. "input", or "" for a single-value source.
     */
    private string $prefix;

    /**
     * The position of each rule key without a wildcard: its property's index in the class
     * and its index among that property's entries.
     *
     * @var array<string, array{int, int}>
     */
    private array $positions;

    /**
     * A pattern matching the keys a wildcard rule key expands to, with its position.
     *
     * @var list<array{string, array{int, int}}>
     */
    private array $wildcards;

    /**
     * `$data` is the coerced data of `$source`. The rules for a union depend on its
     * discriminator, and the rules for a class that contains itself are only as deep as the
     * data, so an array that contains either gets rules per element. A single-value source's
     * value is validated under the source's key as one segment, e.g. "request.ip".
     */
    public function __construct(Class_ $class, public Source $source, mixed $data)
    {
        $dataDependent = [];
        $this->findDataDependent($class, [], $dataDependent);
        $this->dataDependent = $dataDependent;

        $single = $source->isSingleValue();
        $base = $single ? new KeyPath([$source->getKey()]) : new KeyPath;
        $this->prefix = $single ? '' : $source->getKey();
        $this->data = $single ? [$source->getKey() => $data] : (is_array($data) ? $data : []);

        $rules = [];
        $names = [];
        $positions = [];
        $wildcards = [];

        foreach ($class->properties as $index => $property) {
            if ($property->isVirtual || $property->source?->getKey() !== $source->getKey()) {
                continue;
            }

            foreach ($this->entryFromProperty($property, $base) as $entryIndex => $entry) {
                $key = $entry['path']->toRuleKey();
                $plainKey = $entry['path']->toKey();

                // Properties of one accessor, or a property and a class-level constraint, may validate the same key.
                $rules[$key] = [...($rules[$key] ?? []), ...$entry['rules']];

                // The attribute-name key is the unescaped join, matching what
                // Laravel puts in the error bag and looks up for :attribute. A
                // wildcard key gets no name, so messages show the concrete key.
                if ($entry['path']->hasWildcard()) {
                    $wildcards[] = ['/^'.str_replace('\*', '[^.]*', preg_quote($plainKey, '/')).'\z/', [$index, $entryIndex]];
                } else {
                    $names[$plainKey] ??= $entry['name'];
                    $positions[$plainKey] ??= [$index, $entryIndex];
                }
            }
        }

        $this->rules = $rules;
        $this->attributeNames = $names;
        $this->positions = $positions;
        $this->wildcards = $wildcards;
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
     * The presence rules at the start of each value's rule list: "nullable"
     * (allow an explicit null) and the requiredness rule. A required member of
     * a nested object or shape becomes a RequiredWithinObject rule keyed on
     * that object, so it is only required when the object is actually present —
     * an absent or null object doesn't trip its members.
     *
     * @return list<mixed>
     */
    private function leadingRules(bool $nullable, bool $required, ?KeyPath $object): array
    {
        $rules = [];

        if ($nullable) {
            $rules[] = 'nullable';
        }

        if ($required) {
            $rules[] = $object !== null
                ? new RequiredWithinObject($object)
                : 'required';
        }

        return $rules;
    }

    /**
     * @param  KeyPath  $base  the key a single-value source's value is validated under
     * @return list<Entry>
     */
    private function entryFromProperty(Property $property, KeyPath $base): array
    {
        return $this->valueEntries(
            $property->type,
            $base->concat($property->keyPath),
            $property->errorKey,
            [...$this->leadingRules($property->nullable, $property->isRequired, null), ...$property->rules],
        );
    }

    /**
     * The entries for a value of `$type` at `$path`, followed by those of its members. A '*'
     * segment stands for the members of an enclosing array.
     *
     * @param  list<mixed>  $rules  the presence and declared rules, which precede the type's rules
     * @return list<Entry>
     */
    private function valueEntries(Type $type, KeyPath $path, string $name, array $rules): array
    {
        // A value object that parses from a raw string and vets it with its own
        // rule is validated as a leaf string at its own key — the input is one
        // string (e.g. a header value), not a structured sub-object, so the
        // object's fields are not recursed into.
        if ($type instanceof Class_ && $this->validatesAsString($type->class)) {
            return [[
                'path' => $path,
                'rules' => [...$rules, 'string', ...$this->stringValueRules($type->class)],
                'name' => $name,
            ]];
        }

        $typeRules = $this->getTypeValidationRules($type);

        if ($type instanceof Types\Boolean && ! $this->hasPresenceRule($rules)) {
            $typeRules[] = new RejectBlankString('validation.boolean');
        }

        $entry = [
            'path' => $path,
            'rules' => [...$rules, ...$typeRules],
            'name' => $name,
        ];

        return match (true) {
            $type instanceof Class_ && $this->dependsOnData($type) && ! is_array($path->getFrom($this->data)) => [$entry],
            $type instanceof Class_ => [$entry, ...$this->objectMemberEntries($type, $path, $name)],
            $type instanceof Types\Shape => [$entry, ...$this->shapeItemEntries($type, $path, $name)],
            $type instanceof Types\Union => [$entry, ...$this->unionEntries($type, $path, $name)],
            $type instanceof Types\Array_ && $this->dependsOnData($type->memberType) => [$entry, ...$this->elementEntries($type, $path, $name)],
            $type instanceof Types\Array_ => [$entry, ...$this->valueEntries($type->memberType, $path->append('*'), "$name.*", [])],
            default => [$entry],
        };
    }

    /**
     * The discriminator entry, followed by the entries of the member it selects.
     *
     * @return list<Entry>
     */
    private function unionEntries(Types\Union $union, KeyPath $path, string $name): array
    {
        $object = $path->getFrom($this->data);
        $member = is_array($object) ? $union->memberFor($object[$union->key] ?? null) : null;

        return [
            [
                'path' => $path->append($union->key),
                'rules' => [new DiscriminatorIn($path, $union->values())],
                'name' => "$name.$union->key",
            ],
            ...($member !== null ? $this->objectMemberEntries($member, $path, $name) : []),
        ];
    }

    /**
     * Entries for each element present in the data, under its own key.
     *
     * @return list<Entry>
     */
    private function elementEntries(Types\Array_ $array, KeyPath $path, string $name): array
    {
        $elements = $path->getFrom($this->data);

        if (! is_array($elements)) {
            return [];
        }

        $entries = [];

        foreach (array_keys($elements) as $key) {
            $entries = [...$entries, ...$this->valueEntries($array->memberType, $path->append((string) $key), "$name.$key", [])];
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
     * Fills `$found` for the classes `$type` reaches. A class reached again while its own
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
     * Visits the types, so that `$found` covers all the classes they reach.
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
     * @return list<Entry>
     */
    private function objectMemberEntries(Class_ $class, KeyPath $path, string $name): array
    {
        $entries = [];

        foreach ($class->properties as $member) {
            // A virtual (computed) property is not an input, so it gets no
            // rules — otherwise it would validate as required.
            if ($member->isVirtual) {
                continue;
            }

            $entries = [...$entries, ...$this->valueEntries(
                $member->type,
                $path->concat($member->keyPath),
                "$name.{$member->keyPath->toKey()}",
                [...$this->leadingRules($member->nullable, $member->isRequired, $path), ...$member->rules],
            )];
        }

        return $entries;
    }

    /**
     * @return list<Entry>
     */
    private function shapeItemEntries(Types\Shape $shape, KeyPath $path, string $name): array
    {
        $entries = [];

        foreach ($shape->items as $item) {
            $entries = [...$entries, ...$this->valueEntries(
                $item->type,
                $path->append($item->key),
                "$name.$item->key",
                $this->leadingRules($item->nullable, ! $item->optional, $path),
            )];
        }

        return $entries;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function data(): array
    {
        return $this->data;
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

    /**
     * The prefix of the error keys, e.g. "input", or "" for a single-value source.
     */
    public function errorPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * The client-facing key for a key the validator reports, e.g. "input.items.1.code".
     */
    public function prefixedKey(string $key): string
    {
        return $this->prefix === '' ? $key : "{$this->prefix}.{$key}";
    }

    /**
     * Where an error for `$key` belongs among the errors of all sources: a key without a
     * wildcard first, by declaration, then the keys a wildcard rule expands to. This is the
     * order of one Laravel validator over all the sources' rules.
     *
     * @return array{int, int, int}
     */
    public function order(string $key): array
    {
        if (isset($this->positions[$key])) {
            return [0, ...$this->positions[$key]];
        }

        foreach ($this->wildcards as [$pattern, $position]) {
            if (preg_match($pattern, $key) === 1) {
                return [1, ...$position];
            }
        }

        return [2, 0, 0];
    }
}
