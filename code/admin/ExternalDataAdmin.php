<?php
namespace Gurucomkz\ExternalData\Admin;

use Gurucomkz\ExternalData\Forms\ExternalDataGridFieldDeleteAction;
use Gurucomkz\ExternalData\Forms\ExternalDataGridFieldDetailForm;
use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Control\Controller;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\Form;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\Forms\GridField\GridFieldFilterHeader;

/**
 * ExternalDataAdmin
 *
 * @category   ExternalData
 * @package    ExternalData
 * @author     Martijn van Nieuwenhoven <info@axyrmedia.nl>
 */

abstract class ExternalDataAdmin extends ModelAdmin
{
    static $url_segment     = '';
    static $menu_title      = 'External Data';
    static $page_length     = 10;
    static $default_model   = '';

    static $managed_models  = [
    ];

    public function getEditForm($id = null, $fields = null)
    {
        $list = $this->getList();

        $listField = GridField::create(
            $this->sanitiseClassName($this->modelClass),
            false,
            $list,
            $fieldConfig = GridFieldConfig_RecordEditor::create($this->config()->get('page_length'))
                ->removeComponentsByType(GridFieldFilterHeader::class)
                ->removeComponentsByType(GridFieldDetailForm::class)
                ->removeComponentsByType(GridFieldDeleteAction::class)
                ->addComponents(new ExternalDataGridFieldDetailForm())
                ->addComponents(new ExternalDataGridFieldDeleteAction())
        );

        // Validation
        if (singleton($this->modelClass)->hasMethod('getCMSValidator')) {
            $detailValidator = singleton($this->modelClass)->getCMSValidator();
            /** @var GridFieldDetailForm $detailForm */
            $detailForm = $listField->getConfig()->getComponentByType(GridFieldDetailForm::class);
            $detailForm->setValidator($detailValidator);
        }

        $form = Form::create(
            $this,
            'EditForm',
            new FieldList($listField),
            new FieldList()
        )->setHTMLID('Form_EditForm');
        $form->setResponseNegotiator($this->getResponseNegotiator());
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
            return false;
        }
    }

    public function getSearchContext()
    {
    }
    public function SearchForm()
    {
    }
    public function ImportForm()
    {
    }
}
