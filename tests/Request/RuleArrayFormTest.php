<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

readonly class MultiRuleRequest
{
    public function __construct(
        // An array is a list of independent rules. Each must reach the validator
        // on its own so that a parameterized rule like "url:http,https" keeps its
        // parameters rather than being mistaken for a rule literally named
        // "url:http,https".
        #[Rule(['url:http,https', 'max:255'])]
        public string $url,
    ) {}
}

it('applies every rule in an array-form #[Rule]', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['url' => str_repeat('a', 300)],
    );

    try {
        $this->mapRequest(MultiRuleRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        // Both the "url:http,https" and "max:255" rules must be in force: the
        // value is neither a valid http(s) URL nor within the length limit.
        expect($e->errors()['input.url'] ?? [])
            ->toContain('The input.url field must be a valid URL.')
            ->toContain('The input.url field must not be greater than 255 characters.');
    }
});

it('passes a value that satisfies an array-form #[Rule]', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['url' => 'https://example.com/path'],
    );

    $request = $this->mapRequest(MultiRuleRequest::class);

    expect($request->url)->toBe('https://example.com/path');
});
