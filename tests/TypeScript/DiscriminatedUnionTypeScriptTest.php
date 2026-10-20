<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Discriminated;
use BYanelli\Roma\Http\Response\Response;
use BYanelli\Roma\Tests\Support\Discriminated\BankAccount;
use BYanelli\Roma\Tests\Support\Discriminated\Card;
use BYanelli\Roma\Tests\Support\Discriminated\Checkout;
use BYanelli\Roma\Tests\Support\Discriminated\PaymentMethod;
use BYanelli\Roma\Tests\Support\Discriminated\Refunds;
use BYanelli\Roma\Tests\Support\Discriminated\Voucher;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

class TsReceiptResponse extends Response
{
    public function __construct(
        public PaymentMethod $payment,
        #[Discriminated([Card::class, Voucher::class])]
        public Card|Voucher|null $refund = null,
    ) {}
}

it('emits a named alias for a union defined on an interface or abstract class', function () {
    $ts = new TypeScriptGenerator([Checkout::class])->generate();

    expect($ts)
        ->toContain("export type PaymentMethod = ({ type: 'Card' } & Card) | ({ type: 'bank' } & BankAccount);")
        ->toContain("export type Shipping = ({ kind: 'Standard' } & Standard) | ({ kind: 'Express' } & Express);")
        ->toContain(<<<'TS'
            export interface CheckoutBody {
              payment: PaymentMethod;
              shipping: Shipping;
              refundTo: ({ type: 'Card' } & Card) | ({ type: 'Voucher' } & Voucher);
              fallbacks?: PaymentMethod[];
              saved?: Record<string, PaymentMethod>;
              backup?: PaymentMethod | null;
              wallet?: Wallet | null;
            }
            TS);
});

it('emits member interfaces without the discriminator key', function () {
    $ts = new TypeScriptGenerator([Checkout::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface Card {
          last4: string;
          saved?: boolean;
        }
        TS)
        ->toContain(<<<'TS'
        export interface BankAccount {
          iban: string;
        }
        TS)
        ->toContain(<<<'TS'
        export interface Express {
          carrier: string;
          surcharge?: PaymentMethod | null;
        }
        TS)
        ->toContain('slots: { primary: PaymentMethod; label?: string };');
});

it('emits a union in a response', function () {
    $ts = new TypeScriptGenerator([], [TsReceiptResponse::class])->generate();

    expect($ts)->toContain(<<<'TS'
        export interface TsReceiptResponseBody {
          payment: PaymentMethod;
          refund: ({ type: 'Card' } & Card) | ({ type: 'Voucher' } & Voucher) | null;
        }
        TS)
        ->toContain('export type PaymentMethod =')
        ->toContain('export interface Voucher {');
});

it('parenthesizes an inline union in an array', function () {
    $ts = new TypeScriptGenerator([Refunds::class])->generate();

    expect($ts)->toContain("refunds: (({ type: 'Card' } & Card) | ({ type: 'Voucher' } & Voucher))[];");
});

it('gives a union shared by a request and a response a response alias when a member differs', function () {
    // Card's `saved` has a default, so it is optional in the request only.
    $ts = new TypeScriptGenerator([Checkout::class], [TsReceiptResponse::class])->generate();

    expect($ts)
        ->toContain("export type PaymentMethod = ({ type: 'Card' } & Card) | ({ type: 'bank' } & BankAccount);")
        ->toContain("export type PaymentMethodResponse = ({ type: 'Card' } & CardResponse) | ({ type: 'bank' } & BankAccount);")
        ->toContain(<<<'TS'
            export interface CardResponse {
              last4: string;
              saved: boolean;
            }
            TS)
        ->toContain(<<<'TS'
            export interface TsReceiptResponseBody {
              payment: PaymentMethodResponse;
              refund: ({ type: 'Card' } & CardResponse) | ({ type: 'Voucher' } & Voucher) | null;
            }
            TS)
        ->and(substr_count($ts, 'export type PaymentMethod ='))->toBe(1)
        ->and(substr_count($ts, 'export interface '.class_basename(BankAccount::class).' {'))->toBe(1)
        ->and($ts)->not->toContain('BankAccountResponse')
        ->not->toContain('VoucherResponse');
});

class TsUndiscriminatedResponse extends Response
{
    public function __construct(public Card|Voucher $refund) {}
}

it('refuses to generate TypeScript for a native union without #[Discriminated]', function () {
    expect(fn () => new TypeScriptGenerator([], [TsUndiscriminatedResponse::class])->generate())
        ->toThrow(RuntimeException::class, 'is typed as the union');
});
