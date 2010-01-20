<?php

/**
 * Is Date Range
 *
 * Tells whether or not the given start and end fields are a valid
 * date range.  Note that this is useful beyond "before" and "after"
 * options on the regular IsDate test because it has the option to run
 * only if both fields are filled in, but skip in the event that they aren't.
 *
 * @package SimpleDb_Item_Validator_Test
 */
class SimpleDb_Item_Validator_Test_IsDateRange extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'startField' => null,
		'endField' => null,
		'requireBoth' => false,
		'overlapAllowed' => false,
		'format' => null,
		'error' => 'Please enter a valid date range.'
	);
	
	public function runTest() {
		extract($this->_options, EXTR_SKIP);
		
		$startFieldValue = $this->validator->getFieldValue($startField);
		$endFieldValue = $this->validator->getFieldValue($endField);
		
		if ($requireBoth) {
			// We do this individually rather than part of the above "if" because we want
			// them both to be flagged as failed.  This won't happen if we put an "||" between
			// them -- it'll fail the first and then skip the second.
			$startPassed = $this->isDate($startField);
			$endPassed = $this->isDate($endField);
			
			if (!$startPassed || !$endPassed) {
				return false;
			}
		}
		
		if ($startFieldValue == '' || $endFieldValue == '') {
			return true;
		}
					
		$options = array('chronologyError' => $error);
		
		if ($format !== null) {
			$options['format'] = $format;
		}
		
		if ($overlapAllowed) {
			$options['onOrBefore'] = $endField;
		} else {
			$options['before'] = $endField;
		}
		
		$validated = $this->validator->validateFieldUsingMethod($startField, 'isDate', $options);
		
		if (!$validated) {
			// IsDate will only mark the start field invalid, not both.  So we do that here.
			$this->validator->registerInvalid($endField, $this->formatError($error));
			return false;
		}
		
		return true;
	}
	
	public function isDate($field) {	
		return $this->validator->validateFieldUsingMethod($field, 'isDate', array('error' => $this->_options['error']));
	}
}
