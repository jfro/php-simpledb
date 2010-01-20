<?php

class SimpleDb_Item_Validator_Test_IsUnique extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'where' => null
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if (!$this->_item->isUnique($this->field, $where)) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
		
		return true;
	}
}