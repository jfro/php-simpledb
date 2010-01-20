<?php

class SimpleDb_Item_Validator_Test_IsDate extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'format' => 'Y-m-d H:i:s',
		'error' => 'Please enter a valid date.',
		'before' => null,
		'onOrBefore' => null,
		'after' => null,
		'onOrAfter' => null,
		'chronologyError' => null
	);
	
	public function runTest() {
		if (!is_array($this->fieldValue)) {
			$timestamp = strtotime($this->fieldValue);
			$dateString = $this->fieldValue;
		} else {
			$dateInfo = array(
				'month' => '01',
				'day' => '01',
				'hour' => '00',
				'minute' => '00',
				'second' => '00',
				'meridian' => 'am'
			);
			if (isset($this->fieldValue['date'])) {
				$timestamp = strtotime($this->fieldValue['date']);
				list($dateInfo['month'], $dateInfo['day'], $dateInfo['year']) = explode('-', date('m-d-Y', $timestamp));
			}
			
			if (!$dateInfo['year']) {
				throw new Exception('The information passed to IsDate for "'.$this->field.'" did not include a year.');
			}
			
			$dateInfo = array_merge($dateInfo, $this->fieldValue);
			
			if ($dateInfo['hour'] > 12) {
				$dateInfo['hour'] -= 12;
				$dateInfo['meridian'] = 'pm';
			}
			
			$dateString = $dateInfo['year'].'-'.$dateInfo['month'].'-'.$dateInfo['day'].' '.$dateInfo['hour'].':'.$dateInfo['minute'].':'.$dateInfo['second'].' '.$dateInfo['meridian'];
			$timestamp = strtotime($dateString);
		}
		
		extract($this->_options, EXTR_SKIP);
		
		if ($chronologyError === null) {
			$chronologyError = $error;
		}
		
		if ($timestamp === false || $timestamp === 0) {
			$this->fail($error, $dateString);
			return false;
		}
		
		// The input was valid but might be subject to other options.
		if ($before !== null && $timestamp >= $before) {
			$this->fail($chronologyError, $dateString);
			return false;
		}
		
		if ($onOrBefore !== null && $timestamp > $onOrBefore) {
			$this->fail($chronologyError, $dateString);
			return false;
		}		
		
		if ($after !== null && $timestamp <= $after) {
			$this->fail($chronologyError, $dateString);
			return false;
		}
		
		if ($onOrAfter !== null && $timestamp < $onOrAfter) {
			$this->fail($chronologyError, $dateString);
			return false;
		}
		
		$this->fieldValue = date($format, $timestamp);
		return true;
	}
	
	/**
	 * After Process Options
	 *
	 * Runs after the parent processOptions() method.  Performs the job of processing values
	 * for date formulas so that when we get to runTest(), it's a simple comparison.
	 *
	 * @param void
	 * @return void
	 */
	public function afterProcessOptions() {
		$dateFormulaOptions = array(
			'after', 'onOrAfter', 'before', 'onOrBefore'
		);
		
		foreach ($dateFormulaOptions as $option) {
			if (!isset($this->_options[$option]) || $this->_options[$option] === null) {
				// Skip.
				continue;
			}
			
			$value = $this->_options[$option];
			
			// UNIX Timestamp.
			if (Date::isTimestamp($value)) {
				continue;
			}
			
			// Parse-able by strtotime()
			if (strtotime($value) !== -1 && strtotime($value) !== false) {
				// We assume it is a date string.
				$this->_options[$option] = strtotime($value);
				continue;
			}
			
			// Likely, a formula.
			$formula = $value;
			
			// We assume it is a string indicating either a single
			// field from which to draw a value or an formula representing
			// one or more fields from which to evaluate mathematically.
			preg_match_all('!(?:0x[a-fA-F0-9]+)|([a-zA-Z][a-zA-Z0-9_]+)!', $formula, $matches);
			$allowedFunctions = array('int','abs','ceil','cos','exp','floor','log','log10','max','min','pi','pow','rand','round','sin','sqrt','srand','tan');
		
			foreach ($matches[1] as $currentField) {
				$currentFieldValue = $this->validator->getFieldValue($currentField);
				if (!Date::isTimestamp($currentFieldValue)) {
					// Try to convert it.
					$currentFieldValue = strtotime($currentFieldValue);
				}
				
				if ($currentField && ($currentFieldValue === false || $currentFieldValue === -1) && !in_array($currentField, $allowedFunctions)) {
					$this->validator->registerInvalid($this->field, $this->formatError($this->_options['error']));
					return false;
					// This is the old way of doing things.
					//throw new Exception('Field "'.$currentField.'" does not have a valid date/time value.');
				}
				
				$formula = preg_replace('/\b'.$currentField.'\b/', $currentFieldValue, $formula);
			}
		
			eval("\$this->_options['".$option."'] = ".$formula.";");
		}
	}
	
	/**
	 * Fail
	 *
	 * Regsiters the field as invalid with the appropriate error and resets
	 * the value to something that can be displayed in a form.
	 *
	 * @param string $error The error message for failure.
	 * @param string $dateString The value to be put back in for this field.
	 * @return void
	 */
	public function fail($error, $dateString) {
		$this->fieldValue = $dateString;
		$this->validator->registerInvalid($this->field, $this->formatError($error));
	}
}