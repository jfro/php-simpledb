<?php

class SimpleDb_Item_Validator_Test_IsUrl extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		// We will pre-pend http:// in front of the value by default.
		if (!preg_match('/^(ftp|http|https):\/\//', $this->fieldValue)) {
			// Note, this will change it in the model as well.
			$this->fieldValue = 'http://'.$this->fieldValue;
		}
		
		$urlRegEx = '/^(ftp|http|https):\/\/(\w+:{0,1}\w*@)?(\S+)(:[0-9]+)?(\/|\/([\w#!:.?+=&%@!\-\/]))?\.[a-zA-Z]{2,4}/';
		return (bool)preg_match($urlRegEx, $this->fieldValue);
	}
}