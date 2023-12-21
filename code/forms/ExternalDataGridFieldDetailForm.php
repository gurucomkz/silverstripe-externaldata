<?php
namespace Gurucomkz\ExternalData\Forms;

use SilverStripe\Forms\GridField\GridFieldDetailForm;

class ExternalDataGridFieldDetailForm extends GridFieldDetailForm
{

	public function ItemEditForm()
	{
		$form = parent::ItemEditForm();

		return $form;
	}

	public function handleItem($gridField, $request)
	{
		$controller = $gridField->getForm()->Controller();

		// we can't check on is_numeric, since some datasources use strings as identifiers
		if ($request->param('ID') && $request->param('ID') != 'new') {
			$record = $gridField->getList()->byId($request->param("ID"));
		} else {
			$record = Object::create($gridField->getModelClass());
		}

		$class = $this->getItemRequestClass();

		$handler = Object::create($class, $gridField, $this, $record, $controller, $this->name);
		$handler->setTemplate($this->template);

		return $handler->handleRequest($request);
	}
}
