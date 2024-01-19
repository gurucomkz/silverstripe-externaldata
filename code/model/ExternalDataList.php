<?php
namespace Gurucomkz\ExternalData\Model;

use ArrayIterator;
use Exception;
use Gurucomkz\ExternalData\Interfaces\AbstractDataQuery;
use InvalidArgumentException;
use LogicException;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\Filterable;
use SilverStripe\ORM\Limitable;
use SilverStripe\ORM\Map;
use SilverStripe\ORM\Sortable;
use SilverStripe\ORM\SS_List;
use SilverStripe\View\ViewableData;
use Traversable;

abstract class ExternalDataList extends ViewableData implements SS_List, Filterable, Sortable, Limitable
{
    /**
     * The DataObject class name that this data list is querying
     *
     * @var string
     */
    protected $dataClass;

    /** @var AbstractDataQuery */
    protected $dataQuery;


    /**
     * @var bool - Indicates if we are in an alterDataQueryCall already, so alterDataQuery can be re-entrant
     */
    protected $inAlterDataQueryCall = false;

    /**
     * Create a new DataList.
     * No querying is done on construction, but the initial query schema is set up.
     *
     * @param string $dataClass - The DataObject class to query.
     */
    public function __construct($dataClass)
    {
        $this->dataClass = $dataClass;

        parent::__construct();
    }

    public function dataClass()
    {
        return $this->dataClass;
    }

    abstract public function min($field);
    abstract public function max($field);
    abstract public function sum($field);

    public function filter(): ExternalDataList
    {
        // Validate and process arguments
        $arguments = func_get_args();
        switch (sizeof($arguments ?? [])) {
            case 1:
                $filters = $arguments[0];

                break;
            case 2:
                $filters = [$arguments[0] => $arguments[1]];

                break;
            default:
                throw new InvalidArgumentException('Incorrect number of arguments passed to filter()');
        }

        return $this->addFilter($filters);
    }

    abstract public function filterAny(...$args): ExternalDataList;
    abstract public function filterAnyMulti(...$args): ExternalDataList;

    abstract public function addFilter($filterArray): ExternalDataList;

    /**
     * Note that, in the current implementation, the filtered list will be an ArrayList, but this may change in a
     * future implementation.
     * @see Filterable::filterByCallback()
     *
     * @example $list = $list->filterByCallback(function($item, $list) { return $item->Age == 9; })
     * @param callable $callback
     * @return ArrayList (this may change in future implementations)
     */
    public function filterByCallback($callback)
    {
        if (!is_callable($callback)) {
            throw new LogicException(sprintf(
                "SS_Filterable::filterByCallback() passed callback must be callable, '%s' given",
                gettype($callback)
            ));
        }
        /** @var ArrayList $output */
        $output = ArrayList::create();
        foreach ($this as $item) {
            if (call_user_func($callback, $item, $this)) {
                $output->push($item);
            }
        }
        return $output;
    }

    /**
     * Return a new DataList instance with the underlying {@link DataQuery} object altered
     *
     * If you want to alter the underlying dataQuery for this list, this wrapper method
     * will ensure that you can do so without mutating the existing List object.
     *
     * It clones this list, calls the passed callback function with the dataQuery of the new
     * list as it's first parameter (and the list as it's second), then returns the list
     *
     * Note that this function is re-entrant - it's safe to call this inside a callback passed to
     * alterDataQuery
     *
     * @param callable $callback
     * @return static
     * @throws Exception
     */
    public function alterDataQuery($callback)
    {
        if ($this->inAlterDataQueryCall) {
            $list = $this;

            $res = call_user_func($callback, $list->dataQuery, $list);
            if ($res) {
                $list->dataQuery = $res;
            }

            return $list;
        }

        $list = clone $this;
        $list->inAlterDataQueryCall = true;

        try {
            $res = $callback($list->dataQuery, $list);
            if ($res) {
                $list->dataQuery = $res;
            }
        } catch (Exception $e) {
            $list->inAlterDataQueryCall = false;
            throw $e;
        }

        $list->inAlterDataQueryCall = false;
        return $list;
    }

    public function limit($limit, $offset = 0)
    {
        return $this->alterDataQuery(function (AbstractDataQuery $query) use ($limit, $offset) {
            $query->limit($limit, $offset);
        });
    }

    abstract public function exclude(): ExternalDataList;

    public function sort(): ExternalDataList
    {
        $count = func_num_args();

        if ($count == 0) {
            return $this;
        }

        if ($count > 2) {
            throw new InvalidArgumentException('This method takes zero, one or two arguments');
        }

        if ($count == 2) {
            $col = null;
            $dir = null;
            list($col, $dir) = func_get_args();

            // Validate direction
            if (!in_array(strtolower($dir ?? ''), ['desc', 'asc'])) {
                user_error('Second argument to sort must be either ASC or DESC');
            }

            $sort = [$col => $dir];
        } else {
            $sort = func_get_arg(0);
        }

        return $this->alterDataQuery(function (AbstractDataQuery $query, ExternalDataList $list) use ($sort) {

            if (is_string($sort) && $sort) {
                if (false !== stripos($sort ?? '', ' asc') || false !== stripos($sort ?? '', ' desc')) {
                    $query->sort($sort);
                } else {
                    $exprSplit = explode(' ', $sort, 2);
                    $query->sort($exprSplit[0], 'ASC');
                }
            } elseif (is_array($sort)) {
                // sort(array('Name'=>'desc'));
                $query->sort(null, null); // wipe the sort

                foreach ($sort as $column => $direction) {
                    $query->sort($column, $direction, false);
                }
            }
        });
    }

    /**
     * Returns a unique array of a single field value for all items in the list.
     *
     * @param string $colName
     * @return array
     */
    abstract public function columnUnique($colName): array;


    /**
     * Return this list as an array and every object it as an sub array as well
     *
     * @return array
     */
    public function toNestedArray()
    {
        $result = [];

        foreach ($this as $item) {
            $result[] = $item->toMap();
        }

        return $result;
    }

    /**
     * Walks the list using the specified callback
     *
     * @param callable $callback
     * @return $this
     */
    public function each($callback)
    {
        foreach ($this as $row) {
            $callback($row);
        }

        return $this;
    }

    /**
     * Returns a map of this list
     *
     * @param string $keyField - the 'key' field of the result array
     * @param string $titleField - the value field of the result array
     * @return Map
     */
    public function map($keyField = 'ID', $titleField = 'Title')
    {
        return new Map($this, $keyField, $titleField);
    }

    /**
     * Returns an Iterator for this DataList.
     * This function allows you to use DataLists in foreach loops
     *
     * @return ArrayIterator
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    public function add($item)
    {
        // Nothing needs to happen by default
        // TO DO: If a filter is given to this data list then
    }

    /**
     * Remove this item by deleting it
     *
     * @param ExternalDataObject $item
     * @todo Allow for amendment of this behaviour - for example, we can remove an item from
     * an "ActiveItems" DataList by changing the status to inactive.
     */
    public function remove($item)
    {
        // By default, we remove an item from a DataList by deleting it.
        $this->removeByID($item->ID);
    }

    /**
     * Remove an item from this DataList by ID
     *
     * @param int $itemID The primary ID
     */
    public function removeByID($itemID)
    {
        $item = $this->byID($itemID);

        if ($item) {
            $item->delete();
        }
    }


    /**
     * Returns whether an item with $key exists
     *
     * @param mixed $key
     * @return bool
     */
    public function offsetExists($key): bool
    {
        return ($this->limit(1, $key)->first() != null);
    }

    /**
     * Returns item stored in list with index $key
     *
     * The object returned is not cached, unlike {@link DataObject::get_one()}
     *
     * @param mixed $key
     * @return ?ExternalDataObject
     */
    public function offsetGet($key): mixed
    {
        return $this->limit(1, $key)->first();
    }

    public function offsetSet($key, $value): void
    {
        throw new \BadMethodCallException("Can't alter items in a DataList using array-access");
    }

    public function offsetUnset($key): void
    {
        throw new \BadMethodCallException("Can't alter items in a DataList using array-access");
    }
}
