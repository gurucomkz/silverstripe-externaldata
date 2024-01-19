<?php
namespace Gurucomkz\ExternalData\Forms;

use Gurucomkz\ExternalData\Model\ExternalDataList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\ORM\ValidationException;

class ExternalDataGridFieldDeleteAction extends GridFieldDeleteAction
{

    /**
     * Only delete record is supported.
     * Somehow this will not refresh the list, so we remove the item from the list as well
     */
    public function handleAction(GridField $gridField, $actionName, $arguments, $data)
    {
        if ($actionName == 'deleterecord') {
            /** @var ExternalDataList */
            $list = $gridField->getList();
            $item = $list->byID($arguments['RecordID']);

            if (!$item) {
                return;
            }

            if (!$item->canDelete()) {
                throw new ValidationException(
                    _t('GridFieldAction_Delete.DeletePermissionsFailure', "No delete permissions"),
                    0
                );
            }
            $item->delete();
            $gridField->getList()->remove($item);
        }
    }
}
