<?php

namespace BYanelli\Roma\Http\Request\Precognition;

use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Validation\ValidationErrors;
use BYanelli\Roma\Validation\ValidationRules;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Validates the form data of a precognitive request, narrowed to the fields in
 * Precognition-Validate-Only, and answers with a 422 or a 204.
 */
readonly class PrecognitiveValidator
{
    public function __construct(
        private ObjectMapper $objectMapper,
        private PrecognitiveRuleFilter $ruleFilter = new PrecognitiveRuleFilter,
        private ClientDataKeys $clientDataKeys = new ClientDataKeys,
    ) {}

    /**
     * Other sources aren't validated, so the object cannot be safely built and its guards
     * cannot run. As with FormRequest's validate-only hook (Illuminate\Foundation\Precognition),
     * a passing request gets a 204 and the controller never runs.
     *
     * @param  iterable<ValidationRules>  $sources  the rules of the sources the client posted
     * @param  bool  $bareKeys  key errors by the field the client posted ("email"), not by
     *                          Roma's source-prefixed key ("input.email")
     *
     * @throws RuntimeException the validator factory did not return a Laravel Validator
     * @throws ValidationException
     * @throws HttpException a passing request short-circuits with a 204
     */
    public function validate(Request $request, iterable $sources, bool $bareKeys): never
    {
        $errors = new ValidationErrors;

        foreach ($sources as $rules) {
            $attributeNames = $rules->attributeNames();
            $unnamedKey = $rules->prefixedKey(...);

            if ($bareKeys) {
                $attributeNames = array_map(fn (string $name) => $this->clientDataKeys->stripSourcePrefix($rules->source, $name), $attributeNames);
                $unnamedKey = fn (string $key) => $key;
            }

            $validator = $this->objectMapper->makeValidator($rules, $attributeNames, $unnamedKey);

            // As in FormRequest, filtering runs on the validator's expanded
            // rules — after wildcard expansion against the data — so a client
            // pattern like "items.0.code" can match an array-member rule. That
            // needs setRules() and getRulesWithoutPlaceholders(), which only
            // Laravel's concrete Validator and its subclasses have.
            if (! ($validator instanceof Validator)) {
                throw new RuntimeException(sprintf(
                    'Precognitive validation requires an instance of %s so its rules can be '
                    .'narrowed to the requested fields; the configured validator factory returned %s.',
                    Validator::class,
                    get_debug_type($validator),
                ));
            }

            $validator->setRules(
                $this->ruleFilter->filter($request, $rules->errorPrefix(), $validator->getRulesWithoutPlaceholders()),
            );

            $errors->add($rules, $validator, $attributeNames, $unnamedKey);
        }

        $messages = $errors->toArray();

        if ($messages !== []) {
            throw ValidationException::withMessages($messages);
        }

        abort(204, headers: ['Precognition-Success' => 'true']);
    }
}
