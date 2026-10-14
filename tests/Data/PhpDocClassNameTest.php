<?php

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\ArrayPolicy;
use BYanelli\Roma\Data\ObjectMapper;
use BYanelli\Roma\Data\UnresolvableClassName;
use BYanelli\Roma\Facades\Serializer;
use BYanelli\Roma\Tests\Support\Discriminated\BankAccount;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\Tests\Support\Imports\AliasedRequest;
use BYanelli\Roma\Tests\Support\Imports\Card as LocalCard;
use BYanelli\Roma\Tests\Support\Imports\ChildRequest;
use BYanelli\Roma\Tests\Support\Imports\FullyQualifiedRequest;
use BYanelli\Roma\Tests\Support\Imports\GroupImportRequest;
use BYanelli\Roma\Tests\Support\Imports\ImportedListRequest;
use BYanelli\Roma\Tests\Support\Imports\ImportedResponse;
use BYanelli\Roma\Tests\Support\Imports\ImportedShapeRequest;
use BYanelli\Roma\Tests\Support\Imports\ImportedUnionRequest;
use BYanelli\Roma\Tests\Support\Imports\MissingImportRequest;
use BYanelli\Roma\Tests\Support\Imports\PartiallyQualifiedRequest;
use BYanelli\Roma\Tests\Support\Imports\SelfTree;
use BYanelli\Roma\Tests\Support\Imports\TraitRequest;
use BYanelli\Roma\Tests\Support\Nested\FqcnListRequest;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

function mapPhpDocNames(string $class, array $data): mixed
{
    return app(ObjectMapper::class)->map($class, new ArrayPayload($data), new ArrayPolicy);
}

it('resolves an imported class name', function () {
    expect(mapPhpDocNames(ImportedListRequest::class, ['cards' => [['last4' => '4242']]])->cards)
        ->toEqual([new Card('4242')]);
});

it('prefers an import to a class of the same short name in the declaring namespace', function () {
    expect(class_exists(LocalCard::class))->toBeTrue()
        ->and(mapPhpDocNames(ImportedListRequest::class, ['cards' => [['last4' => '4242']]])->cards[0])
        ->toBeInstanceOf(Card::class);
});

it('resolves an aliased import', function () {
    expect(mapPhpDocNames(AliasedRequest::class, ['cards' => [['last4' => '4242']]])->cards)
        ->toEqual([new Card('4242')]);
});

it('resolves a group import and skips function and constant imports and other namespace blocks', function () {
    $request = mapPhpDocNames(GroupImportRequest::class, ['cards' => [['last4' => '4242']], 'coupons' => [['code' => 'A']]]);

    expect($request->cards)->toEqual([new Card('4242')])
        ->and($request->coupons)->toEqual([new Voucher('A')]);
});

it('resolves a partially qualified name through a namespace import', function () {
    expect(mapPhpDocNames(PartiallyQualifiedRequest::class, ['cards' => [['last4' => '4242']]])->cards)
        ->toEqual([new Card('4242')]);
});

it('resolves a fully qualified name that an import of the same short name would shadow', function () {
    expect(mapPhpDocNames(FullyQualifiedRequest::class, ['cards' => [['last4' => '4242']]])->cards)
        ->toEqual([new Card('4242')]);
});

it('resolves names in a trait through the trait\'s imports', function () {
    $request = mapPhpDocNames(TraitRequest::class, ['cards' => [['last4' => '4242']], 'coupons' => [['code' => 'A']]]);

    expect($request->cards)->toEqual([new Card('4242')])
        ->and($request->coupons)->toEqual([new Voucher('A')]);
});

it('resolves names in a parent class through the parent\'s imports', function () {
    $request = mapPhpDocNames(ChildRequest::class, ['cards' => [['last4' => '4242']], 'coupons' => [['code' => 'A']]]);

    expect($request->cards)->toEqual([new Card('4242')])
        ->and($request->coupons)->toEqual([new Voucher('A')]);
});

it('resolves imported names in a union', function () {
    $request = mapPhpDocNames(ImportedUnionRequest::class, ['methods' => [
        ['type' => 'Card', 'last4' => '4242'],
        ['type' => 'Voucher', 'code' => 'A'],
    ]]);

    expect($request->methods)->toEqual([new Card('4242'), new Voucher('A')]);
});

it('resolves imported names in shapes and maps', function () {
    $request = mapPhpDocNames(ImportedShapeRequest::class, [
        'checkout' => ['card' => ['last4' => '4242'], 'vouchers' => [['code' => 'A']]],
        'saved' => ['work' => ['last4' => '1111']],
    ]);

    expect($request->checkout)->toEqual(['card' => new Card('4242'), 'vouchers' => [new Voucher('A')]])
        ->and($request->saved)->toEqual(['work' => new Card('1111')]);
});

it('resolves a qualified name without a leading backslash as written', function () {
    expect(mapPhpDocNames(FqcnListRequest::class, ['items' => [['name' => 'a']]])->items)->toHaveCount(1);
});

it('resolves self and an imported global class', function () {
    $tree = mapPhpDocNames(SelfTree::class, ['name' => 'a', 'children' => [['name' => 'b', 'dates' => ['2025-01-02']]]]);

    expect($tree->children[0])->toBeInstanceOf(SelfTree::class)
        ->and($tree->children[0]->dates[0])->toEqual(new DateTimeImmutable('2025-01-02'));
});

it('rejects a class name that resolves to no class', function () {
    expect(fn () => mapPhpDocNames(MissingImportRequest::class, ['invoices' => []]))
        ->toThrow(UnresolvableClassName::class, 'Roma cannot read the PHPDoc type list<Invoice> of '
            .'BYanelli\Roma\Tests\Support\Imports\MissingImportRequest::$invoices: it contains the class name Invoice; '
            .'tried App\Billing\Invoice and Invoice, and neither exists.');
});

it('rejects an unresolvable class name when generating TypeScript', function () {
    expect(fn () => new TypeScriptGenerator([], [MissingImportRequest::class])->generate())
        ->toThrow(UnresolvableClassName::class, 'MissingImportRequest::$invoices');
});

it('writes the discriminator for an imported discriminated interface when serializing', function () {
    expect(Serializer::toArray(new ImportedResponse([new Card('4242'), new BankAccount('DE89')])))->toBe(['methods' => [
        ['type' => 'Card', 'last4' => '4242', 'saved' => false],
        ['type' => 'bank', 'iban' => 'DE89'],
    ]]);
});

it('emits an imported discriminated interface in TypeScript', function () {
    expect(new TypeScriptGenerator([], [ImportedResponse::class])->generate())
        ->toContain('methods: PaymentMethod[];');
});
