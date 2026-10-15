<?php

namespace BYanelli\Roma\Http\Request\Precognition;

use Illuminate\Http\Request;

/**
 * Narrows the rules of one form-data source to the fields a precognitive request names in
 * Precognition-Validate-Only, using the same pattern semantics as Laravel's own filtering
 * (Illuminate\Http\Concerns\CanBePrecognitive). Without the header every rule is kept.
 */
readonly class PrecognitiveRuleFilter
{
    /**
     * @param  string  $prefix  the source's error prefix, e.g. "input"
     * @param  array<string, mixed>  $rules  keyed relative to the source as the validator
     *                                       reports them: wildcards expanded against the
     *                                       data, literal dots escaped as "\."
     * @return array<string, mixed>
     */
    public function filter(Request $request, string $prefix, array $rules): array
    {
        if (! $request->headers->has('Precognition-Validate-Only')) {
            return $rules;
        }

        $patterns = explode(',', (string) $request->header('Precognition-Validate-Only'));

        return array_filter(
            $rules,
            function (string|int $key) use ($prefix, $patterns) {
                $plain = str_replace('\\.', '.', (string) $key);

                // A field matches by either name the client might use: the bare
                // posted name ("email"), which the official Precognition front-end
                // helpers send, or Roma's source-prefixed key ("input.email").
                return $this->matchesAnyPattern([$plain, "{$prefix}.{$plain}"], $patterns);
            },
            ARRAY_FILTER_USE_KEY,
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
