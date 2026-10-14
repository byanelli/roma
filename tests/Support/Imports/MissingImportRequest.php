<?php

namespace BYanelli\Roma\Tests\Support\Imports;

use App\Billing\Invoice;

readonly class MissingImportRequest
{
    /** @var list<Invoice> */
    public array $invoices;
}
