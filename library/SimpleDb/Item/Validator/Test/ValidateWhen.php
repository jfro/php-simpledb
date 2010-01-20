<?php

class SimpleDb_Item_Validator_Test_ValidateWhen extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'field' => null,
		'equals' => null,
		'using' => 'notBlank',
		'strict' => false // When strict is TRUE, $field must === $equals to run the test.
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if (($strict && $this->validator->getFieldValue($field) !== $equals) || (!$strict && $this->validator->getFieldValue($field) != $equals)) {
			// It did not match the value, so we don't run the test.
			return null;
		}
		
		// It did match the value, so we run the test.
		
		return $this->validator->validateFieldUsingMethod($this->field, $using, $extraOptions);		
	}
}