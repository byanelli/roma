<?php

namespace BYanelli\Roma\Http\Request;

use BYanelli\Roma\Data\ClassMapping;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Http\Request\Precognition\ClientDataKeys;
use BYanelli\Roma\Http\Request\Precognition\PrecognitiveRuleFilter;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

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
     * @throws HttpException a passing precognitive request short-circuits with a 204
     */
    public function mapRequest(string $className)
    {
        $request = $this->requestResolver->get();

        $mapping = $this->objectMapper->mapping($className, new RequestPayload($request), new RequestSourcePolicy);

        if ($request->isPrecognitive()) {
            $this->validatePrecognitiveRequest($mapping, $request);
        }

        $this->asHttpValidation(fn () => $this->objectMapper->validateMapping($mapping));

        return $this->objectMapper->build($mapping);
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
     * Non-form rules are dropped, so the object cannot be safely built (and
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
        // its form-data errors are keyed and named by the bare field the
        // client posted ("email", not "input.email") — the shape the official
        // laravel-precognition-* helpers map onto form fields. Ordinary
        // validation keeps Roma's source-prefixed keys.
        $stripPrefixes = ! config('roma.precognition.source_prefixed_errors', false);

        $attributeNames = $mapping->attributeNames();

        if ($stripPrefixes) {
            $attributeNames = array_map($this->clientDataKeys->stripSourcePrefix(...), $attributeNames);
        }

        $unnamedKey = $stripPrefixes ? $this->clientDataKeys->stripSourcePrefix(...) : null;

        $validator = $this->objectMapper->makeValidator($mapping, $attributeNames, $unnamedKey);

        // Validation is optionally narrowed to the fields named in the
        // Precognition-Validate-Only header. As in FormRequest, filtering
        // runs on the validator's expanded rules — after wildcard expansion
        // against the data — so a client pattern like "items.0.code" can
        // match an array-member rule. That needs Laravel's concrete Validator
        // (or a subclass of it), whose setRules()/getRulesWithoutPlaceholders()
        // the filter relies on.
        if (! ($validator instanceof Validator)) {
            throw new RuntimeException(sprintf(
                'Precognitive validation requires an instance of %s so its rules can be '
                .'narrowed to the requested fields; the configured validator factory returned %s.',
                Validator::class,
                get_debug_type($validator),
            ));
        }

        $validator->setRules(
            $this->precognitiveRuleFilter->filter($request, $validator->getRulesWithoutPlaceholders()),
        );

        $this->asHttpValidation(fn () => $this->objectMapper->validate($validator, $attributeNames, $mapping->getClassName(), $unnamedKey));

        abort(204, headers: ['Precognition-Success' => 'true']);
    }
}
