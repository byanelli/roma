<?php

namespace BYanelli\Roma\Data\Attributes;

/**
 * An attribute (e.g. #[Body], #[Query], #[Input] or #[Key]) that may give an explicit key in
 * place of the property name. One without a SourceAttribute sets the key within the
 * property's source, or within a nested object.
 */
interface ExplicitKeyAttribute
{
    /**
     * The key this property maps to, or null to use the property's own name. The source
     * decides whether a dot in it nests (see Source::nestsKeys()).
     */
    public function getKey(): ?string;
}
