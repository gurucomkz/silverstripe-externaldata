<?php
namespace Gurucomkz\ExternalData\Model;

use SilverStripe\ORM\ArrayList;

class ExternalDataList extends ArrayList {

	protected $dataClass;

	public function dataClass() {
		return $this->dataClass;
	}
}
