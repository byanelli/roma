<?php

namespace BYanelli\Roma\Data\Attributes;

interface ErrorKeyAttribute
{
    /**
     * The source-prefixed name shown for this field in validation
     * errors, e.g. "header.X-Flag" or "request.ajax". Distinct from the
     * internal lookup key, which is normalized for data access.
     */
    public function getErrorKey(): string;
}
