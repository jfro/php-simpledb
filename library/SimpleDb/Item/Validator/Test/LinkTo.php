<?php

class SimpleDb_Item_Validator_Test_LinkTo extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'field' => null, // Optional
		'fields' => null
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if (count($fields) == 0) {
			return true;
		}
		
		foreach ($fields as $currentField) {
			if ($this->validator->isFieldInvalid($currentField)) {
				$this->validator->registerInvalid($this->field, null);
				return false;
			}
		}
		
		return true;		
	}
	
	/**
	 * After Process Options
	 *
	 * Runs after the parent processOptions() method.  Performs the job of processing values
	 * for separating option strings into arrays for use in runTest().
	 */
	public function afterProcessOptions() {
		if (isset($this->_options['field']) && trim($this->_options['field']) != '') {
			// We were given one field.
			$this->_options['fields'] = $this->_options['field'];
			unset($this->_options['field']);
			return;
		}
		
		$this->_options['fields'] = preg_split('/[\s,]+/', $this->_options['fields']);
	}
}