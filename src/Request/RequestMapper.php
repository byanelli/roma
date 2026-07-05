<?php

namespace BYanelli\Roma\Request;

use BYanelli\Roma\Request\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\ClassRequestMapping;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Validation\ValidationException;
use ReflectionProperty;

readonly class RequestMapper implements Contracts\RequestMapper
{
    public function __construct(
        private Contracts\RequestResolver $requestResolver,
        private ValidatorFactory $validatorFactory,
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
                ->make($mapping->data(), $mapping->rules(), [], $attributeNames)
                ->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->rekeyErrors($e, $attributeNames));
        }

        return $this->mapClass($mapping);
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
