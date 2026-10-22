<?php

/** @noinspection PhpIllegalPsrClassPathInspection */

use BYanelli\Roma\Attributes\Description;
use BYanelli\Roma\TypeScript\TypeScriptGenerator;

#[Description('Where a parcel goes.')]
readonly class TsDescribedAddress
{
    public function __construct(
        #[Description('The street, without the unit.')]
        public string $street,
    ) {}
}

#[Description("Ships an order.\nCharged on dispatch.")]
readonly class TsDescribedRequest
{
    public function __construct(
        #[Description("Line one.\n\nUses */ safely.")]
        public string $note,
        public TsDescribedAddress $address,
    ) {}
}

it('emits #[Description] as TSDoc comments on interfaces and properties', function () {
    $typescript = new TypeScriptGenerator([TsDescribedRequest::class])->generate();

    expect($typescript)->toContain(<<<'TS'
        /**
         * Ships an order.
         * Charged on dispatch.
         */
        export interface TsDescribedRequestBody {
          /**
           * Line one.
           *
           * Uses *\/ safely.
           */
          note: string;
          address: TsDescribedAddress;
        }
        TS)->toContain(<<<'TS'
        /** Where a parcel goes. */
        export interface TsDescribedAddress {
          /** The street, without the unit. */
          street: string;
        }
        TS);
});
