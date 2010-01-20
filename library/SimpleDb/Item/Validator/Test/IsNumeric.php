<?php

class SimpleDb_Item_Validator_Test_IsNumeric extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'minLength' => null,
		'maxLength' => null,
		'min' => null,
		'max' => null,
		'greaterThan' => null,
		'error' => 'Please enter a number.',
		'minError' => 'Please enter a number no less than :min.',
		'maxError' => 'Please enter a number no greater than :max.',
		'greaterThanError' => 'Please enter a number greater than :greaterThan.',
		'lengthMinError' => 'Please enter a number no less than :minLength in characters.',
		'lengthMaxError' => 'Please enter a number no greater than :maxLength characters.'
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		// Make sure it is numeric first.
		if (!is_numeric($this->fieldValue)) {
			$this->validator->registerInvalid($this->field, $this->formatError($error));
			return false;
		}
		
		// Length and value validation.
		if ($minLength !== null && strlen($this->fieldValue) < $minLength) {
			$this->validator->registerInvalid($this->field, $this->formatError($lengthMinError));
			return false;
		} elseif ($maxLength !== null && strlen($this->fieldValue) > $maxLength) {
			$this->validator->registerInvalid($this->field, $this->formatError($lengthMaxError));
			return false;
		} elseif ($greaterThan !== null && intval($this->fieldValue) <= intval($greaterThan)) {
			$this->validator->registerInvalid($this->field, $this->formatError($greaterThanError));
			return false;
		} elseif ($min !== null && $this->fieldValue < $min) {
			$this->validator->registerInvalid($this->field, $this->formatError($minError));
			return false;
		} elseif ($max !== null && $this->fieldValue > $max) {
			$this->validator->registerInvalid($this->field, $this->formatError($maxError));
			return false;
		}
		
		return true;
	}
	
	/**
	 * After Process Options
	 *
	 * Runs automatically when options are being processed to allow dynamic processing of options
	 * so that they can be distilled down from forumlas or do dynamic value replacements.
	 *
	 * @param void
	 * @return bool False indicates that validation must be abandonned because of problems
	 * processing the options.  True indicates that everything went fine.
	 */
	public function afterProcessOptions() {
		// Here, we go through each field that could contain a string which represents the
		// variable value on an object.  Ie, 'min="0" max="max"' means to lookup the "max"
		// value and use that.
		$variableOptions = array('minLength', 'maxLength', 'min', 'max', 'greaterThan');
		
		foreach ($variableOptions as $option) {
			if (!isset($this->_options[$option]) || $this->_options[$option] === null || is_numeric($this->_options[$option])) {
				// Skip.
				continue;
			}
			
			$formula = $this->_options[$option];
			preg_match_all('!(?:0x[a-fA-F0-9]+)|([a-zA-Z][a-zA-Z0-9_]+)!', $formula, $matches);
			$allowedFunctions = array('int','abs','ceil','cos','exp','floor','log','log10','max','min','pi','pow','rand','round','sin','sqrt','srand','tan');
			
			foreach ($matches[1] as $currentField) {
				$currentFieldValue = $this->validator->getFieldValue($currentField);
				
				if ($currentField && !is_numeric($currentFieldValue) && !in_array($currentField, $allowedFunctions)) {
					$this->validator->registerInvalid($this->field, $this->formatError('Please review the information you entered for accuracy.'));
					return false;
				}
				
				$formula = preg_replace('/(\b)?:?'.$currentField.'(\b)?/', $currentFieldValue, $formula);
			}
			
			eval("\$this->_options['".$option."'] = ".$formula.";");
		}
		
		return true;
	}
}