<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\IgnoreIfEmpty;
use BYanelli\Roma\Attributes\Message;
use BYanelli\Roma\Attributes\RequiredOnCreate;
use BYanelli\Roma\Attributes\Rule;
use BYanelli\Roma\Facades\Mapper;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Http\Request\Injection\Request as RomaRequest;
use BYanelli\Roma\Missing;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Support\Facades\Route;

readonly class OnCreateLimits
{
    public function __construct(
        #[RequiredOnCreate]
        public int|Missing $daily,
        public int|Missing $monthly,
    ) {}
}

#[RomaRequest]
readonly class OnCreateRequest
{
    public function __construct(
        #[RequiredOnCreate]
        #[Rule('integer', 'min:1')]
        public int|Missing $account_id,
        #[RequiredOnCreate]
        #[Message(required: 'Send a name.')]
        #[Rule('max:5')]
        public string|Missing $name,
        #[RequiredOnCreate]
        public ?string $note,
        #[RequiredOnCreate]
        #[IgnoreIfEmpty]
        public string|Missing $code,
        public OnCreateLimits|Missing|null $limits,
    ) {}
}

readonly class OnCreateAlwaysRequired
{
    public function __construct(
        #[RequiredOnCreate]
        public string $name,
    ) {}
}

beforeEach(function () {
    $serialize = fn (OnCreateRequest $request) => Serializer::toArray($request);

    Route::post('/things', $serialize);
    Route::put('/things', $serialize);
    Route::patch('/things', $serialize);
});

$valid = ['account_id' => 1, 'name' => 'Ada', 'note' => 'n', 'code' => 'x'];

it('requires the properties on a POST', function () {
    /** @var TestCase $this */
    $this->postJson('/things', [])
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => 'The input.account_id field is required. (and 3 more errors)',
            'errors' => [
                'input.account_id' => ['The input.account_id field is required.'],
                'input.name' => ['Send a name.'],
                'input.note' => ['The input.note field is required.'],
                'input.code' => ['The input.code field is required.'],
            ],
        ]);
});

it('maps a POST that sends them', function () use ($valid) {
    /** @var TestCase $this */
    $this->postJson('/things', $valid)->assertOk()->assertExactJson($valid);
});

it('rejects a null on a POST, as Laravel\'s required rule does', function () use ($valid) {
    /** @var TestCase $this */
    $this->postJson('/things', [...$valid, 'note' => null])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.note' => ['The input.note field is required.']]);

    $this->putJson('/things', ['note' => null])->assertOk()->assertExactJson(['note' => null]);
});

it('leaves the properties optional on other methods', function (string $method) {
    /** @var TestCase $this */
    $this->json($method, '/things', ['name' => 'Bo'])->assertOk()->assertExactJson(['name' => 'Bo', 'note' => null]);
})->with(['PUT', 'PATCH']);

it('treats a POST with a method override as the overriding method', function () {
    /** @var TestCase $this */
    $this->postJson('/things', ['name' => 'Bo', '_method' => 'PUT'])->assertOk();
});

it('applies the other rules when the properties are optional', function () {
    /** @var TestCase $this */
    $this->putJson('/things', ['account_id' => 'abc', 'name' => 'Too long'])
        ->assertUnprocessable()
        ->assertJsonPath('errors', [
            'input.account_id' => ['The input.account_id field must be an integer.'],
            'input.name' => ['The input.name field must not be greater than 5 characters.'],
        ]);
});

it('requires a value that #[IgnoreIfEmpty] ignores on a POST', function () use ($valid) {
    /** @var TestCase $this */
    $this->postJson('/things', [...$valid, 'code' => []])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.code' => ['The input.code field is required.']]);

    $this->putJson('/things', ['code' => []])->assertOk()->assertExactJson(['note' => null]);
});

it('requires a nested object\'s property on a POST when the object is present', function () use ($valid) {
    /** @var TestCase $this */
    $this->postJson('/things', [...$valid, 'limits' => ['monthly' => 9]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['input.limits.daily' => ['The input.limits.daily field is required.']]);

    $this->postJson('/things', [...$valid, 'limits' => null])->assertOk();
    $this->putJson('/things', ['limits' => ['monthly' => 9]])->assertOk()->assertExactJson(['note' => null, 'limits' => ['monthly' => 9]]);
});

it('never requires the properties outside a request', function () {
    $request = Mapper::fromArray(OnCreateRequest::class, []);

    expect($request->account_id)->toBe(Missing::instance())
        ->and($request->note)->toBeNull();
});

it('rejects #[RequiredOnCreate] on a property that is always required', function () {
    expect(fn () => Mapper::fromArray(OnCreateAlwaysRequired::class, []))->toThrow(
        RuntimeException::class,
        'Roma property "OnCreateAlwaysRequired::$name" has #[RequiredOnCreate], but it is always required',
    );
});
