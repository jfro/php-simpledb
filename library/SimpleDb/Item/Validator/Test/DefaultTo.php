<?php

class SimpleDb_Item_Validator_Test_DefaultTo extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'value' => null,
		'strict' => false // When strict is TRUE, a blank value '' counts as "filled".
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if ($this->fieldValue === null || (!$strict && $this->fieldValue == '')) {
			// It was empty, so we fill it.
			$this->validator->setFieldValue($this->field, $value);
		}

		return true;
	}
}
