<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\UnresolvableClassName;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Imports\ImportedListRequest;
use BYanelli\Roma\Tests\Support\Imports\ImportedResponse;
use BYanelli\Roma\Tests\Support\Imports\ImportedUnionRequest;
use BYanelli\Roma\Tests\Support\Imports\SelfTree;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

function mapPhpDocNames(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

// Defined with eval because Pint would import the class and shorten the docblock name.
function defineFullyQualified(string $class, string $type): string
{
    eval("namespace BYanelli\\Roma\\Tests\\Support\\Imports; readonly class {$class} { /** @var {$type} */ public array \$cards; }");

    return "BYanelli\\Roma\\Tests\\Support\\Imports\\{$class}";
}

it('rejects an imported class name in PHPDoc', function () {
    expect(fn () => mapPhpDocNames(ImportedListRequest::class, ['cards' => []]))
        ->toThrow(UnresolvableClassName::class, 'Roma cannot read the PHPDoc type list<Card> of '
            .'BYanelli\Roma\Tests\Support\Imports\ImportedListRequest::$cards: it contains the class name Card, which is '
            .'not fully qualified and is not in the namespace BYanelli\Roma\Tests\Support\Imports. Use imports are not '
            .'read, so write the fully qualified name, such as \Vendor\Package\Card.');
});

it('explains how to keep Pint from shortening the name', function () {
    expect(fn () => mapPhpDocNames(ImportedListRequest::class, ['cards' => []]))
        ->toThrow(UnresolvableClassName::class, 'set the fixer\'s "phpdoc_tags" option to [] in pint.json');
});

it('rejects an imported class name in a PHPDoc union', function () {
    expect(fn () => mapPhpDocNames(ImportedUnionRequest::class, ['methods' => []]))
        ->toThrow(UnresolvableClassName::class, 'the PHPDoc type list<(Card | Voucher)> of '
            .'BYanelli\Roma\Tests\Support\Imports\ImportedUnionRequest::$methods: it contains the class name Card,');
});

it('rejects an imported class name when generating TypeScript', function () {
    expect(fn () => new TypeScriptGenerator([], [ImportedResponse::class])->generate())
        ->toThrow(UnresolvableClassName::class, 'ImportedResponse::$cards');
});

it('rejects an imported class name when serializing', function () {
    expect(fn () => Serializer::toArray(new ImportedResponse([new Card('4242')])))
        ->toThrow(UnresolvableClassName::class, 'ImportedResponse::$cards');
});

it('accepts a fully qualified class name', function () {
    $class = defineFullyQualified('FullyQualifiedCards', 'list<\BYanelli\Roma\Tests\Support\Discriminated\Card>');

    expect(mapPhpDocNames($class, ['cards' => [['last4' => '4242']]])->cards)->toEqual([new Card('4242')]);
});

it('rejects a fully qualified class name that does not exist', function () {
    $class = defineFullyQualified('MissingCards', 'list<\App\Billing\Card>');

    expect(fn () => mapPhpDocNames($class, ['cards' => []]))
        ->toThrow(UnresolvableClassName::class, 'it contains the class \App\Billing\Card, which does not exist.');
});

it('resolves self and a global class that exists as written', function () {
    $tree = mapPhpDocNames(SelfTree::class, ['name' => 'a', 'children' => [['name' => 'b', 'dates' => ['2025-01-02']]]]);

    expect($tree->children[0])->toBeInstanceOf(SelfTree::class)
        ->and($tree->children[0]->dates[0])->toEqual(new DateTimeImmutable('2025-01-02'));
});
