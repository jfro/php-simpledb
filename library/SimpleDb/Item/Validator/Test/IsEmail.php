<?php

class SimpleDb_Item_Validator_Test_IsEmail extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'error' => 'You did not enter a valid e-mail address.'
	);
	
	public function runTest() {
		$emailRegex = "/[a-zA-Z0-9._%-]+@[a-zA-Z0-9._%-]+\.[a-zA-Z]{2,4}/";
		
		extract($this->_options, EXTR_SKIP);
		
		// Check to make sure it is a valid email address.
		if (!preg_match($emailRegex, $this->fieldValue, $matches)) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
				
		return true;
	}
}