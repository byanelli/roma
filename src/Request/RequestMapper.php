<?php

namespace BYanelli\Roma\Request;

use BYanelli\Roma\Request\Attributes\Guard;
use BYanelli\Roma\Request\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\ClassRequestMapping;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

readonly class RequestMapper implements Contracts\RequestMapper
{
    public function __construct(
        private Contracts\RequestResolver $requestResolver,
        private ValidatorFactory $validatorFactory,
        private Container $container,
        private ClassDefinitionBuilder $classDefinitionBuilder = new ClassDefinitionBuilder,
    ) {}

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function mapValuesToNestedClasses(array $values): array
    {
        return collect($values)
            ->map(fn ($v) => $this->mapValue($v))
            ->all();
    }

    private function mapValue(mixed $v): mixed
    {
        return match (true) {
            $v instanceof ClassRequestMapping => $this->mapClass($v),
            is_array($v) => $this->mapValuesToNestedClasses($v),
            default => $v,
        };
    }

    /**
     * @throws \ReflectionException
     */
    private function mapClass(ClassRequestMapping $mapping): mixed
    {
        $className = $mapping->getClassName();
        $constructorValues = $this->mapValuesToNestedClasses($mapping->getConstructorValuesArray());
        $classProperties = $this->mapValuesToNestedClasses($mapping->getClassPropertiesMap());

        $instance = new $className(...$constructorValues);

        foreach ($classProperties as $name => $value) {
            new ReflectionProperty($className, $name)->setValue($instance, $value);
        }

        return $instance;
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     */
    public function mapRequest(string $className)
    {
        $class = $this->classDefinitionBuilder->buildClassDefinition($className);
        $request = $this->requestResolver->get();

        $mapping = new ClassRequestMapping($class, $request);

        $attributeNames = $mapping->attributeNames();

        try {
            $this->validatorFactory
                ->make($mapping->data(), $this->resolveRuleClosures($mapping->rules()), [], $attributeNames)
                ->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->rekeyErrors($e, $attributeNames));
        }

        $instance = $this->mapClass($mapping);

        $this->runGuards($instance);

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
            if (! is_array($ruleSet)) {
                $rules[$key] = $ruleSet instanceof Closure ? $this->container->call($ruleSet) : $ruleSet;

                continue;
            }

            $resolved = [];

            foreach ($ruleSet as $rule) {
                if (! $rule instanceof Closure) {
                    $resolved[] = $rule;

                    continue;
                }

                $value = $this->container->call($rule);

                $resolved = is_array($value)
                    ? array_merge($resolved, array_values($value))
                    : [...$resolved, $value];
            }

            $rules[$key] = $resolved;
        }

        return $rules;
    }

    /**
     * Run every #[Guard] method on the mapped request, in declaration order,
     * through the container so guards can inject dependencies. Guards run only
     * after validation has passed; a guard rejects by throwing.
     */
    private function runGuards(object $instance): void
    {
        foreach (new ReflectionClass($instance)->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getAttributes(Guard::class) !== []) {
                // getClosure() yields a real Closure (params intact for the
                // container to inject) rather than an [$object, method] array,
                // which the container/Closure callable typehints reject.
                $this->container->call($method->getClosure($instance));
            }
        }
    }

    /**
     * Re-key the error bag from internal source-prefixed keys to the
     * client-facing names (e.g. "header.x_flag" => "header.X-Flag"). Keys
     * without a mapping (plain and array-indexed paths) are already correct.
     *
     * @param  array<string, string>  $attributeNames
     * @return array<string, list<string>>
     */
    private function rekeyErrors(ValidationException $e, array $attributeNames): array
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors[$attributeNames[$key] ?? $key] = $messages;
        }

        return $errors;
    }
}
