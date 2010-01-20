<?php

class SimpleDb_Item_Validator_Test_ValidateWhenFilled extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'field' => '',
		'using' => 'notBlank',
		'strict' => false // When strict is TRUE, a blank value '' counts as "filled".
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		if ($field != '') {
			$valueToAssess = $this->validator->getFieldValue($field);
		} else {
			$valueToAssess = $this->fieldValue;
		}
		
		if (is_array($valueToAssess)) {
			// We need to go through each one and if any is filled, we do the validation.
			$validate = false;
			foreach ($valueToAssess as $value) {
				if ($this->isFilled($value, $strict)) {
					// We found one that was filled, so validate.
					$validate = true;
					break;
				}
			}
			
			if (!$validate) {
				// None were filled, so we skip validation.
				return null;
			}
		} else {
			if (!$this->isFilled($valueToAssess, $strict)) {
				return null;
			}
		}
		
		return $this->validator->validateFieldUsingMethod($this->field, $using, $extraOptions);		
	}
	
	/**
	 * Is Filled
	 *
	 * Tells whether or not a given value is filled.  Null values are always not
	 * filled, but blank values are only considered filled if strict is on.
	 *
	 * @param mixed $value
	 * @param bool $strict
	 */
	public function isFilled($value, $strict) {
		// Null means it is not filled, regardless of strict.
		// If strict is on and the value is blank, that is filled.
		if ($value === null || (!$strict && $value === '')) {
			return false;
		}
		
		return true;
	}
}