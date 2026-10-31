<?php

namespace BYanelli\Roma\Data;

use BYanelli\Roma\Data\Attributes\GuardAttribute;
use BYanelli\Roma\Data\Sources\Data;
use BYanelli\Roma\Validation\ValidationErrors;
use BYanelli\Roma\Validation\ValidationRules;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Support\Arr;
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
     * @throws \ReflectionException|MappingValidationException
     */
    public function map(string $className, object $input, SourcePolicy $policy)
    {
        return $this->instantiate($this->mapping($className, $input, $policy));
    }

    /**
     * Maps a plain array, such as a tool's arguments or a stored value. `$operation` decides
     * whether the #[RequiredOnCreate] and #[OptionalOnUpdate] properties are required.
     *
     * @template T of object
     *
     * @param  class-string<T>  $className
     * @param  array<array-key, mixed>  $data
     * @return T
     *
     * @throws \ReflectionException|MappingValidationException
     */
    public function fromArray(string $className, array $data, Operation $operation = Operation::None): object
    {
        return $this->map($className, new ArrayInput($data, $operation), new ArrayPolicy);
    }

    /**
     * Validates an object constructed directly, such as with `new`, against its rules, then runs
     * its guards. Properties with #[MapFromValue] or #[MapFromInput] aren't validated, and the
     * error keys are the input keys without a source prefix. An object that contains itself fails
     * at the key where it repeats. `$operation` decides whether the #[RequiredOnCreate] and
     * #[OptionalOnUpdate] properties are required.
     *
     * @template T of object
     *
     * @param  T  $object
     * @return T
     *
     * @throws \ReflectionException|MappingValidationException
     */
    public function validate(object $object, Operation $operation = Operation::None): object
    {
        $class = new ClassDefinitionBuilder(new ObjectPolicy)->buildClassDefinition($object::class);
        $data = new ObjectData($class, $object);
        $rules = new ValidationRules($class, new Data, $data->data, $operation, nativeValues: true);

        $errors = new ValidationErrors;
        $errors->add($rules, $this->makeValidator($rules, [], null), [], fn (string $key) => $key);

        foreach ($data->cycles as $path) {
            $errors->replace($rules, $path->toKey(), "The {$path->toKey()} field refers to an object that contains it.");
        }

        $errors->throwIfAny($object::class);

        $this->runGuards($object);
        $this->validatedObjects()->record($object, $operation);

        return $object;
    }

    /**
     * The coerced, unvalidated data for `$className`.
     *
     * @param  class-string  $className
     */
    public function mapping(string $className, object $input, SourcePolicy $policy): ClassMapping
    {
        $class = new ClassDefinitionBuilder($policy)->buildClassDefinition($className);

        return ClassMapping::fromInput($class, $input, $policy->operation($input));
    }

    /**
     * Validate the mapping, then construct the object and run its guards.
     *
     * @throws \ReflectionException|MappingValidationException
     */
    public function instantiate(ClassMapping $mapping): mixed
    {
        $this->validateMapping($mapping);

        return $this->build($mapping);
    }

    /**
     * Validates each source's data with its own validator and throws the errors of all of them.
     *
     * @throws MappingValidationException
     */
    public function validateMapping(ClassMapping $mapping): void
    {
        $errors = new ValidationErrors;

        foreach ($mapping->validationRules() as $rules) {
            $attributeNames = $rules->attributeNames();
            $unnamedKey = $rules->prefixedKey(...);

            $errors->add($rules, $this->makeValidator($rules, $attributeNames, $unnamedKey), $attributeNames, $unnamedKey);
        }

        $errors->throwIfAny($mapping->getClassName());
    }

    /**
     * Construct the object from a validated mapping, run its guards and record it as validated.
     *
     * @throws \ReflectionException
     */
    public function build(ClassMapping $mapping): mixed
    {
        $instance = $this->construct($mapping);

        $this->runGuards($instance);
        $this->validatedObjects()->record($instance, $mapping->getOperation());

        return $instance;
    }

    /**
     * @param  array<string, string>  $attributeNames  keyed by rule key
     * @param  ?Closure(string): string  $unnamedKey  the name of a key with no attribute name, such as an array element's,
     *                                                or null for Laravel's own
     */
    public function makeValidator(ValidationRules $rules, array $attributeNames, ?Closure $unnamedKey): ValidatorContract
    {
        $validator = $this->validatorFactory->make(
            $rules->data(),
            $this->resolveRuleClosures($rules->toArray()),
            $rules->messages(),
            $attributeNames,
        );

        if ($validator instanceof Validator && $unnamedKey !== null) {
            $validator->setImplicitAttributesFormatter($unnamedKey);
        }

        return $validator;
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
    private function construct(ClassMapping $mapping): object
    {
        $className = $mapping->getClassName();
        $constructorValues = $mapping->transformConstructorValues(array_values($this->constructNestedClasses(
            $mapping->getConstructorMappedValuesOrNestedClassesAsList()
        )));
        $classPropertyValues = $mapping->transformClassPropertyValues($this->constructNestedClasses(
            $mapping->getClassPropertiesMappedValuesOrNestedClassesAsMap()
        ));

        $instance = new $className(...$constructorValues);

        foreach ($classPropertyValues as $name => $value) {
            new ReflectionProperty($className, $name)->setValue($instance, $value);
        }

        return $instance;
    }

    private function validatedObjects(): ValidatedObjects
    {
        return $this->container->make(ValidatedObjects::class);
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
     * Run the guard methods on the mapped object, in declaration order,
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
}
