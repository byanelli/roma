<?php

namespace BYanelli\Roma\Request\Attributes;

/**
 * A source attribute (e.g. #[Body], #[Query], #[Input]) that may carry an
 * explicit literal request key. This is how a client field whose name contains
 * a literal dot — impossible to express as a PHP property name — is declared,
 * e.g. #[Body('a.b')] public string $x.
 */
interface ExplicitKeyAttribute
{
    /**
     * The literal request key this property maps to, or null to fall back to
     * the property's own name. The returned key is one opaque segment and may
     * contain a literal dot.
     */
    public function getKey(): ?string;
}
