<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Request\Attributes\Body;
use BYanelli\Roma\Request\Attributes\Header;
use BYanelli\Roma\Request\Attributes\Input;
use BYanelli\Roma\Tests\TestCase;
use Illuminate\Validation\ValidationException;

/*
|--------------------------------------------------------------------------
| A literal "*" in a key is still rejected: Laravel reserves it as the array
| wildcard with no escape hatch, so it could never be validated.
|--------------------------------------------------------------------------
*/

readonly class TestStarKeyRequest
{
    #[Header('X*Weird')]
    public string $weird;
}

it('rejects a request key containing a star', function () {
    /** @var TestCase $this */
    $this->setRequest();

    expect(fn () => $this->mapRequest(TestStarKeyRequest::class))
        ->toThrow(RuntimeException::class, "may not contain '*'");
});

it('does not reject a request key containing a dot', function () {
    /** @var TestCase $this */
    // A dot is now a supported literal key character, so building the class
    // definition must not throw.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['a.b' => 'hello'],
    );

    expect($this->mapRequest(TestDottedBodyKeyRequest::class)->x)->toBe('hello');
});

/*
|--------------------------------------------------------------------------
| A literal dotted key on a top-level property.
|--------------------------------------------------------------------------
*/

readonly class TestDottedBodyKeyRequest
{
    #[Body('a.b')]
    public string $x;
}

it('maps a top-level literal dotted key', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['a.b' => 'hello'],
    );

    $request = $this->mapRequest(TestDottedBodyKeyRequest::class);

    expect($request->x)->toBe('hello');
});

readonly class TestDottedBodyIntKeyRequest
{
    #[Body('a.b')]
    public int $x;
}

it('validates a literal dotted key with the friendly error key on a type error', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['a.b' => 'not-an-int'],
    );

    try {
        $this->mapRequest(TestDottedBodyIntKeyRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'body.a.b' => ['The body.a.b field must be an integer.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

it('validates a literal dotted key with the friendly error key when missing', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['something-else' => 'x'],
    );

    try {
        $this->mapRequest(TestDottedBodyKeyRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'body.a.b' => ['The body.a.b field is required.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

/*
|--------------------------------------------------------------------------
| A literal dotted key on a property inside a nested object.
|--------------------------------------------------------------------------
*/

readonly class TestDottedNestedChild
{
    #[Input('c.d')]
    public string $val;
}

readonly class TestDottedNestedRequest
{
    public TestDottedNestedChild $nested;
}

it('maps a literal dotted key inside a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['nested' => ['c.d' => 'deep']],
    );

    $request = $this->mapRequest(TestDottedNestedRequest::class);

    expect($request->nested->val)->toBe('deep');
});

it('validates a literal dotted key inside a nested object', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['nested' => ['not-it' => 'x']],
    );

    try {
        $this->mapRequest(TestDottedNestedRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'input.nested.c.d' => ['The input.nested.c.d field is required.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

/*
|--------------------------------------------------------------------------
| Regressions: ordinary structural nesting and array wildcards are unchanged
| by segment-based access, and unmapped odd keys are still ignored.
|--------------------------------------------------------------------------
*/

readonly class TestPlainNestedChild
{
    public string $city;
}

readonly class TestPlainNestedRequest
{
    public TestPlainNestedChild $address;

    /** @var array<int> */
    public array $items;
}

it('still treats structural dots as nesting and validates array wildcards', function () {
    /** @var TestCase $this */
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['address' => ['city' => 5], 'items' => ['a', 'b']],
    );

    try {
        $this->mapRequest(TestPlainNestedRequest::class);
    } catch (ValidationException $e) {
        expect($e->errors())->toBe([
            'input.address.city' => ['The input.address.city field must be a string.'],
            'input.items.0' => ['The input.items.* field must be an integer.'],
            'input.items.1' => ['The input.items.* field must be an integer.'],
        ]);

        return;
    }

    $this->fail('Exception was not thrown');
});

readonly class TestPlainKeyRequest
{
    public string $name;
}

it('leaves dotted/starred keys in the client data alone (they are just unmapped)', function () {
    /** @var TestCase $this */
    // A client may send arbitrary field names; ones we don't map are ignored
    // and must not disturb a normal property or crash the build.
    $this->setRequest(
        headers: ['Content-Type' => 'application/json'],
        json: ['name' => 'Bill', 'weird.key' => 'junk', 'a*b' => 'z'],
    );

    $request = $this->mapRequest(TestPlainKeyRequest::class);

    expect($request->name)->toBe('Bill');
});
