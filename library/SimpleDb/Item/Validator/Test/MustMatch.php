<?php

class SimpleDb_Item_Validator_Test_MustMatch extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'field' => null,
		'value' => null
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if ($field === null && $value === null) {
			throw new Exception('The mustMatch Validator test requires at least one parameter: field or value.');
		}
		
		if ($field !== null && $this->validator->getFieldValue($field) != $this->fieldValue) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
		
		if ($value !== null & strtolower($value) != strtolower($this->fieldValue)) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
		
		return true;
	}
}