<?php

namespace BYanelli\Roma\Http\Request\Sources;

use BYanelli\Roma\Data\Source;
use Illuminate\Http\Request;

/**
 * A source that reads part of an HTTP request.
 *
 * @extends Source<Request>
 */
abstract readonly class RequestSource extends Source
{
    public function inputType(): string
    {
        return Request::class;
    }

    /**
     * The JSON or form body.
     *
     * @return array<array-key, mixed>
     */
    protected function body(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    /**
     * The keys of the body, without the `_method` and `_token` fields of Laravel's forms.
     *
     * @return list<array-key>
     */
    protected function bodyKeys(Request $request): array
    {
        return array_values(array_diff(array_keys($this->body($request)), ['_method', '_token']));
    }
}
