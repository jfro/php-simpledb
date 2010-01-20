<?php

class SimpleDb_Item_Validator_Test_IsPhone extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'error' => 'You did not enter a valid phone number.',
		'defaultAreaCode' => '207',
		'modify' => false
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		$phone = new PhoneNumber($this->fieldValue);
		$phone->defaultAreaCode = $defaultAreaCode;
		list($areaCode, $first3Digits, $last4Digits) = $phone->split();
		
		// Check to make sure it is a valid phone number.
		if (!$areaCode || !$first3Digits || !$last4Digits) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
				
		if ($modify) {
			$this->validator->setFieldValue($this->field, $areaCode.'-'.$first3Digits.'-'.$last4Digits);
		}
				
		return true;
	}
}