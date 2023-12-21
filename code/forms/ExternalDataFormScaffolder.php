<?php
namespace Gurucomkz\ExternalData\Forms;

use Gurucomkz\ExternalData\Model\ExternalDataObject;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\FormScaffolder;
use SilverStripe\Forms\Tab;
use SilverStripe\Forms\TabSet;

/**
 *
 * @package externaldata
 *
 * @uses DBField::scaffoldFormField()
 * @uses ExternalDataObject::fieldLabels()
 */
class ExternalDataFormScaffolder extends FormScaffolder
{
    /**
     * @var ExternalDataObject $obj
     */
    protected $obj;

    /**
     * Gets the form fields as defined through the metadata
     * on {@link $obj} and the custom parameters passed to FormScaffolder.
     * Depending on those parameters, the fields can be used in ajax-context,
     * contain {@link TabSet}s etc.
     *
     * @return FieldList
     */
    public function getFieldList()
    {
        $fields = new FieldList();

        // tabbed or untabbed
        if ($this->tabbed) {
            $fields->push(new TabSet("Root", $mainTab = new Tab("Main")));
            $mainTab->setTitle(_t(__CLASS__ . '.TABMAIN', "Main"));
        }
        //var_dump($this->obj->db());exit();
        // add database fields
        foreach ($this->obj->db() as $fieldName => $fieldType) {
            if ($this->restrictFields && !in_array($fieldName, $this->restrictFields)) {
                continue;
            }

            // @todo Pass localized title
            if ($this->fieldClasses && isset($this->fieldClasses[$fieldName])) {
                $fieldClass = $this->fieldClasses[$fieldName];
                $fieldObject = new $fieldClass($fieldName);
            } else {
                $fieldObject = $this->obj->dbObject($fieldName)->scaffoldFormField(null, $this->getParamsArray());
            }
            $fieldObject->setTitle($this->obj->fieldLabel($fieldName));
            if ($this->tabbed) {
                $fields->addFieldToTab("Root.Main", $fieldObject);
            } else {
                $fields->push($fieldObject);
            }
        }

        return $fields;
    }
}
