<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Data\MappingValidationException;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * The errors of the validators of a class's sources, keyed by client-facing name.
 *
 * @internal
 */
final class ValidationErrors
{
    /**
     * @var list<array{order: list<int>, name: string, messages: list<string>, replaces?: true}>
     */
    private array $errors = [];

    /**
     * The names whose validator errors are left out, with those of the keys inside them.
     *
     * @var list<string>
     */
    private array $replaced = [];

    /**
     * @param  array<string, string>  $attributeNames  keyed by rule key
     * @param  Closure(string): string  $unnamedKey  the name of a key with no attribute name, such as an array element's
     */
    public function add(ValidationRules $rules, Validator $validator, array $attributeNames, Closure $unnamedKey): void
    {
        if (! $validator->fails()) {
            return;
        }

        foreach ($validator->errors()->messages() as $key => $messages) {
            $this->errors[] = [
                'order' => [...$rules->order((string) $key), count($this->errors)],
                'name' => $attributeNames[$key] ?? $unnamedKey((string) $key),
                'messages' => array_values($messages),
            ];
        }
    }

    /**
     * An error found before validation, under the rule key `$key` as its name. The validator's
     * errors for the key and the keys inside it are left out.
     */
    public function replace(ValidationRules $rules, string $key, string $message): void
    {
        $this->errors[] = [
            'order' => [...$rules->order($key), count($this->errors)],
            'name' => $key,
            'messages' => [$message],
            'replaces' => true,
        ];
        $this->replaced[] = $key;
    }

    /**
     * Two keys with the same name merge their messages.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $errors = array_values(array_filter($this->errors, fn (array $error) => isset($error['replaces']) || ! $this->isReplaced($error['name'])));

        usort($errors, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $messages = [];

        foreach ($errors as $error) {
            $messages[$error['name']] = [...($messages[$error['name']] ?? []), ...$error['messages']];
        }

        return $messages;
    }

    private function isReplaced(string $name): bool
    {
        return array_any($this->replaced, fn (string $key) => $name === $key || str_starts_with($name, "{$key}."));
    }

    /**
     * @param  class-string  $className
     *
     * @throws MappingValidationException
     */
    public function throwIfAny(string $className): void
    {
        if ($this->toArray() !== []) {
            throw new MappingValidationException($className, $this->toArray());
        }
    }
}
