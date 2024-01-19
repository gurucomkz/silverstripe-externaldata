<?php
namespace Gurucomkz\ExternalData\Model;

use Exception;
use Gurucomkz\ExternalData\Forms\ExternalDataFormScaffolder;
use Gurucomkz\ExternalData\Model\FieldTypes\ExternalDataObjectPrimaryKey;
use InvalidArgumentException;
use LogicException;
use MongoDB\Model\BSONDocument;
use MongoDB\BSON\UTCDateTime;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Resettable;
use SilverStripe\Dev\Deprecation;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\CompositeValidator;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FieldsValidator;
use SilverStripe\Forms\FormField;
use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DataObjectInterface;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBComposite;
use SilverStripe\ORM\FieldType\DBField;
use SilverStripe\ORM\Search\SearchContext;
use SilverStripe\ORM\ValidationException;
use SilverStripe\ORM\ValidationResult;
use SilverStripe\Security\Permission;
use SilverStripe\View\ArrayData;
use SilverStripe\View\ViewableData;

/**
 * ExternalDataObject
 *
 * Use this class to create an object from an external datasource with CRUD options and that will work with the GridField.
 * In this way you can connect to other datasources and manage them with the build in Modeladmin.
 *
 * This is basicly a stripped down DataObject, without being tied to a Database Table,
 * and works more or less the same.
 *
 * Things that work :
 *  Create, Read, Update, Delete
 *  FormScaffolding
 *  FieldCasting
 *  Summary Fields
 *  FieldLabels
 *  Limited canView, canEdit checks
 *
 * Things that don't work:
 *  HasOne, HasMany, ManyMany relations
 *  SearchScaffolding.
 *
 * To provide a flexible way to work with External Data, you have to create your own get(), get_one(), delete()
 * functions in your subclass, since we can not know what kind of data you work with.
 *
 * Important is that you provide a method that set an ID value as an unique identifier
 * This can be any value and is not limited to an integer.
 *
 *
 * @property mixed $ID
 */
abstract class ExternalDataObject extends ArrayData implements DataObjectInterface, Resettable
{
    private static $table_name;

    private static $db = [
        'ID' => 'Varchar'
    ];

    private $changed;
    protected $record;
    protected $class;

    private static $singular_name = null;
    private static $plural_name = null;
    private static $summary_fields = null;

    protected static $_cache_db = [];
    protected static $_cache_get_one;
    protected static $_cache_field_labels = [];
    protected static $_cache_composite_fields = [];


    /**
     * Used by onBeforeDelete() to ensure child classes call parent::onBeforeDelete()
     * @var boolean
     */
    protected $brokenOnDelete = false;

    /**
     * Used by onBeforeWrite() to ensure child classes call parent::onBeforeWrite()
     * @var boolean
     */
    protected $brokenOnWrite = false;

    /**
     * A flag to indicate that a "strict" change of the entire record been forced
     * Use {@link getChangedFields()} and {@link isChanged()} to inspect
     * the changed state.
     *
     * @var boolean
     */
    private $changeForced = false;

    /**
     * The database record (in the same format as $record), before
     * any changes.
     * @var array
     */
    protected $original = [];

    public function __construct($data = [])
    {
        if ($data instanceof BSONDocument) {
            $data = $this->bsonToArray($data);
        }

        foreach ($data as $k => $v) {
            if ($v !== null) {
                $data[$k] = $v;
            } else {
                unset($data[$k]);
            }
        }

        if (!isset($data['ID'])) {
            $data['ID'] = '';
        }

        $this->record = $data;
        $this->class = get_called_class();

        $this->original = $this->record ?? [];

        parent::__construct($data);
    }

    public function bsonToArray(BSONDocument $document): array
    {
        $record = [];

        foreach ($document as $key => $value) {
            switch (true) {
                case $value instanceof BSONDocument:
                    $value = json_encode($value);
                    break;

                case $value instanceof UTCDateTime:
                    $value = $value->toDateTime();
                    break;
            }

            $record[$key] = $value;
        }

        return $record;
    }

    public function hasOne()
    {
        return [];
    }
    public function hasMany()
    {
        return [];
    }
    public function manyMany()
    {
        return [];
    }

    public function defaultSearchFilters()
    {
        return [];
    }

    public function relObject($fieldPath)
    {
        return null;
    }

    public function getGeneralSearchFieldName(): string
    {
        return $this->config()->get('general_search_field_name') ?? '';
    }

    public static function is_composite_field($class, $name, $aggregated = true)
    {

        if (!isset(ExternalDataObject::$_cache_composite_fields[$class])) {
            self::cache_composite_fields($class);
        }

        if (isset(ExternalDataObject::$_cache_composite_fields[$class][$name])) {
            return ExternalDataObject::$_cache_composite_fields[$class][$name];
        } elseif ($aggregated && $class != ExternalDataObject::class && ($parentClass=get_parent_class($class)) != ExternalDataObject::class) {
            return self::is_composite_field($parentClass, $name);
        }
    }

    private static function cache_composite_fields($class)
    {
        $compositeFields = [];

        $fields = Config::inst()->get($class, 'db', Config::UNINHERITED);

        if ($fields) {
            foreach ($fields as $fieldName => $fieldClass) {
                if (!is_string($fieldClass)) {
                    continue;
                }

                        // Strip off any parameters
                $bPos = strpos('(', $fieldClass);
                if ($bPos !== false) {
                    $fieldClass = substr(0, $bPos, $fieldClass);
                }

                        // Test to see if it implements CompositeDBField
                if (ClassInfo::classImplements($fieldClass, CompositeField::class)) {
                    $compositeFields[$fieldName] = $fieldClass;
                }
            }
        }

        ExternalDataObject::$_cache_composite_fields[$class] = $compositeFields;
    }

    public function __get($property)
    {
        if ($this->hasMethod($method = "get$property")) {
            return $this->$method();
        } elseif ($this->hasField($property)) {
            return $this->getField($property);
        } elseif (isset($this->record[$property])) {
            return $this->record[$property];
        }
    }

    public function __set($property, $value)
    {
        if ($this->hasMethod($method = "set$property")) {
            $this->$method($value);
        } else {
            $this->setField($property, $value);
        }
    }

    /**
     * Return all objects matching the filter
     * sub-classes are automatically selected and included
     *
     * @param string $callerClass The class of objects to be returned
     * @param string|array $filter A filter to be inserted into the WHERE clause.
     * Supports parameterised queries. See SQLSelect::addWhere() for syntax examples.
     * @param string|array $sort A sort expression to be inserted into the ORDER
     * BY clause.  If omitted, self::$default_sort will be used.
     * @param string $join Deprecated 3.0 Join clause. Use leftJoin($table, $joinClause) instead.
     * @param string|array $limit A limit expression to be inserted into the LIMIT clause.
     * @param string $containerClass The container class to return the results in.
     *
     * @todo $containerClass is Ignored, why?
     *
     * @return ExternalDataList The objects matching the filter, in the class specified by $containerClass
     */
    public static function get(
        $callerClass = null,
        $filter = "",
        $sort = "",
        $join = "",
        $limit = null,
        $containerClass = ExternalDataList::class
    ) {
        // Validate arguments
        if ($callerClass == null) {
            $callerClass = get_called_class();
            if ($callerClass === self::class) {
                throw new InvalidArgumentException('Call <classname>::get() instead of ExternalDataList::get()');
            }
            if ($filter || $sort || $join || $limit || ($containerClass !== ExternalDataList::class)) {
                throw new InvalidArgumentException('If calling <classname>::get() then you shouldn\'t pass any other'
                    . ' arguments');
            }
        } elseif ($callerClass === self::class) {
            throw new InvalidArgumentException('ExternalDataList::get() cannot query non-subclass ExternalDataList directly');
        }
        if ($join) {
            throw new InvalidArgumentException(
                'The $join argument has been removed.'
            );
        }

        // Build and decorate with args
        $result = static::getDataList($callerClass);
        if ($filter) {
            $result = $result->filter($filter);
        }
        if ($sort) {
            $result = $result->sort($sort);
        }
        if ($limit && strpos($limit ?? '', ',') !== false) {
            $limitArguments = explode(',', $limit ?? '');
            $result = $result->limit($limitArguments[1], $limitArguments[0]);
        } elseif ($limit) {
            $result = $result->limit($limit);
        }

        return $result;
    }

    abstract public static function getDataList(string $callerClass): ExternalDataList;

    public static function get_one($callerClass, $filter = "", $cache = true, $orderby = "")
    {
        /** @var ExternalDataObject $singleton */
        $singleton = singleton($callerClass);

        $cacheComponents = [$filter, $orderby, $singleton->getUniqueKeyComponents()];
        $cacheKey = md5(serialize($cacheComponents));

        $item = null;
        if (!$cache || !isset(self::$_cache_get_one[$callerClass][$cacheKey])) {
            $dl = ExternalDataObject::get($callerClass)->filter($filter)->sort($orderby);
            $item = $dl->first();

            if ($cache) {
                self::$_cache_get_one[$callerClass][$cacheKey] = $item;
                if (!self::$_cache_get_one[$callerClass][$cacheKey]) {
                    self::$_cache_get_one[$callerClass][$cacheKey] = false;
                }
            }
        }

        if ($cache) {
            return self::$_cache_get_one[$callerClass][$cacheKey] ?: null;
        }

        return $item;
    }

    public function getID()
    {
        return $this->record['ID'];
    }

    public function getTitle()
    {
        if ($this->hasField('Title')) {
            return $this->getField('Title');
        }
        if ($this->hasField('Name')) {
            return $this->getField('Name');
        }

        return "#{$this->ID}";
    }

    public function getCMSFields()
    {
        $tabbedFields = $this->scaffoldFormFields([
            // Don't allow has_many/many_many relationship editing before the record is first saved
            'includeRelations' => 0, //($this->ID > 0)
            'tabbed' => false,
            'ajaxSafe' => true
        ]);

        $this->extend('updateCMSFields', $tabbedFields);

        return $tabbedFields;
    }

    public function getFrontEndFields($params = null)
    {
        $untabbedFields = $this->scaffoldFormFields($params);
        $this->extend('updateFrontEndFields', $untabbedFields);

        return $untabbedFields;
    }

    public function scaffoldFormFields($_params = null)
    {
        $params = array_merge(
            [
                'tabbed' => false,
                'includeRelations' => false,
                'restrictFields' => false,
                'fieldClasses' => false,
                'ajaxSafe' => false
            ],
            (array)$_params
        );

        $fs = new ExternalDataFormScaffolder($this);
        $fs->tabbed = $params['tabbed'];
        $fs->includeRelations = $params['includeRelations'];
        $fs->restrictFields = $params['restrictFields'];
        $fs->fieldClasses = $params['fieldClasses'];
        $fs->ajaxSafe = $params['ajaxSafe'];

        return $fs->getFieldList();
    }

    public function db($fieldName = null)
    {
        $classes = class_parents($this) + [$this->class => $this->class];
        $good = false;
        $items = [];

        foreach ($classes as $class) {
            // Wait until after we reach ExternalDataObject
            if (!$good) {
                if ($class == ExternalDataObject::class) {
                    $good = true;
                }
                continue;
            }

            if (isset(self::$_cache_db[$class])) {
                $dbItems = self::$_cache_db[$class];
            } else {
                $dbItems = (array) Config::inst()->get($class, 'db');
                self::$_cache_db[$class] = $dbItems;
            }

            if ($fieldName) {
                if (isset($dbItems[$fieldName])) {
                    return $dbItems[$fieldName];
                }
            } else {
                // Validate the data
                foreach ($dbItems as $k => $v) {
                    if (!is_string($k) || is_numeric($k) || !is_string($v)) {
                        user_error("$class::\$db has a bad entry: "
                        . var_export($k, true) . " => " . var_export($v, true) . ".  Each map key should be a"
                        . " property name, and the map value should be the property type.", E_USER_ERROR);
                    }
                }

                $items = array_merge((array) $items, $dbItems);
            }
        }

        return $items;
    }

    public function dbObject($fieldName)
    {
        $value = isset($this->record[$fieldName])
            ? $this->record[$fieldName]
            : null;

        // If we have a CompositeDBField object in $this->record, then return that
        if (is_object($value)) {
            return $value;
        // Special case for ID field
        } elseif ($fieldName == 'ID') { // sure?
            return new ExternalDataObjectPrimaryKey('ID', $this->ID); // ?Varchar
        // General casting information for items in $db
        } elseif ($spec = $this->db($fieldName)) {
            $obj = Injector::inst()->create($spec, $fieldName);
            $obj->setValue($value, $this, false);
            return $obj;
        }
    }

    public function fieldLabels($includerelations = false)
    {

        $cacheKey = $this->class . '_' . $includerelations;

        if (!isset(self::$_cache_field_labels[$cacheKey])) {
            $customLabels = $this->config()->get('field_labels');
            $autoLabels = [];

            // get all translated static properties as defined in i18nCollectStatics()
            $ancestry = class_parents($this->class) + [$this->class => $this->class];

            $ancestry = array_reverse($ancestry);
            if ($ancestry) {
                foreach ($ancestry as $ancestorClass) {
                    if ($ancestorClass == ViewableData::class) {
                        break;
                    }
                    $types = [
                        'db' => (array)Config::inst()->get($ancestorClass, 'db', Config::UNINHERITED)
                    ];
                    if ($includerelations) {
                        $types['has_one'] = (array)singleton($ancestorClass)->uninherited('has_one', true);
                        $types['has_many'] = (array)singleton($ancestorClass)->uninherited('has_many', true);
                        $types['many_many'] = (array)singleton($ancestorClass)->uninherited('many_many', true);
                    }
                    foreach ($types as $type => $attrs) {
                        foreach ($attrs as $name => $spec) {
                            // var_dump("{$ancestorClass}.{$type}_{$name}");
                            $autoLabels[$name] = _t("{$ancestorClass}.{$type}_{$name}", FormField::name_to_label($name));
                        }
                    }
                }
            }

            $labels = array_merge((array)$autoLabels, (array)$customLabels);
            $this->extend('updateFieldLabels', $labels);
            self::$_cache_field_labels[$cacheKey] = $labels;
        }

        return self::$_cache_field_labels[$cacheKey];
    }

    public function hasField($field)
    {
        return (
            array_key_exists($field, $this->record)
            || $this->db($field)
            || $this->hasMethod("get{$field}")
        );
    }

    public function setField($fieldName, $val)
    {
        // Situation 1: Passing an DBField
        if ($val instanceof DBField) {
            $val->setName($fieldName);
            $val->saveInto($this);

            if ($val instanceof DBComposite) {
                $val->bindTo($this);
            }
            $this->record[$fieldName] = $val;
        // Situation 2: Passing a literal or non-DBField object
        } else {
            // If this is a proper database field, we shouldn't be getting non-DBField objects
            if (is_object($val) && $this->db($fieldName)) {
                user_error('ExternalDataObject::setField: passed an object that is not a DBField', E_USER_WARNING);
            }

            $defaults = $this->config()->get('defaults');
            // if a field is not existing or has strictly changed
            if (!isset($this->record[$fieldName]) || $this->record[$fieldName] !== $val) {
                // TODO Add check for php-level defaults which are not set in the db
                // TODO Add check for hidden input-fields (readonly) which are not set in the db
                // At the very least, the type has changed
                $this->changed[$fieldName] = 1;

                if ((!isset($this->record[$fieldName]) && $val) || (isset($this->record[$fieldName])
                        && $this->record[$fieldName] != $val)) {
                    // Value has changed as well, not just the type
                    $this->changed[$fieldName] = 2;
                }

                // If we've just lazy-loaded the column, then we need to populate the $original array by
                // called getField(). Too much overhead? Could this be done by a quicker method? Maybe only
                // on a call to getChanged()?
                $this->getField($fieldName);

                // Value is always saved back when strict check succeeds.
                $this->record[$fieldName] = $val;
            }
        }
        return $this;
    }

    public function setCastedField($fieldName, $value)
    {
        if (!$fieldName) {
            throw new InvalidArgumentException("ExternalDataObject::setCastedField: Called without a fieldName");
        }
        $fieldObj = $this->dbObject($fieldName);
        if ($fieldObj) {
            $fieldObj->setValue($value);
            $fieldObj->saveInto($this);
        } else {
            $this->$fieldName = $value;
        }
        return $this;
    }

    /**
     * need to be overload by solid dataobject, so that the customised actions of that dataobject,
     * including that dataobject's extensions customised actions could be added to the EditForm.
     *
     * @return FieldList an Empty FieldList(); need to be overload by solid subclass
     */
    public function getCMSActions()
    {
        $actions = new FieldList();
        $this->extend('updateCMSActions', $actions);
        return $actions;
    }

    public function getField($field)
    {
        // If we already have a value in $this->record, then we should just return that
        if (isset($this->record[$field])) {
            return $this->record[$field];
        }

        return isset($this->record[$field]) ? $this->record[$field] : null;
    }

    public function fieldLabel($name)
    {
        $labels = $this->fieldLabels();
        return (isset($labels[$name])) ? $labels[$name] : FormField::name_to_label($name);
    }

    public function singular_name()
    {
        $name = $this->config()->get('singular_name');
        if ($name) {
            return $name;
        }
        return ucwords(trim(strtolower(preg_replace(
            '/_?([A-Z])/',
            ' $1',
            ClassInfo::shortName($this) ?? ''
        ) ?? '')));
    }

    public function i18n_singular_name()
    {
        return _t($this->class . '.SINGULARNAME', $this->singular_name());
    }

    public function plural_name()
    {
        if ($name = $this->config()->get('plural_name')) {
            return $name;
        }
        $name = $this->singular_name();
        //if the penultimate character is not a vowel, replace "y" with "ies"
        if (preg_match('/[^aeiou]y$/i', $name ?? '')) {
            $name = substr($name ?? '', 0, -1) . 'ie';
        }
        return ucfirst($name . 's');
    }

    public function i18n_plural_name()
    {
        $name = $this->plural_name();
        return _t($this->class . '.PLURALNAME', $name);
    }

    private function getUniqueKeyComponents(): array
    {
        return $this->extend('cacheKeyComponent');
    }

    //todo, but set so custom ModelAdmin wont choke...
    public function getDefaultSearchContext()
    {
        return SearchContext::create(
            static::class,
            $this->scaffoldSearchFields(),
            $this->defaultSearchFilters()
        );
    }

    public function scaffoldSearchFields($_params = null)
    {
        $params = array_merge(
            [
                'fieldClasses' => false,
                'restrictFields' => false
            ],
            (array)$_params
        );
        $fields = new FieldList();

        foreach ($this->searchableFields() as $fieldName => $spec) {
            if ($params['restrictFields'] && !in_array($fieldName, $params['restrictFields'] ?? [])) {
                continue;
            }

            // If a custom fieldclass is provided as a string, use it
            $field = null;
            if ($params['fieldClasses'] && isset($params['fieldClasses'][$fieldName])) {
                $fieldClass = $params['fieldClasses'][$fieldName];
                $field = new $fieldClass($fieldName);
            // If we explicitly set a field, then construct that
            } elseif (isset($spec['field'])) {
                // If it's a string, use it as a class name and construct
                if (is_string($spec['field'])) {
                    $fieldClass = $spec['field'];
                    $field = new $fieldClass($fieldName);

                // If it's a FormField object, then just use that object directly.
                } elseif ($spec['field'] instanceof FormField) {
                    $field = $spec['field'];

                // Otherwise we have a bug
                } else {
                    user_error("Bad value for searchable_fields, 'field' value: "
                        . var_export($spec['field'], true), E_USER_WARNING);
                }

            // Otherwise, use the database field's scaffolder
            } elseif ($object = $this->relObject($fieldName)) {
                if (is_object($object) && $object->hasMethod('scaffoldSearchField')) {
                    $field = $object->scaffoldSearchField();
                } else {
                    throw new Exception(sprintf(
                        "SearchField '%s' on '%s' does not return a valid DBField instance.",
                        $fieldName,
                        get_class($this)
                    ));
                }
            }

            // Allow fields to opt out of search
            if (!$field) {
                continue;
            }

            if (strstr($fieldName ?? '', '.')) {
                $field->setName(str_replace('.', '__', $fieldName ?? ''));
            }
            $field->setTitle($spec['title']);

            $fields->push($field);
        }

        // Only include general search if there are fields it can search on
        $generalSearch = $this->getGeneralSearchFieldName();
        if ($generalSearch !== '' && $fields->count() > 0) {
            if ($fields->fieldByName($generalSearch) || $fields->dataFieldByName($generalSearch)) {
                throw new LogicException('General search field name must be unique.');
            }
            $fields->unshift(HiddenField::create($generalSearch, _t(self::class . '.GENERALSEARCH', 'General Search')));
        }

        return $fields;
    }

    /**
     * Get the default searchable fields for this object, as defined in the
     * $searchable_fields list. If searchable fields are not defined on the
     * data object, uses a default selection of summary fields.
     *
     * @return array
     */
    public function searchableFields()
    {
        // can have mixed format, need to make consistent in most verbose form
        $fields = $this->config()->get('searchable_fields');
        $labels = $this->fieldLabels();

        // fallback to summary fields (unless empty array is explicitly specified)
        if (!$fields && !is_array($fields)) {
            $summaryFields = array_keys($this->summaryFields() ?? []);
            $fields = [];

            if ($summaryFields) {
                foreach ($summaryFields as $name) {
                    if ($field = $this->getDatabaseBackedField($name)) {
                        $fields[] = $field;
                    }
                }
            }
        }

        // we need to make sure the format is unified before
        // augmenting fields, so extensions can apply consistent checks
        // but also after augmenting fields, because the extension
        // might use the shorthand notation as well

        // rewrite array, if it is using shorthand syntax
        $rewrite = [];
        foreach ($fields as $name => $specOrName) {
            $identifier = (is_int($name)) ? $specOrName : $name;

            if (is_int($name)) {
                // Format: array('MyFieldName')
                $rewrite[$identifier] = [];
            } elseif (is_array($specOrName) && (isset($specOrName['match_any']))) {
                $rewrite[$identifier] = $fields[$identifier];
                $rewrite[$identifier]['match_any'] = $specOrName['match_any'];
            } elseif (is_array($specOrName) && ($relObject = $this->relObject($identifier))) {
                // Format: array('MyFieldName' => array(
                //   'filter => 'ExactMatchFilter',
                //   'field' => 'NumericField', // optional
                //   'title' => 'My Title', // optional
                // ))
                $rewrite[$identifier] = array_merge(
                    ['filter' => $relObject->config()->get('default_search_filter_class')],
                    (array)$specOrName
                );
            } else {
                // Format: array('MyFieldName' => 'ExactMatchFilter')
                $rewrite[$identifier] = [
                    'filter' => $specOrName,
                ];
            }
            if (!isset($rewrite[$identifier]['title'])) {
                $rewrite[$identifier]['title'] = (isset($labels[$identifier]))
                    ? $labels[$identifier] : FormField::name_to_label($identifier);
            }
            if (!isset($rewrite[$identifier]['filter'])) {
                /** @skipUpgrade */
                $rewrite[$identifier]['filter'] = 'PartialMatchFilter';
            }
        }

        $fields = $rewrite;

        // apply DataExtensions if present
        $this->extend('updateSearchableFields', $fields);

        return $fields;
    }

    public function getChangedFields($databaseFieldsOnly = false, $changeLevel = DataObject::CHANGE_STRICT)
    {
        $changedFields = [];

        // Update the changed array with references to changed obj-fields
        foreach ($this->record as $k => $v) {
            // Prevents DBComposite infinite looping on isChanged
            if (is_array($databaseFieldsOnly) && !in_array($k, $databaseFieldsOnly ?? [])) {
                continue;
            }
            if (is_object($v) && method_exists($v, 'isChanged') && $v->isChanged()) {
                $this->changed[$k] = DataObject::CHANGE_VALUE;
            }
        }

        // If change was forced, then derive change data from $this->record
        if ($this->changeForced && $changeLevel <= DataObject::CHANGE_STRICT) {
            $changed = array_combine(
                array_keys($this->record ?? []),
                array_fill(0, count($this->record ?? []), DataObject::CHANGE_STRICT)
            );
            // @todo Find better way to allow versioned to write a new version after forceChange
            unset($changed['Version']);
        } else {
            $changed = $this->changed;
        }

        if (is_array($databaseFieldsOnly)) {
            $fields = array_intersect_key($changed ?? [], array_flip($databaseFieldsOnly ?? []));
        } elseif ($databaseFieldsOnly) {
            $fieldsSpecs = $this->db();
            $fields = array_intersect_key($changed ?? [], $fieldsSpecs);
        } else {
            $fields = $changed;
        }

        // Filter the list to those of a certain change level
        if ($changeLevel > DataObject::CHANGE_STRICT) {
            if ($fields) {
                foreach ($fields as $name => $level) {
                    if ($level < $changeLevel) {
                        unset($fields[$name]);
                    }
                }
            }
        }

        if ($fields) {
            foreach ($fields as $name => $level) {
                $changedFields[$name] = [
                    'before' => array_key_exists($name, $this->original) ? $this->original[$name] : null,
                    'after' => array_key_exists($name, $this->record) ? $this->record[$name] : null,
                    'level' => $level
                ];
            }
        }

        return $changedFields;
    }

    public function canCreate($member = null)
    {
        return true;
        // @phpstan-ignore-next-line
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canView($member = null)
    {
        return true;
        // @phpstan-ignore-next-line
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }
    }

    public function canEdit($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }
        return Permission::check('ADMIN', 'any', $member);
    }

    public function canDelete($member = null)
    {
        $extended = $this->extendedCan(__FUNCTION__, $member);
        if ($extended !== null) {
            return $extended;
        }
        return Permission::check('ADMIN', 'any', $member);
    }

    public function extendedCan($methodName, $member)
    {
        $results = $this->extend($methodName, $member);
        if ($results && is_array($results)) {
            // Remove NULLs
            $results = array_filter($results, function ($v) {
                return !is_null($v);
            });
            // If there are any non-NULL responses, then return the lowest one of them.
            // If any explicitly deny the permission, then we don't get access
            if ($results) {
                return min($results);
            }
        }
        return null;
    }

    public function summaryFields()
    {
        $fields = $this->config()->get('summary_fields');

        // if fields were passed in numeric array,
        // convert to an associative array
        if ($fields && array_key_exists(0, $fields)) {
            $fields = array_combine(array_values($fields), array_values($fields));
        }

        if (!$fields) {
            $fields = [];
            // try to scaffold a couple of usual suspects
            if ($this->db('Name')) {
                $fields['Name'] = 'Name';
            }
            if ($this->db('Title')) {
                $fields['Title'] = 'Title';
            }
        }
        $this->extend("updateSummaryFields", $fields);

        // Final fail-over, just list ID field
        if (!$fields) {
            $fields['ID'] = 'ID';
        }

        // Localize fields (if possible)
        foreach ($this->fieldLabels(false) as $name => $label) {
            if (isset($fields[$name])) {
                $fields[$name] = $label;
            }
        }

        return $fields;
    }

    /**
     * @return boolean True if the object is in the database
     */
    public function isInDB()
    {
        return !empty($this->ID);
    }

    /**
     * Validate the current object.
     *
     * By default, there is no validation - objects are always valid!  However, you can overload this method in your
     * DataObject sub-classes to specify custom validation, or use the hook through DataExtension.
     *
     * Invalid objects won't be able to be written - a warning will be thrown and no write will occur.  onBeforeWrite()
     * and onAfterWrite() won't get called either.
     *
     * It is expected that you call validate() in your own application to test that an object is valid before
     * attempting a write, and respond appropriately if it isn't.
     *
     * @see {@link ValidationResult}
     * @return ValidationResult
     */
    public function validate()
    {
        $result = ValidationResult::create();
        $this->extend('validate', $result);
        return $result;
    }

    /**
     * Public accessor for {@see DataObject::validate()}
     *
     * @return ValidationResult
     * @deprecated 4.12.0 Use validate() instead
     */
    public function doValidate()
    {
        Deprecation::notice('4.12.0', 'Use validate() instead');
        return $this->validate();
    }

    /**
     * Event handler called before writing to the database.
     * You can overload this to clean up or otherwise process data before writing it to the
     * database.  Don't forget to call parent::onBeforeWrite(), though!
     *
     * This called after {@link $this->validate()}, so you can be sure that your data is valid.
     *
     * @uses DataExtension::onBeforeWrite()
     */
    protected function onBeforeWrite()
    {
        $this->brokenOnWrite = false;

        $dummy = null;
        $this->extend('onBeforeWrite', $dummy);
    }

    /**
     * Event handler called after writing to the database.
     * You can overload this to act upon changes made to the data after it is written.
     * $this->changed will have a record
     * database.  Don't forget to call parent::onAfterWrite(), though!
     *
     * @uses DataExtension::onAfterWrite()
     */
    protected function onAfterWrite()
    {
        $dummy = null;
        $this->extend('onAfterWrite', $dummy);
    }

    /**
     * Determine validation of this object prior to write
     *
     * @return ValidationException|null Exception generated by this write, or null if valid
     */
    protected function validateWrite()
    {
        // Note: Validation can only be disabled at the global level, not per-model
        if (ExternalDataObject::config()->uninherited('validation_enabled')) {
            $result = $this->validate();
            if (!$result->isValid()) {
                return new ValidationException($result);
            }
        }
        return null;
    }

    /**
     * Prepare an object prior to write
     *
     * @throws ValidationException
     */
    protected function preWrite()
    {
        // Validate this object
        if ($writeException = $this->validateWrite()) {
            // Used by DODs to clean up after themselves, eg, Versioned
            $this->invokeWithExtensions('onAfterSkippedWrite');
            throw $writeException;
        }

        // Check onBeforeWrite
        $this->brokenOnWrite = true;
        $this->onBeforeWrite();
        // @phpstan-ignore-next-line
        if ($this->brokenOnWrite) {
            throw new LogicException(
                static::class . " has a broken onBeforeWrite() function."
                . " Make sure that you call parent::onBeforeWrite()."
            );
        }
    }

    public function write()
    {
        $this->preWrite();

        $this->realWrite();

        return $this->record['ID'];
    }

    abstract protected function realWrite();

    /**
     * Event handler called before deleting from the database.
     * You can overload this to clean up or otherwise process data before delete this
     * record.  Don't forget to call parent::onBeforeDelete(), though!
     *
     * @uses DataExtension::onBeforeDelete()
     */
    protected function onBeforeDelete()
    {
        $this->brokenOnDelete = false;

        $dummy = null;
        $this->extend('onBeforeDelete', $dummy);
    }

    /**
     * Delete this data object.
     * $this->onBeforeDelete() gets called.
     * Note that in Versioned objects, both Stage and Live will be deleted.
     * @uses DataExtension::augmentSQL()
     */
    public function delete()
    {
        $this->brokenOnDelete = true;
        $this->onBeforeDelete();
        // @phpstan-ignore-next-line
        if ($this->brokenOnDelete) {
            throw new LogicException(
                static::class . " has a broken onBeforeDelete() function."
                . " Make sure that you call parent::onBeforeDelete()."
            );
        }

        // Deleting a record without an ID shouldn't do anything
        // @phpstan-ignore-next-line
        if (!$this->ID) {
            throw new LogicException("ExternalDataObject::delete() called on a ExternalDataObject without an ID");
        }

        $this->realDelete();

        // Remove this item out of any caches
        $this->flushCache();

        $this->onAfterDelete();

        $this->OldID = $this->ID;
        $this->ID = 0;
    }

    abstract protected function realDelete();

    public function flushCache($persistent = true)
    {
        if (static::class == self::class) {
            self::$_cache_get_one = [];
            return $this;
        }

        $classes = ClassInfo::ancestry(static::class);
        foreach ($classes as $class) {
            if (isset(self::$_cache_get_one[$class])) {
                unset(self::$_cache_get_one[$class]);
            }
        }

        $this->extend('flushCache');

        return $this;
    }

    /**
     * Reset all global caches associated with DataObject.
     */
    public static function reset()
    {
        self::$_cache_get_one = [];
        self::$_cache_field_labels = [];
    }

    /**
     * When extending this class and overriding this method, you will need to instantiate the CompositeValidator by
     * calling parent::getCMSCompositeValidator(). This will ensure that the appropriate extension point is also
     * invoked.
     *
     * You can also update the CompositeValidator by creating an Extension and implementing the
     * updateCMSCompositeValidator(CompositeValidator $compositeValidator) method.
     *
     * @see CompositeValidator for examples of implementation
     * @return CompositeValidator
     */
    public function getCMSCompositeValidator(): CompositeValidator
    {
        $compositeValidator = CompositeValidator::create([FieldsValidator::create()]);

        // Support for the old method during the deprecation period
        if ($this->hasMethod('getCMSValidator')) {
            // @phpstan-ignore-next-line
            $compositeValidator->addValidator($this->getCMSValidator());
        }

        // Extend validator - forward support, will be supported beyond 5.0.0
        $this->invokeWithExtensions('updateCMSCompositeValidator', $compositeValidator);

        return $compositeValidator;
    }

    /** @codeCoverageIgnore */
    public function getCMSActionsOptions()
    {
        return [
            'save_close' => false,
            'save_prev_next' => false,
            'delete_right' => false,
        ];
    }

    private function getDatabaseBackedField(string $fieldPath): ?string
    {
        return null;
    }

    public function requireDefaultRecords()
    {
        $defaultRecords = $this->config()->uninherited('default_records');

        if (!empty($defaultRecords)) {
            $hasData = ExternalDataObject::get_one(static::class);
            if (!$hasData) {
                $className = static::class;
                foreach ($defaultRecords as $record) {
                    $obj = Injector::inst()->create($className, $record);
                    $obj->write();
                }
                DB::alteration_message("Added default records to $className table", "created");
            }
        }

        // Let any extensions make their own database default data
        $this->extend('requireDefaultRecords', $dummy);
    }
}
