<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Attributes\GuardAttribute;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Maps raw data onto a class: builds its definition, coerces the data,
 * validates it, constructs the object and runs its guards.
 */
readonly class ObjectMapper
{
    public function __construct(
        private ValidatorFactory $validatorFactory,
        private Container $container,
    ) {}

    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     */
    public function map(string $className, Payload $payload, SourcePolicy $policy)
    {
        return $this->instantiate($this->mapping($className, $payload, $policy));
    }

    /**
     * The coerced, unvalidated data for `$className`.
     *
     * @param  class-string  $className
     */
    public function mapping(string $className, Payload $payload, SourcePolicy $policy): ClassMapping
    {
        $class = new ClassDefinitionBuilder($policy)->buildClassDefinition($className);

        return ClassMapping::fromPayload($class, $payload);
    }

    /**
     * Validate the mapping, then construct the object and run its guards.
     *
     * @throws \ReflectionException|ValidationException
     */
    public function instantiate(ClassMapping $mapping): mixed
    {
        $attributeNames = $mapping->attributeNames();

        $this->validate($this->makeValidator($mapping, $attributeNames), $attributeNames);

        $instance = $this->construct($mapping);

        $this->runGuards($instance);

        return $instance;
    }

    /**
     * @param  array<string, string>  $attributeNames
     * @param  ?Closure(string): string  $unnamedKey  renames a key with no attribute name, such as an array element's
     */
    public function makeValidator(ClassMapping $mapping, array $attributeNames, ?Closure $unnamedKey = null): ValidatorContract
    {
        $validator = $this->validatorFactory->make(
            $mapping->data(),
            $this->resolveRuleClosures($mapping->rules()),
            [],
            $attributeNames,
        );

        if ($unnamedKey !== null && $validator instanceof Validator) {
            $validator->setImplicitAttributesFormatter($unnamedKey);
        }

        return $validator;
    }

    /**
     * Validate, re-keying any errors from internal source-prefixed keys to
     * client-facing names before rethrowing.
     *
     * @param  array<string, string>  $attributeNames
     * @param  ?Closure(string): string  $unnamedKey  renames an error key with no attribute name
     *
     * @throws ValidationException
     */
    public function validate(ValidatorContract $validator, array $attributeNames, ?Closure $unnamedKey = null): void
    {
        try {
            $validator->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->rekeyErrors($e, $attributeNames, $unnamedKey));
        }
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function constructNestedClasses(array $values): array
    {
        return collect($values)
            ->map(fn ($v) => match (true) {
                $v instanceof ClassMapping => $this->construct($v),
                is_array($v) => $this->constructNestedClasses($v),
                default => $v,
            })
            ->all();
    }

    /**
     * @throws \ReflectionException
     */
    private function construct(ClassMapping $mapping): mixed
    {
        $className = $mapping->getClassName();
        $constructorValues = $this->constructNestedClasses(
            $mapping->getConstructorMappedValuesOrNestedClassesAsList()
        );
        $classPropertyValues = $this->constructNestedClasses(
            $mapping->getClassPropertiesMappedValuesOrNestedClassesAsMap()
        );

        $instance = new $className(...$constructorValues);

        foreach ($classPropertyValues as $name => $value) {
            new ReflectionProperty($className, $name)->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * Resolve closure rules — first-class-callable references passed to #[Rule] —
     * by calling them through the container, so a rule can depend on runtime
     * state (e.g. #[CurrentUser]). A closure may return a single rule or a list
     * of rules; a returned list is spread into the property's rule list in place.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function resolveRuleClosures(array $rules): array
    {
        foreach ($rules as $key => $ruleSet) {
            $resolved = [];

            foreach (Arr::wrap($ruleSet) as $rule) {
                $value = Arr::wrap(
                    $rule instanceof Closure
                        ? $this->container->call($rule)
                        : $rule
                );

                $resolved = array_merge($resolved, array_values($value));
            }

            $rules[$key] = $resolved;
        }

        return $rules;
    }

    /**
     * Run every guard method on the mapped object, in declaration order,
     * through the container so guards can inject dependencies. Guards run only
     * after validation has passed; a guard rejects by throwing.
     */
    private function runGuards(object $instance): void
    {
        foreach (new ReflectionClass($instance)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(GuardAttribute::class, ReflectionAttribute::IS_INSTANCEOF) !== []) {
                // getClosure() yields a real Closure (params intact for the
                // container to inject) rather than an [$object, method] array,
                // which the container/Closure callable typehints reject.
                $this->container->call($method->getClosure($instance));
            }
        }
    }

    /**
     * Re-key the error bag from internal source-prefixed keys to the
     * client-facing names (e.g. "header.x_flag" => "header.X-Flag"). An
     * array-indexed path expanded by the validator ("input.items.1.code") has
     * no attribute-name entry, so it goes through `$unnamedKey` instead. Two
     * fields that share a client-facing name merge their messages.
     *
     * @param  array<string, string>  $attributeNames
     * @param  ?Closure(string): string  $unnamedKey
     * @return array<string, list<string>>
     */
    private function rekeyErrors(ValidationException $e, array $attributeNames, ?Closure $unnamedKey): array
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $name = $attributeNames[$key] ?? ($unnamedKey !== null ? $unnamedKey($key) : $key);

            $errors[$name] = array_values(array_merge($errors[$name] ?? [], $messages));
        }

        return $errors;
    }
}
