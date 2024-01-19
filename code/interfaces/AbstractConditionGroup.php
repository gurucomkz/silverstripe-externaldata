<?php
namespace Gurucomkz\ExternalData\Interfaces;

interface AbstractConditionGroup extends \ArrayAccess
{
    public function disjunctiveGroup(): AbstractConditionGroup;
    public function conjunctiveGroup(): AbstractConditionGroup;
    public function toArray(): array;
}
