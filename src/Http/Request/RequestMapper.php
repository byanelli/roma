<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\ClassMapping;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Http\Request\Precognition\ClientDataKeys;
use BYanelli\Roma\Http\Request\Precognition\PrecognitiveRuleFilter;
use BYanelli\Roma\Validation\ValidationErrors;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

readonly class RequestMapper implements Contracts\RequestMapper
{
    public function __construct(
        private Contracts\RequestResolver $requestResolver,
        private ObjectMapper $objectMapper,
        private PrecognitiveRuleFilter $precognitiveRuleFilter = new PrecognitiveRuleFilter,
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

        $errors = new ValidationErrors;

        foreach ($mapping->validationRules() as $rules) {
            if ($this->clientDataKeys->isClientData($rules->source)) {
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
     * Validate a precognitive request against its form data only, then answer
     * with a 204 — a precognitive request asks only "would this form data
     * pass?", so the controller never runs and this method never returns.
     * Other sources aren't validated, so the object cannot be safely built (and
     * guards cannot run): answer from validation alone, as FormRequest's
     * validate-only hook does (see Illuminate\Foundation\Precognition).
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
        $stripPrefixes = ! config('roma.precognition.source_prefixed_errors', false);

        $errors = new ValidationErrors;

        foreach ($mapping->validationRules() as $rules) {
            if (! $this->clientDataKeys->isClientData($rules->source)) {
                continue;
            }

            $attributeNames = $rules->attributeNames();
            $unnamedKey = $rules->prefixedKey(...);

            if ($stripPrefixes) {
                $attributeNames = array_map(fn (string $name) => $this->clientDataKeys->stripSourcePrefix($rules->source, $name), $attributeNames);
                $unnamedKey = fn (string $key) => $key;
            }

            $validator = $this->objectMapper->makeValidator($rules, $attributeNames, $unnamedKey);

            // As in FormRequest, filtering runs on the validator's expanded
            // rules — after wildcard expansion against the data — so a client
            // pattern like "items.0.code" can match an array-member rule. That
            // needs Laravel's concrete Validator (or a subclass of it), whose
            // setRules()/getRulesWithoutPlaceholders() the filter relies on.
            if (! ($validator instanceof Validator)) {
                throw new RuntimeException(sprintf(
                    'Precognitive validation requires an instance of %s so its rules can be '
                    .'narrowed to the requested fields; the configured validator factory returned %s.',
                    Validator::class,
                    get_debug_type($validator),
                ));
            }

            $validator->setRules(
                $this->precognitiveRuleFilter->filter($request, $rules->errorPrefix(), $validator->getRulesWithoutPlaceholders()),
            );

            $errors->add($rules, $validator, $attributeNames, $unnamedKey);
        }

        $this->asHttpValidation(fn () => $errors->throwIfAny($mapping->getClassName()));

        abort(204, headers: ['Precognition-Success' => 'true']);
    }
}
