<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Guard;
use BYanelli\Roma\Request\Attributes\Rule;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

class GuardLog
{
    /** @var list<string> */
    public array $calls = [];
}

readonly class GuardedRequest
{
    public function __construct(
        public string $name = 'x',
    ) {}

    #[Guard]
    public function first(GuardLog $log): void
    {
        $log->calls[] = 'first';
    }

    #[Guard]
    public function second(GuardLog $log): void
    {
        $log->calls[] = 'second';
    }
}

it('runs guards after validation, in declaration order, with container injection', function () {
    /** @var TestCase $this */
    $this->app->instance(GuardLog::class, $log = new GuardLog);

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['name' => 'x']);

    $this->mapRequest(GuardedRequest::class);

    expect($log->calls)->toBe(['first', 'second']);
});

readonly class RejectingGuardRequest
{
    public function __construct(
        public string $name = 'x',
    ) {}

    #[Guard]
    public function reject(): void
    {
        throw ValidationException::withMessages(['name' => 'rejected by guard']);
    }
}

it('lets a guard reject the request by throwing', function () {
    /** @var TestCase $this */
    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['name' => 'x']);

    try {
        $this->mapRequest(RejectingGuardRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('name');
    }
});

readonly class ValidatedGuardRequest
{
    public function __construct(
        #[Rule('email')]
        public string $email,
    ) {}

    #[Guard]
    public function afterValidation(GuardLog $log): void
    {
        $log->calls[] = 'ran';
    }
}

it('does not run guards when validation fails', function () {
    /** @var TestCase $this */
    $this->app->instance(GuardLog::class, $log = new GuardLog);

    $this->setRequest(headers: ['Content-Type' => 'application/json'], json: ['email' => 'not-an-email']);

    try {
        $this->mapRequest(ValidatedGuardRequest::class);
        $this->fail('Expected ValidationException');
    } catch (ValidationException) {
        // expected
    }

    expect($log->calls)->toBe([]);
});
