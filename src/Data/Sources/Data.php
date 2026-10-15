<?php

namespace BYanelli\Roma\Data\Sources;

use BYanelli\Roma\Data\ArrayPayload;
use BYanelli\Roma\Data\Payload;
use BYanelli\Roma\Data\Source;

/**
 * The whole plain array mapped with ArrayPolicy.
 *
 * @internal
 */
final readonly class Data extends Source
{
    public function getKey(): string
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
