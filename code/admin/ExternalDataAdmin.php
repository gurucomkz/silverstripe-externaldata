<?php
namespace Gurucomkz\ExternalData\Admin;

use Gurucomkz\ExternalData\Forms\ExternalDataGridFieldDeleteAction;
use Gurucomkz\ExternalData\Forms\ExternalDataGridFieldDetailForm;
use Gurucomkz\ExternalData\Model\ExternalDataObject;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;
use SilverStripe\ORM\DataObjectInterface;

/**
 * ExternalDataAdmin
 *
 * @category   ExternalData
 * @package    ExternalData
 * @author     Martijn van Nieuwenhoven <info@axyrmedia.nl>
 */

abstract class ExternalDataAdmin extends ModelAdmin
{
    private static $url_segment     = '';
    private static $menu_title      = 'External Data';
    private static $page_length     = 10;
    private static $default_model   = '';

    private static $managed_models  = [
    ];

    public function getGridField(): GridField
    {
        $listField = GridField::create(
            $this->sanitiseClassName($this->modelClass),
            false,
            $this->getList(),
            $fieldConfig = GridFieldConfig_RecordEditor::create($this->config()->get('page_length'))
                ->removeComponentsByType(GridFieldFilterHeader::class)
                ->removeComponentsByType(GridFieldDetailForm::class)
                ->removeComponentsByType(GridFieldDeleteAction::class)
                ->addComponents(new ExternalDataGridFieldDetailForm())
                ->addComponents(new ExternalDataGridFieldDeleteAction())
        );
        $listField->setModelClass($this->modelClass);
        return $listField;
    }

    public function getEditForm($id = null, $fields = null)
    {
        $form = Form::create(
            $this,
            'EditForm',
            new FieldList($this->getGridField()),
            new FieldList()
        )->setHTMLID('Form_EditForm');
        $form->addExtraClass('cms-edit-form cms-panel-padded center');
        $form->setTemplate($this->getTemplatesWithSuffix('_EditForm'));
        $editFormAction = Controller::join_links($this->Link($this->sanitiseClassName($this->modelClass)), 'EditForm');
        $form->setFormAction($editFormAction);
        $form->setAttribute('data-pjax-fragment', 'CurrentForm');

        $this->extend('updateEditForm', $form);
        return $form;
    }

    public function getList()
    {
        $class = $this->modelClass;
        $list =  $class::get();
        $list->dataModel = $class;
        /*
        $context = $this->getSearchContext();
        $params = $this->request->requestVar('q');
        $list = $context->getResults($params);

        $this->extend('updateList', $list);
        */
        return $list;
    }

    /**
     * Get ExternalDataObject from the current ID
     *
     * @param int|DataObjectInterface $id ID or object
     * @return DataObjectInterface|null
     */
    public function getRecord($id)
    {
        $className = $this->modelClass;
        if ($className && $id instanceof $className) {
            return $id;
        } elseif ($id == 'root') {
            return singleton($className);
        } elseif ($id) {
            return $className::get_by_id($id);
        } else {
            return null;
        }
    }
}
