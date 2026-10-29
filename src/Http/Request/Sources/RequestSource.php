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
    protected static function body(Request $request): array
    {
        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    /**
     * The body without the `_method` and `_token` fields of Laravel's forms.
     *
     * @return array<array-key, mixed>
     */
    public static function bodyFields(Request $request): array
    {
        return array_diff_key(static::body($request), array_flip(['_method', '_token']));
    }

    /**
     * The keys of bodyFields().
     *
     * @return list<array-key>
     */
    protected function bodyKeys(Request $request): array
    {
        return array_keys(static::bodyFields($request));
    }
}
