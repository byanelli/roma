<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\Payload;
use BYanelli\Roma\Data\RootSource;

/**
 * The single root of a plain array mapped with ArrayPolicy.
 *
 * @internal
 */
final readonly class Data extends RootSource
{
    public function getOwnKey(): string
    {
        return 'data';
    }

    public function payloadType(): string
    {
        return ArrayPayload::class;
    }

    /**
     * @param  ArrayPayload  $payload
     * @return array<array-key, mixed>
     */
    protected function read(Payload $payload): array
    {
        return $payload->data;
    }
}
