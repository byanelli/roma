<?php

namespace BYanelli\Roma\Request\Validation;

use Illuminate\Http\Request;

/**
 * Narrows a rule set to what a precognitive request should validate.
 * Precognition is a front-end form concern, so only rules for the data a
 * client posts — the input, query, body, and file sources — take part;
 * header, cookie, route-parameter, and request-metadata rules are dropped.
 * When the request names specific fields via Precognition-Validate-Only, the
 * rules are narrowed further to the fields matching those patterns, using the
 * same pattern semantics as Laravel's own filtering
 * (Illuminate\Http\Concerns\CanBePrecognitive).
 */
readonly class PrecognitiveRuleFilter
{
    public function __construct(private ClientDataKeys $clientDataKeys = new ClientDataKeys) {}

    /**
     * @param  array<string, mixed>  $rules  keyed as the validator reports
     *                                       them: wildcards expanded against
     *                                       the data, literal dots escaped as "\."
     * @return array<string, mixed>
     */
    public function filter(Request $request, array $rules): array
    {
        $patterns = $request->headers->has('Precognition-Validate-Only')
            ? explode(',', (string) $request->header('Precognition-Validate-Only'))
            : null;

        return array_filter(
            $rules,
            fn (string|int $key) => $this->included((string) $key, $patterns),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  list<string>|null  $patterns
     */
    private function included(string $key, ?array $patterns): bool
    {
        $plain = str_replace('\\.', '.', $key);

        if (! $this->clientDataKeys->isClientDataKey($plain)) {
            return false;
        }

        if ($patterns === null) {
            return true;
        }

        // A field matches by either name the client might use: the bare
        // posted name ("email"), which the official Precognition front-end
        // helpers send, or Roma's source-prefixed key ("input.email").
        return $this->matchesAnyPattern(
            [$plain, $this->clientDataKeys->stripSourcePrefix($plain)],
            $patterns,
        );
    }

    /**
     * @param  list<string>  $names
     * @param  list<string>  $patterns
     */
    private function matchesAnyPattern(array $names, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            // Laravel's pattern semantics: a literal match, with "*" in the
            // pattern standing for one key segment.
            $regex = '/^'.str_replace('\*', '[^.]+', preg_quote($pattern, '/')).'$/';

            foreach ($names as $name) {
                if (preg_match($regex, $name)) {
                    return true;
                }
            }
        }

        return false;
    }
}
