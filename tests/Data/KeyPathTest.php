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
