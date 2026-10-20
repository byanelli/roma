<?php

use BYanelli\Roma\Data\KeyPath;

it('treats a segment with a literal dot as one key', function () {
    $path = new KeyPath(['a.b', 'c']);
    $data = ['a.b' => ['c' => 1], 'a' => ['b' => ['c' => 2]]];

    expect($path->existsIn($data))->toBeTrue()
        ->and($path->getFrom($data))->toBe(1)
        ->and($path->toKey())->toBe('a.b.c')
        ->and($path->toRuleKey())->toBe('a\.b.c');
});

it('creates the arrays along a path it sets', function () {
    $data = ['a' => 'scalar'];

    new KeyPath(['a', 'b'])->setIn($data, 1);
    new KeyPath(['x', 'y.z'])->setIn($data, 2);

    expect($data)->toBe(['a' => ['b' => 1], 'x' => ['y.z' => 2]]);
});

it('returns the default for a missing key', function () {
    expect(new KeyPath(['a', 'b'])->getFrom(['a' => 'scalar'], 'default'))->toBe('default')
        ->and(new KeyPath(['a', 'b'])->existsIn(['a' => ['c' => 1]]))->toBeFalse();
});

it('splits a key on unescaped dots', function () {
    expect(KeyPath::fromDotted('error.message')->segments)->toBe(['error', 'message'])
        ->and(KeyPath::fromDotted('created\.at')->segments)->toBe(['created.at'])
        ->and(KeyPath::fromDotted('hosts.api\.example\.com.token')->segments)->toBe(['hosts', 'api.example.com', 'token'])
        ->and(KeyPath::fromDotted('name')->segments)->toBe(['name'])
        ->and(fn () => KeyPath::fromDotted('a..b'))->toThrow(InvalidArgumentException::class, 'The key "a..b" has an empty segment.')
        ->and(fn () => KeyPath::fromDotted('a.'))->toThrow(InvalidArgumentException::class);
});

it('escapes a literal dot in the rule key of a split key', function () {
    expect(KeyPath::fromDotted('meta.created\.at')->toRuleKey())->toBe('meta.created\.at')
        ->and(KeyPath::fromDotted('meta.created\.at')->toKey())->toBe('meta.created.at');
});

it('finds two paths that cannot both be in one array', function () {
    $paths = fn (array ...$segments) => array_map(fn (array $s) => new KeyPath($s), $segments);

    expect(KeyPath::firstConflict($paths(['error', 'message'], ['error', 'code'], ['created.at'])))->toBeNull()
        ->and(KeyPath::firstConflict($paths(['a'], ['error', 'message'], ['error'])))->toBe([1, 2])
        ->and(KeyPath::firstConflict($paths(['error'], ['error', 'message'])))->toBe([0, 1])
        ->and(KeyPath::firstConflict($paths(['a', 'b'], ['a.b'])))->toBe([0, 1])
        ->and(KeyPath::firstConflict($paths(['a.b'], ['a'])))->toBeNull();
});
