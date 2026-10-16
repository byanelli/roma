<?php

namespace BYanelli\Roma\Tests\PHPStan;

use BYanelli\Roma\Data\Source;
use BYanelli\Roma\Http\Request\Sources\RequestSource;
use Illuminate\Http\Request;

use function PHPStan\Testing\assertType;

// Checked by `composer analyse`, never run. An override of read() with no @param of its own
// gets the input class from the @extends template.

final readonly class TenantSource extends RequestSource
{
    public function getKey(): string
    {
        return 'tenant';
    }

    protected function read(object $input): mixed
    {
        assertType(Request::class, $input);

        return $input->attributes->all();
    }
}

/**
 * @extends Source<Request>
 */
final readonly class DirectRequestSource extends Source
{
    public function getKey(): string
    {
        return 'direct';
    }

    public function inputType(): string
    {
        return Request::class;
    }

    protected function read(object $input): mixed
    {
        assertType(Request::class, $input);

        return $input->ip();
    }
}

assertType('class-string<Illuminate\Http\Request>', new TenantSource()->inputType());
