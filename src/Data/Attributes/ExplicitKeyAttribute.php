<?php

namespace BYanelli\Roma\Data\Attributes;

/**
 * An attribute (e.g. #[Body], #[Query], #[Input] or #[Key]) that may carry an
 * explicit literal key. This is how a field whose name contains a literal dot
 * — impossible to express as a PHP property name — is declared, e.g.
 * #[Body('a.b')] public string $x. One without a SourceAttribute sets the key
 * within the property's source, or within a nested object.
 */
interface ExplicitKeyAttribute
{
    /**
     * The literal key this property maps to, or null to fall back to
     * the property's own name. The returned key is one opaque segment and may
     * contain a literal dot.
     */
    public function getKey(): ?string;
}
