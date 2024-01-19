<?php
namespace Gurucomkz\ExternalData\Interfaces;

interface AbstractDataQuery extends \ArrayAccess
{
    public function execute();
    public function count(): int;
    public function sum($field);
    public function avg($field);
    public function min($field);
    public function max($field);
    // public function where();
    // public function whereAny();
    public function getQueryParams();
    public function disjunctiveGroup(): AbstractConditionGroup;
    public function conjunctiveGroup(): AbstractConditionGroup;
    public function setQueryParam($key, $value);
    public function getQueryParam($key);
    public function reverseSort();
    public function limit($limit, $offset = 0);
    public function column($name, $distinct = false);
    public function sort($sort = null, $direction = null, $clear = true);
}
