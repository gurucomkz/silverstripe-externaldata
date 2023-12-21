<?php
namespace Gurucomkz\ExternalData\Model\FieldTypes;

use SilverStripe\Forms\HiddenField;
use SilverStripe\ORM\FieldType\DBPrimaryKey;

class ExternalDataObjectPrimaryKey extends DBPrimaryKey
{
    public function scaffoldFormField($title = null, $params = null)
    {
        $field = new HiddenField($this->name, $title);
        return $field;
    }
}
