<?php

namespace BYanelli\Roma\Data\Attributes;

interface RulesAttribute
{
    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array;
}
