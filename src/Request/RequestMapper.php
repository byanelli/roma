<?php

namespace BYanelli\Roma\Request;

use BYanelli\Roma\Request\Attributes\Guard;
use BYanelli\Roma\Request\Data\ClassDefinitionBuilder;
use BYanelli\Roma\Request\Data\ClassRequestMapping;
use BYanelli\Roma\Request\Validation\ClientDataKeys;
use BYanelli\Roma\Request\Validation\PrecognitiveRuleFilter;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\HttpKernel\Exception\HttpException;

readonly class RequestMapper implements Contracts\RequestMapper
{
    public function __construct(
        private Contracts\RequestResolver $requestResolver,
        private ValidatorFactory $validatorFactory,
        private Container $container,
        private ClassDefinitionBuilder $classDefinitionBuilder = new ClassDefinitionBuilder,
        private PrecognitiveRuleFilter $precognitiveRuleFilter = new PrecognitiveRuleFilter,
        private ClientDataKeys $clientDataKeys = new ClientDataKeys,
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
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    public function mapRequest(string $className)
    {
        $class = $this->classDefinitionBuilder->buildClassDefinition($className);
        $request = $this->requestResolver->get();

        $mapping = new ClassRequestMapping($class, $request);

        $attributeNames = $mapping->attributeNames();

        // A precognitive response is consumed by front-end form tooling, so
        // its form-data errors are keyed and named by the bare field the
        // client posted ("email", not "input.email") — the shape the official
        // laravel-precognition-* helpers map onto form fields. Ordinary
        // validation keeps Roma's source-prefixed keys.
        $stripPrefixes = $request->isPrecognitive()
            && ! config('roma.precognition.source_prefixed_errors', false);

        if ($stripPrefixes) {
            $attributeNames = array_map($this->clientDataKeys->stripSourcePrefix(...), $attributeNames);
        }

        $validator = $this->validatorFactory->make(
            $mapping->data(),
            $this->resolveRuleClosures($mapping->rules()),
            [],
            $attributeNames,
        );

        // A precognitive request validates form data only, optionally narrowed
        // to the fields named in its Precognition-Validate-Only header. As in
        // FormRequest, filtering runs on the validator's expanded rules —
        // after wildcard expansion against the data — so a client pattern like
        // "items.0.code" can match an array-member rule.
        if ($request->isPrecognitive() && $validator instanceof Validator) {
            $validator->setRules(
                $this->precognitiveRuleFilter->filter($request, $validator->getRulesWithoutPlaceholders()),
            );
        }

        try {
            $validator->validate();
        } catch (ValidationException $e) {
            throw ValidationException::withMessages($this->rekeyErrors($e, $attributeNames, $stripPrefixes));
        }

        // A precognitive request asks only "would this form data pass?" — the
        // controller never runs. Non-form rules were dropped above, so the
        // object cannot be safely built (and guards cannot run): answer now,
        // as FormRequest's validate-only hook does (see
        // Illuminate\Foundation\Precognition).
        if ($request->isPrecognitive()) {
            abort(204, headers: ['Precognition-Success' => 'true']);
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
     * client-facing names (e.g. "header.x_flag" => "header.X-Flag", plus —
     * when a precognitive request is stripping prefixes — "input.email" =>
     * "email"). An array-indexed path expanded by the validator
     * ("input.items.1.code") has no attribute-name entry, so it falls through
     * to the same prefix stripping its siblings got. Two fields that share a
     * client-facing name merge their messages.
     *
     * @param  array<string, string>  $attributeNames
     * @return array<string, list<string>>
     */
    private function rekeyErrors(ValidationException $e, array $attributeNames, bool $stripPrefixes): array
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $name = $attributeNames[$key]
                ?? ($stripPrefixes ? $this->clientDataKeys->stripSourcePrefix($key) : $key);

            $errors[$name] = array_values(array_merge($errors[$name] ?? [], $messages));
        }

        return $errors;
    }
}
