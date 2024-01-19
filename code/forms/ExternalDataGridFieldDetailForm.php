<?php
namespace Gurucomkz\ExternalData\Forms;

use Gurucomkz\ExternalData\Model\ExternalDataObject;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldDetailForm;
use SilverStripe\ORM\Filterable;

class ExternalDataGridFieldDetailForm extends GridFieldDetailForm
{

    /**
     *
     * @param GridField $gridField
     * @param HTTPRequest $request
     * @return HTTPResponse
     */
    public function handleItem($gridField, $request)
    {
        // Our getController could either give us a true Controller, if this is the top-level GridField.
        // It could also give us a RequestHandler in the form of GridFieldDetailForm_ItemRequest if this is a
        // nested GridField.
        $requestHandler = $gridField->getForm()->getController();
        $record = $this->getExternalRecordFromRequest($gridField, $request);
        if (!$record) {
            // Look for the record elsewhere in the CMS
            $redirectDest = $this->getLostRecordRedirection($gridField, $request);
            // Don't allow infinite redirections
            if ($redirectDest) {
                // Mark the remainder of the URL as parsed to trigger an immediate redirection
                while (!$request->allParsed()) {
                    $request->shift();
                }
                return (new HTTPResponse())->redirect($redirectDest);
            }

            return $requestHandler->httpError(404, 'That record was not found');
        }
        $handler = $this->getItemRequestHandler($gridField, $record, $requestHandler);
        $manager = $this->getStateManager();
        if ($gridStateStr = $manager->getStateFromRequest($gridField, $request)) {
            $gridField->getState(false)->setValue($gridStateStr);
        }

        // if no validator has been set on the GridField then use the Validators from the record.
        if (!$this->getValidator()) {
            $this->setValidator($record->getCMSCompositeValidator());
        }

        return $handler->handleRequest($request);
    }

    /**
     * @param GridField $gridField
     * @param HTTPRequest $request
     * @return ExternalDataObject|null
     */
    protected function getExternalRecordFromRequest(GridField $gridField, HTTPRequest $request): ?ExternalDataObject
    {
        $id = $request->param('ID');
        if (!empty($id) && $id != 'new') {
            /** @var Filterable $dataList */
            $dataList = $gridField->getList();
            /** @var ExternalDataObject $record */
            $record = $dataList->byID($request->param('ID'));
        } else {
            /** @var ExternalDataObject $record */
            $record = Injector::inst()->create($gridField->getModelClass());
        }

        return $record;
    }

    /**
     * @return string name of {@see GridFieldDetailForm_ItemRequest} subclass
     */
    public function getItemRequestClass()
    {
        if ($this->itemRequestClass) {
            return $this->itemRequestClass;
        } elseif (ClassInfo::exists(static::class . '_ItemRequest')) {
            return static::class . '_ItemRequest';
        }
        return ExternalDataGridFieldDetailForm_ItemRequest::class;
    }
}
