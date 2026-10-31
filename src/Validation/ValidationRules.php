<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Attributes\Limit;
use BYanelli\Roma\Attributes\MapFromValue;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\RejectUnknownKeys;
use BYanelli\Roma\Data\KeyPath;
use BYanelli\Roma\Data\ObjectData;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\Property;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Data\Type;
use BYanelli\Roma\Data\Types;
use BYanelli\Roma\Data\Types\Class_;
use BYanelli\Roma\Data\Values\HasValidationRules;
use BYanelli\Roma\Data\Values\ParsesStringValue;
use BYanelli\Roma\Validation\Rules\DiscriminatorIn;
use BYanelli\Roma\Validation\Rules\EnumCase;
use BYanelli\Roma\Validation\Rules\FiniteNumber;
use BYanelli\Roma\Validation\Rules\IntegerKeys;
use BYanelli\Roma\Validation\Rules\NativeInstance;
use BYanelli\Roma\Validation\Rules\ObjectValue;
use BYanelli\Roma\Validation\Rules\RequiredWithinObject;
use BYanelli\Roma\Validation\Rules\ScalarBoolean;
use BYanelli\Roma\Validation\Rules\UnknownKey;
use BYanelli\Roma\Validation\Rules\ValidInterval;
use DateTimeInterface;

/**
 * The rules for the data of one source, keyed relative to the source: "email",
 * "address.city", "items.*.code". Error keys are the source's prefix followed by the rule key.
 *
 * @phpstan-type Entry array{path: KeyPath, rules: list<mixed>, name: string, message?: ?Message}
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
     * Laravel's custom messages from #[Message], keyed by rule key and rule, e.g. "email.required".
     *
     * @var array<string, string>
     */
    private array $messages;

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
     * data, so an array that contains either gets rules per element, as does an array that
     * contains a non-finite float. A single-value source's value is validated under the
     * source's key as one segment, e.g. "request.ip". `$operation`
     * decides whether the #[RequiredOnCreate] and #[OptionalOnUpdate] properties are required. `$checkedKeys` are the source's
     * top-level keys that #[RejectUnknownKeys] checks, or null for all of them. With `$nativeValues`,
     * `$data` is a constructed object's ObjectData, so values such as dates are already objects and
     * there are no unknown keys.
     *
     * @param  ?list<array-key>  $checkedKeys
     */
    public function __construct(
        Class_ $class,
        public Source $source,
        mixed $data,
        private Operation $operation = Operation::None,
        ?array $checkedKeys = [],
        private bool $nativeValues = false,
    ) {
        $dataDependent = [];
        $this->findDataDependent($class, [], $dataDependent);
        $this->dataDependent = $dataDependent;

        $single = $source->isSingleValue();
        $base = $single ? new KeyPath([$source->getKey()]) : new KeyPath;
        $this->prefix = $single ? '' : $source->getKey();
        $this->data = $single ? [$source->getKey() => $data] : (is_array($data) ? $data : []);

        $rules = [];
        $names = [];
        $messages = [];
        $positions = [];
        $wildcards = [];
        $groups = [];

        foreach ($class->properties as $index => $property) {
            if (! $this->validates($class, $property) || $property->source?->isInjected() || $property->source?->getKey() !== $source->getKey()) {
                continue;
            }

            $groups[$index] = $this->entryFromProperty($property, $base);
        }

        if ($class->rejectUnknownKeys !== null && ! $single && ! $this->nativeValues) {
            $groups[count($class->properties)] = $this->unknownKeyEntries(
                $this->unknownKeys($this->data, $this->topLevelKeyTree($class), new KeyPath, $checkedKeys),
                $class->rejectUnknownKeys,
            );
        }

        foreach ($groups as $index => $entries) {
            foreach ($entries as $entryIndex => $entry) {
                $key = $entry['path']->toRuleKey();
                $plainKey = $entry['path']->toKey();

                // Properties of one accessor, or a property and a class-level constraint, may validate the same key.
                $rules[$key] = [...($rules[$key] ?? []), ...$entry['rules']];

                if (isset($entry['message'])) {
                    $messages = [...$messages, ...$entry['message']->forKey($key)];
                }

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
        $this->messages = $messages;
        $this->positions = $positions;
        $this->wildcards = $wildcards;
    }

    /**
     * The known keys of a top-level class: those of the properties of the sources that share
     * this source's keys, virtual ones included, since they are serialized.
     *
     * @return array<array-key, mixed>
     */
    private function topLevelKeyTree(Class_ $class): array
    {
        $properties = array_filter(
            $class->properties,
            fn (Property $property) => $property->source !== null && $this->source->sharesKeysWith($property->source),
        );

        return $this->keyTree(array_map(fn (Property $property) => $property->keyPath, $properties));
    }

    /**
     * The key paths as nested arrays, with true for the end of a path.
     *
     * @param  array<KeyPath>  $paths
     * @return array<array-key, mixed>
     */
    private function keyTree(array $paths): array
    {
        $tree = [];

        foreach ($paths as $path) {
            $tree = $this->withPath($tree, $path->segments);
        }

        return $tree;
    }

    /**
     * @param  array<array-key, mixed>  $tree
     * @param  list<string>  $segments
     * @return array<array-key, mixed>
     */
    private function withPath(array $tree, array $segments): array
    {
        if ($segments === []) {
            return $tree;
        }

        $segment = array_shift($segments);
        $node = $tree[$segment] ?? [];

        if ($node !== true) {
            $tree[$segment] = $segments === [] ? true : $this->withPath(is_array($node) ? $node : [], $segments);
        }

        return $tree;
    }

    /**
     * The paths of the keys of `$data` that aren't in `$tree`. The keys inside a known key's
     * value are checked only for an object whose class has #[RejectUnknownKeys]. A key with a
     * '*' is skipped, since Laravel reads it in a rule key as a wildcard.
     *
     * @param  array<array-key, mixed>  $data
     * @param  array<array-key, mixed>  $tree
     * @param  ?list<array-key>  $checked  the keys of `$data` that may be unknown, or null for all
     * @return list<KeyPath>
     */
    private function unknownKeys(array $data, array $tree, KeyPath $path, ?array $checked = null): array
    {
        $checked = $checked === null ? null : array_map(strval(...), $checked);
        $unknown = [];

        foreach ($data as $key => $value) {
            $key = (string) $key;
            $node = $tree[$key] ?? null;

            if (($checked !== null && ! in_array($key, $checked, true)) || str_contains($key, '*')) {
                continue;
            }

            if ($node === null) {
                $unknown[] = $path->append($key);
            } elseif (is_array($node) && is_array($value)) {
                $unknown = [...$unknown, ...$this->unknownKeys($value, $node, $path->append($key))];
            }
        }

        return $unknown;
    }

    /**
     * @param  list<KeyPath>  $paths
     * @return list<Entry>
     */
    private function unknownKeyEntries(array $paths, RejectUnknownKeys $attribute): array
    {
        return array_map(fn (KeyPath $path) => [
            'path' => $path,
            'rules' => [new UnknownKey($attribute->message)],
            'name' => $this->prefixedKey($path->toKey()),
        ], $paths);
    }

    /**
     * The unknown keys of the objects at `$path`, whose '*' segments stand for the members of
     * arrays. A non-empty list fails the object's own rule, so its keys aren't reported.
     *
     * @param  ?string  $discriminator  the key of the union the class is a member of
     * @return list<Entry>
     */
    private function objectUnknownKeyEntries(Class_ $class, KeyPath $path, ?string $discriminator): array
    {
        if ($class->rejectUnknownKeys === null || $this->nativeValues) {
            return [];
        }

        $paths = array_map(fn (Property $property) => $property->keyPath, $class->properties);
        $tree = $this->keyTree($discriminator !== null ? [...$paths, new KeyPath([$discriminator])] : $paths);
        $unknown = [];

        foreach ($this->concretePaths($path) as $concrete) {
            $object = $concrete->getFrom($this->data);

            if (is_array($object) && ($object === [] || ! array_is_list($object))) {
                $unknown = [...$unknown, ...$this->unknownKeys($object, $tree, $concrete)];
            }
        }

        return $this->unknownKeyEntries($unknown, $class->rejectUnknownKeys);
    }

    /**
     * The paths in the data that `$path` matches, with each '*' replaced by an array key.
     *
     * @return list<KeyPath>
     */
    private function concretePaths(KeyPath $path): array
    {
        $paths = [new KeyPath];

        foreach ($path->segments as $segment) {
            $next = [];

            foreach ($paths as $concrete) {
                if ($segment !== '*') {
                    $next[] = $concrete->append($segment);

                    continue;
                }

                $value = $concrete->getFrom($this->data);

                foreach (is_array($value) ? array_keys($value) : [] as $key) {
                    $next[] = $concrete->append((string) $key);
                }
            }

            $paths = $next;
        }

        return $paths;
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
     * Whether the property gets rules. A virtual (computed) property is not an input, so it
     * would otherwise validate as required. A constructed object's #[MapFromValue] callables have
     * already run, and its rules describe the value before them.
     */
    private function validates(Class_ $class, Property $property): bool
    {
        if ($property->isVirtual) {
            return false;
        }

        return ! $this->nativeValues || (ObjectData::isReadable($class, $property) && ! $property->hasAttribute(MapFromValue::class));
    }

    /**
     * The property's own rules. For a constructed object, #[Limit] adds a "max" of the longest
     * string Str::limit() returns, the limit plus the end's length. A larger "max:n" rule is
     * lowered to it instead, so a long value gets one message.
     *
     * @return list<mixed>
     */
    private function declaredRules(Property $property): array
    {
        $limit = $this->nativeValues ? $property->attribute(Limit::class) : null;

        if ($limit === null) {
            return array_values($property->rules);
        }

        $max = $limit->limit + mb_strlen($limit->end);
        $found = false;
        $rules = [];

        foreach ($property->rules as $rule) {
            if (is_string($rule) && preg_match('/^max:(\d+)$/', $rule, $matches) === 1) {
                $rule = 'max:'.min((int) $matches[1], $max);
                $found = true;
            }

            $rules[] = $rule;
        }

        return $found ? $rules : [...$rules, "max:{$max}"];
    }

    /**
     * @return list<mixed>
     */
    private function getTypeValidationRules(Type $type): array
    {
        if ($this->nativeValues && ($rules = $this->nativeTypeRules($type)) !== null) {
            return $rules;
        }

        return match (true) {
            $type instanceof Types\Boolean => [new ScalarBoolean],
            $type instanceof Types\Integer => [new FiniteNumber('validation.integer'), 'integer'],
            $type instanceof Types\Float_ => [new FiniteNumber, 'numeric'],
            $type instanceof Types\Date => [$type->format !== null ? "date_format:{$type->format}" : 'date'],
            $type instanceof Types\Interval => [new ValidInterval($type->unit)],
            $type instanceof Types\String_ => ['string'],
            $type instanceof Types\Array_ => match (true) {
                $type->isList => ['array', 'list'],
                $type->keyType instanceof Types\Integer => ['array', new IntegerKeys],
                default => ['array'],
            },
            $type instanceof Class_, $type instanceof Types\Union => ['array', new ObjectValue],
            $type instanceof Types\Shape => ['array'],
            $type instanceof Types\Enum => [new EnumCase($type->class)],
            default => [],
        };
    }

    /**
     * The type rules for a constructed object's value, or null where they are the input's. The
     * strict rules reject a numeric string in an array, which PHP doesn't check. A nested object
     * is an array in ObjectData.
     *
     * @return ?list<mixed>
     */
    private function nativeTypeRules(Type $type): ?array
    {
        return match (true) {
            $type instanceof Types\Boolean => ['boolean:strict'],
            $type instanceof Types\Integer => ['integer:strict'],
            $type instanceof Types\Float_ => [new FiniteNumber, 'numeric:strict'],
            $type instanceof Types\Date => [new NativeInstance($type->class ?? DateTimeInterface::class)],
            $type instanceof Types\Interval => [new NativeInstance($type->class)],
            $type instanceof Types\Enum => [new NativeInstance($type->class)],
            $type instanceof Class_, $type instanceof Types\Union => ['array'],
            default => null,
        };
    }

    /**
     * The presence rules at the start of each value's rule list: "nullable" and
     * the requiredness rule. A required member of a nested object or shape becomes
     * a RequiredWithinObject rule keyed on that object, so it is only required
     * when the object is present. An absent or null object requires none of its members.
     *
     * @param  ?string  $shapeKey  a shape item's key; a required nullable item may then be null,
     *                             but its key must be present
     * @return list<mixed>
     */
    private function leadingRules(bool $nullable, bool $required, ?KeyPath $object, ?string $shapeKey = null): array
    {
        $rules = [];

        // RequiredWithinObject rejects a null member that isn't nullable. With "nullable", Laravel
        // skips the member's other rules for null, as it does after "required" fails.
        if ($nullable || ($required && $object !== null)) {
            $rules[] = 'nullable';
        }

        if ($required) {
            $rules[] = $object !== null
                ? new RequiredWithinObject($object, $nullable ? $shapeKey : null)
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
        return $this->withMessage($property, $this->valueEntries(
            $property->type,
            $base->concat($property->keyPath),
            $property->errorKey,
            [...$this->leadingRules($property->nullable, $property->isRequiredFor($this->operation), null), ...$this->declaredRules($property)],
            $property->itemRules,
        ));
    }

    /**
     * Attaches the property's #[Message] to its own entry, the first, and to the entries of its
     * items when it has #[RulePerItem], but not to its members'.
     *
     * @param  list<Entry>  $entries
     * @return list<Entry>
     */
    private function withMessage(Property $property, array $entries): array
    {
        if ($entries === []) {
            return $entries;
        }

        $message = $property->attribute(Message::class);
        $itemDepth = count($entries[0]['path']->segments) + 1;

        foreach ($entries as $index => $entry) {
            if ($index === 0 || ($property->itemRules !== [] && count($entry['path']->segments) === $itemDepth)) {
                $entries[$index]['message'] = $message;
            }
        }

        return $entries;
    }

    /**
     * The entries for a value of `$type` at `$path`, followed by those of its members. A '*'
     * segment stands for the members of an enclosing array.
     *
     * @param  list<mixed>  $rules  the presence and declared rules, which precede the type's rules
     * @param  list<mixed>  $itemRules  the declared rules of an array's items
     * @return list<Entry>
     */
    private function valueEntries(Type $type, KeyPath $path, string $name, array $rules, array $itemRules = []): array
    {
        // A value object that parses from a raw string and vets it with its own
        // rule is validated as a leaf string at its own key — the input is one
        // string (e.g. a header value), not a structured sub-object, so the
        // object's fields are not recursed into.
        if ($type instanceof Class_ && $this->validatesAsString($type->class)) {
            return [[
                'path' => $path,
                'rules' => $this->withTypeRules($path, $rules, $this->nativeValues ? [new NativeInstance($type->class)] : ['string', ...$this->stringValueRules($type->class)]),
                'name' => $name,
            ]];
        }

        $entry = [
            'path' => $path,
            'rules' => $this->withTypeRules($path, $rules, $this->getTypeValidationRules($type)),
            'name' => $name,
        ];

        return match (true) {
            $type instanceof Class_ && $this->dependsOnData($type) && ! is_array($path->getFrom($this->data)) => [$entry],
            $type instanceof Class_ => [$entry, ...$this->objectMemberEntries($type, $path, $name)],
            $type instanceof Types\Shape => [$entry, ...$this->shapeItemEntries($type, $path, $name)],
            $type instanceof Types\Union => [$entry, ...$this->unionEntries($type, $path, $name)],
            $type instanceof Types\Array_ && ($this->dependsOnData($type->memberType) || $this->containsNonFinite($path->getFrom($this->data))) => [$entry, ...$this->elementEntries($type, $path, $name, $itemRules)],
            $type instanceof Types\Array_ => [$entry, ...$this->valueEntries($type->memberType, $path->append('*'), "$name.*", $itemRules)],
            default => [$entry],
        };
    }

    /**
     * Laravel's size rules convert a number to a string or a BigNumber, which fails for NAN and
     * INF, so a non-finite float is checked against its type first, and its checks stop there.
     *
     * @param  list<mixed>  $rules
     * @param  list<mixed>  $typeRules
     * @return list<mixed>
     */
    private function withTypeRules(KeyPath $path, array $rules, array $typeRules): array
    {
        $value = $path->getFrom($this->data);

        return is_float($value) && ! is_finite($value)
            ? ['bail', ...$typeRules, ...$rules]
            : [...$rules, ...$typeRules];
    }

    /**
     * Whether `$value` is an array that contains NAN, INF or -INF at any depth.
     */
    private function containsNonFinite(mixed $value): bool
    {
        return is_array($value) && array_any(
            $value,
            fn (mixed $item) => (is_float($item) && ! is_finite($item)) || $this->containsNonFinite($item),
        );
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
            ...($member !== null ? $this->objectMemberEntries($member, $path, $name, $union->key) : []),
        ];
    }

    /**
     * Entries for each element present in the data, under its own key.
     *
     * @param  list<mixed>  $itemRules
     * @return list<Entry>
     */
    private function elementEntries(Types\Array_ $array, KeyPath $path, string $name, array $itemRules): array
    {
        $elements = $path->getFrom($this->data);

        if (! is_array($elements)) {
            return [];
        }

        $entries = [];

        foreach (array_keys($elements) as $key) {
            $entries = [...$entries, ...$this->valueEntries($array->memberType, $path->append((string) $key), "$name.$key", $itemRules)];
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
     * @param  ?string  $discriminator  the key of the union the class is a member of
     * @return list<Entry>
     */
    private function objectMemberEntries(Class_ $class, KeyPath $path, string $name, ?string $discriminator = null): array
    {
        $entries = [];

        foreach ($class->properties as $member) {
            if (! $this->validates($class, $member)) {
                continue;
            }

            $entries = [...$entries, ...$this->withMessage($member, $this->valueEntries(
                $member->type,
                $path->concat($member->keyPath),
                "$name.{$member->keyPath->toKey()}",
                [...$this->leadingRules($member->nullable, $member->isRequiredFor($this->operation), $path), ...$this->declaredRules($member)],
                $member->itemRules,
            ))];
        }

        return [...$entries, ...$this->objectUnknownKeyEntries($class, $path, $discriminator)];
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
                $this->leadingRules($item->nullable, ! $item->optional, $path, $item->key),
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
    public function messages(): array
    {
        return $this->messages;
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
