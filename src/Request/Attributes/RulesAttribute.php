<?php

namespace BYanelli\Roma\Request\Attributes;

interface RulesAttribute
{
    /**
     * @return list<mixed>
     */
    public function getRules(AttributeTarget $target): array;
}
