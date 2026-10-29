<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\ArrayInput;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ClassMapping;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\Operation;
use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Request\Precognition\ClientDataKeys;
use BYanelli\Roma\Http\Request\Precognition\PrecognitiveValidator;
use BYanelli\Roma\Http\Request\Sources\RequestSource;
use BYanelli\Roma\Validation\ValidationErrors;
use BYanelli\Roma\Validation\ValidationRules;
use Closure;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

readonly class RequestMapper implements Contracts\RequestMapper
{
    public function __construct(
        private Contracts\RequestResolver $requestResolver,
        private ObjectMapper $objectMapper,
        private PrecognitiveValidator $precognitiveValidator,
        private ClientDataKeys $clientDataKeys = new ClientDataKeys,
    ) {}

    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     * @throws HttpResponseException the class implements HandlesFailedValidation and validation failed
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    public function mapRequest(string $className)
    {
        $request = $this->requestResolver->get();

        $mapping = $this->objectMapper->mapping($className, $request, new RequestSourcePolicy);

        try {
            if ($request->isPrecognitive()) {
                $this->validatePrecognitiveRequest($mapping, $request);
            }

            $this->asHttpValidation(fn () => $this->validate($mapping));

            return $this->objectMapper->build($mapping);
        } catch (ValidationException $e) {
            throw $this->failedValidation($className, $e, $request);
        }
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     * @throws HttpResponseException the class implements HandlesFailedValidation and validation failed
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    public function mapBody(string $className)
    {
        $request = $this->requestResolver->get();

        return $this->mapArray($className, $request, RequestSource::bodyFields($request), new RequestSourcePolicy()->operation($request));
    }

    /**
     * @template T
     *
     * @param  class-string<T>  $className
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     * @throws HttpResponseException the class implements HandlesFailedValidation and validation failed
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    public function mapQuery(string $className)
    {
        $request = $this->requestResolver->get();

        return $this->mapArray($className, $request, $request->query->all(), Operation::None);
    }

    /**
     * Maps one part of the request as Mapper::fromArray() maps an array.
     *
     * @template T
     *
     * @param  class-string<T>  $className
     * @param  array<array-key, mixed>  $data
     * @return T
     *
     * @throws \ReflectionException|ValidationException
     * @throws HttpResponseException the class implements HandlesFailedValidation and validation failed
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    private function mapArray(string $className, Request $request, array $data, Operation $operation)
    {
        $mapping = $this->objectMapper->mapping($className, new ArrayInput($data, $operation), new ArrayPolicy);

        try {
            if ($request->isPrecognitive()) {
                $this->precognitiveValidator->validate($request, $mapping->validationRules(), bareKeys: true);
            }

            // The data is the only source, so a "data." prefix would tell the client nothing.
            $this->asHttpValidation(fn () => $this->validateWithLaravelKeys($mapping, fn () => true));

            return $this->objectMapper->build($mapping);
        } catch (ValidationException $e) {
            throw $this->failedValidation($className, $e, $request);
        }
    }

    /**
     * With `roma.source_prefixed_errors` off, input, query, body and file errors are keyed and
     * worded as Laravel's validator words them: "file_name", "The file name field is required."
     *
     * @throws MappingValidationException
     */
    private function validate(ClassMapping $mapping): void
    {
        if (config('roma.source_prefixed_errors', true)) {
            $this->objectMapper->validateMapping($mapping);

            return;
        }

        $this->validateWithLaravelKeys($mapping, $this->clientDataKeys->isClientData(...));
    }

    /**
     * The errors of the sources that pass `$unprefixed` are keyed and worded as Laravel's
     * validator words them, and the others keep their source prefix.
     *
     * @param  Closure(Source): bool  $unprefixed
     *
     * @throws MappingValidationException
     */
    private function validateWithLaravelKeys(ClassMapping $mapping, Closure $unprefixed): void
    {
        $errors = new ValidationErrors;

        foreach ($mapping->validationRules() as $rules) {
            if ($unprefixed($rules->source)) {
                $errors->add($rules, $this->objectMapper->makeValidator($rules, [], null), [], fn (string $key) => $key);

                continue;
            }

            $attributeNames = $rules->attributeNames();
            $unnamedKey = $rules->prefixedKey(...);

            $errors->add($rules, $this->objectMapper->makeValidator($rules, $attributeNames, $unnamedKey), $attributeNames, $unnamedKey);
        }

        $errors->throwIfAny($mapping->getClassName());
    }

    /**
     * @param  class-string  $className
     */
    private function failedValidation(string $className, ValidationException $exception, Request $request): Throwable
    {
        if (! is_a($className, HandlesFailedValidation::class, true)) {
            return $exception;
        }

        $response = $className::failedValidation($exception);

        return new HttpResponseException($response instanceof Responsable ? $response->toResponse($request) : $response);
    }

    /**
     * Laravel renders a ValidationException as a 422 with the same error keys and messages.
     *
     * @param  callable(): void  $validate
     *
     * @throws ValidationException
     */
    private function asHttpValidation(callable $validate): void
    {
        try {
            $validate();
        } catch (MappingValidationException $e) {
            throw ValidationException::withMessages($e->errors());
        }
    }

    /**
     * A precognitive request asks only whether its form data would pass, so the
     * other sources are left out and the method never returns.
     *
     * @throws RuntimeException the validator factory did not return a Laravel Validator
     * @throws ValidationException
     * @throws HttpException a passing request short-circuits with a 204
     */
    private function validatePrecognitiveRequest(ClassMapping $mapping, Request $request): never
    {
        // A precognitive response is consumed by front-end form tooling, so
        // its form-data errors are keyed by, and refer to, the bare field the
        // client posted ("email", not "input.email") — the shape the official
        // laravel-precognition-* helpers map onto form fields. Ordinary
        // validation keeps Roma's source-prefixed keys.
        $this->precognitiveValidator->validate(
            $request,
            array_filter($mapping->validationRules(), fn (ValidationRules $rules) => $this->clientDataKeys->isClientData($rules->source)),
            bareKeys: ! config('roma.precognition.source_prefixed_errors', false),
        );
    }
}
