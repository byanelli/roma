<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Attributes\RulePerItem;
use BYanelli\Roma\Data\Attributes\AttributeTarget;
use BYanelli\Roma\Data\MappingValidationException;
use BYanelli\Roma\Facades\Mapper;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\In;

final class PipeRuleNotAdmin implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === 'admin') {
            $fail('The :attribute field is reserved.');
        }
    }
}

readonly class PipeRuleSettings
{
    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        #[Rule('string|max:10')]
        public string $name = 'abc',

        #[RulePerItem('email|max:50')]
        public array $emails = [],

        #[Rule('regex:/^(a|b)$/')]
        public string $choice = 'a',

        #[Rule('string|max:3', 'regex:/^(a|b)+$/')]
        public string $letters = 'ab',

        #[Rule('not_regex:/^(x|y)$/')]
        public string $other = 'a',
    ) {}
}

readonly class PipeRuleHandle
{
    public function __construct(
        #[Rule('required|min:2', new PipeRuleNotAdmin)]
        public string $handle,
    ) {}
}

/**
 * @param  class-string  $class
 * @param  array<string, mixed>  $data
 * @return array<string, list<string>>
 */
function pipeRuleErrors(array $data, string $class = PipeRuleSettings::class): array
{
    try {
        Mapper::fromArray($class, $data);
    } catch (MappingValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('splits a pipe-delimited string into rules', function () {
    expect(new Rule('string|max:10')->getRules(AttributeTarget::Property))->toBe(['string', 'max:10'])
        ->and(new Rule(['email', 'string|max:10'])->getRules(AttributeTarget::Property))->toBe(['email', 'string', 'max:10']);
});

it('drops empty segments', function () {
    expect(new Rule('|string||max:10|', '', ' ')->getRules(AttributeTarget::Property))->toBe(['string', 'max:10']);
});

it('passes rule objects and closures through unchanged', function () {
    $in = new In(['a']);
    $closure = fn () => 'max:3';

    expect(new Rule('string|min:1', $in, $closure)->getRules(AttributeTarget::Property))->toBe(['string', 'min:1', $in, $closure]);
});

it('keeps a string that starts with a regex rule whole', function () {
    expect(new Rule('regex:/^(a|b)$/', 'not_regex:/^(x|y)$/', 'NotRegex:/x|y/')->getRules(AttributeTarget::Property))
        ->toBe(['regex:/^(a|b)$/', 'not_regex:/^(x|y)$/', 'NotRegex:/x|y/']);
});

it('maps values that pass the rules in a pipe-delimited string', function () {
    $settings = Mapper::fromArray(PipeRuleSettings::class, [
        'name' => 'abcdefghij',
        'emails' => ['a@x.test'],
    ]);

    expect($settings->name)->toBe('abcdefghij')
        ->and($settings->emails)->toBe(['a@x.test'])
        ->and(Mapper::fromArray(PipeRuleHandle::class, ['handle' => 'ab'])->handle)->toBe('ab');
});

it('fails values that break the rules in a pipe-delimited string', function () {
    expect(pipeRuleErrors(['name' => 'abcdefghijk']))->toBe([
        'data.name' => ['The data.name field must not be greater than 10 characters.'],
    ]);
});

it('applies a pipe-delimited string with a rule object', function () {
    expect(pipeRuleErrors([], PipeRuleHandle::class))->toBe([
        'data.handle' => ['The data.handle field is required.'],
    ])->and(pipeRuleErrors(['handle' => 'a'], PipeRuleHandle::class))->toBe([
        'data.handle' => ['The data.handle field must be at least 2 characters.'],
    ])->and(pipeRuleErrors(['handle' => 'admin'], PipeRuleHandle::class))->toBe([
        'data.handle' => ['The data.handle field is reserved.'],
    ]);
});

it('splits a pipe-delimited string in #[RulePerItem]', function () {
    expect(pipeRuleErrors(['emails' => ['bad', str_repeat('a', 50).'@x.test']]))->toBe([
        'data.emails.0' => ['The data.emails.0 field must be a valid email address.'],
        'data.emails.1' => ['The data.emails.1 field must not be greater than 50 characters.'],
    ]);
});

it('applies a regex rule whose pattern contains a pipe', function () {
    expect(pipeRuleErrors(['choice' => 'b', 'other' => 'z']))->toBe([])
        ->and(pipeRuleErrors(['choice' => 'c', 'other' => 'x']))->toBe([
            'data.choice' => ['The data.choice field format is invalid.'],
            'data.other' => ['The data.other field format is invalid.'],
        ]);
});

it('applies a regex rule next to a pipe-delimited string', function () {
    expect(pipeRuleErrors(['letters' => 'aba']))->toBe([])
        ->and(pipeRuleErrors(['letters' => 'abc']))->toBe([
            'data.letters' => ['The data.letters field format is invalid.'],
        ])
        ->and(pipeRuleErrors(['letters' => 'abab']))->toBe([
            'data.letters' => ['The data.letters field must not be greater than 3 characters.'],
        ]);
});
