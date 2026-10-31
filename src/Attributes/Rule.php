<?php

namespace BYanelli\Roma\Attributes;

use Attribute;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\Attributes\RulesAttribute;
use Illuminate\Support\Str;

#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
class Rule implements RulesAttribute
{
    /**
     * @var list<mixed>
     */
    private array $rules;

    public function __construct(mixed ...$rules)
    {
        $this->rules = array_values($rules);
    }

    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array
    {
        // Each argument is a rule or a pipe-delimited string of rules. An array
        // argument is a list of rules and is spread so each element is passed
        // to the validator as its own rule — wrapping it instead nests it, and
        // Laravel then treats the first element as a parameterless rule name
        // (e.g. "url:http,https" becomes an unknown rule rather than "url" with
        // parameters). A closure argument (a first-class-callable reference) is
        // left intact for the mapper to resolve through the container at
        // validation time.
        return array_values(
            collect($this->rules)
                ->flatMap(fn (mixed $rule): array => is_array($rule) ? array_values($rule) : [$rule])
                ->flatMap(fn (mixed $rule): array => is_string($rule) ? self::split($rule) : [$rule])
                ->all()
        );
    }

    /**
     * Split a pipe-delimited string as Laravel's validator does, dropping empty segments. A
     * string that starts with a regex rule is one rule, since its pattern may contain "|".
     *
     * @return list<string>
     */
    private static function split(string $rules): array
    {
        // The rule names that Laravel's ValidationRuleParser::ruleIsRegex() accepts.
        if (in_array(strtolower(trim(Str::before($rules, ':'))), ['regex', 'not_regex', 'notregex'], true)) {
            return [$rules];
        }

        return array_values(array_filter(explode('|', $rules), fn (string $rule): bool => trim($rule) !== ''));
    }
}
