<?php

namespace BYanelli\Roma\Validation;

use BYanelli\Roma\Data\MappingValidationException;
use Closure;
use Illuminate\Contracts\Validation\Validator;

/**
 * The errors of the validators of a class's root sources, keyed by client-facing name.
 *
 * @internal
 */
final class ValidationErrors
{
    /**
     * @var list<array{order: list<int>, name: string, messages: list<string>}>
     */
    private array $errors = [];

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
     * Two keys with the same name, such as a header's in two cases, merge their messages.
     *
     * @return array<string, list<string>>
     */
    public function toArray(): array
    {
        $errors = $this->errors;

        usort($errors, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        $messages = [];

        foreach ($errors as $error) {
            $messages[$error['name']] = [...($messages[$error['name']] ?? []), ...$error['messages']];
        }

        return $messages;
    }

    /**
     * @param  class-string  $className
     *
     * @throws MappingValidationException
     */
    public function throwIfAny(string $className): void
    {
        if ($this->errors !== []) {
            throw new MappingValidationException($className, $this->toArray());
        }
    }
}
