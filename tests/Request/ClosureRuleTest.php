<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class MaxLengthProvider
{
    public function __construct(public int $max) {}
}

readonly class ClosureRuleRequest
{
    public function __construct(
        #[Rule('string', self::maxRule(...))]
        public string $name = '',
    ) {}

    // First-class-callable reference resolved through the container at
    // validation time, so it can inject dependencies.
    public static function maxRule(MaxLengthProvider $provider): string
    {
        return "max:{$provider->max}";
    }
}

it('resolves a closure rule through the container and enforces it', function () {
    /** @var TestCase $this */
    $this->app->instance(MaxLengthProvider::class, new MaxLengthProvider(3));

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['name' => 'abcd']);

    try {
        $this->mapRequest(ClosureRuleRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.name')
            ->and($e->errors()['input.name'][0])->toContain('must not be greater than 3');
    }
});

it('passes when the closure rule is satisfied', function () {
    /** @var TestCase $this */
    $this->app->instance(MaxLengthProvider::class, new MaxLengthProvider(3));

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['name' => 'ab']);

    expect($this->mapRequest(ClosureRuleRequest::class)->name)->toBe('ab');
});

readonly class SpreadClosureRuleRequest
{
    public function __construct(
        #[Rule(self::rules(...))]
        public string $value = '',
    ) {}

    /** @return list<string> a closure may return a list of rules, spread in place */
    public static function rules(): array
    {
        return ['string', 'in:red,green'];
    }
}

it('spreads a list returned from a closure rule', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['value' => 'blue']);

    try {
        $this->mapRequest(SpreadClosureRuleRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('input.value');
    }

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['value' => 'red']);
    expect($this->mapRequest(SpreadClosureRuleRequest::class)->value)->toBe('red');
});
