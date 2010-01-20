<?php

class SimpleDb_Item_Validator_Test_IsTime extends SimpleDb_Item_Validator_Test {
	public $defaultOptions = array(
		'format' => 'H:i:s',
		'error' => 'Please enter a valid time.',
		'require' => 'hour, minute, meridian'
	);
	
	public $originalValue;
	
	public function runTest() {
		if (!is_array($this->fieldValue)) {
			// The isTime validator test can only run on arrays right now.
			return;
		}
		
		$this->originalValue = $this->fieldValue;
		extract($this->_options, EXTR_SKIP);

		foreach ($this->fieldValue as $key => $value) {
			unset($this->fieldValue[$key]);
			$this->fieldValue[strtolower($key)] = $value;
		}
		
		// This keeps us from having to perform "isset" commands which would trigger notices.
		$empties = array(
			'hour' => '',
			'minute' => '',
			'second' => '',
			'meridian' => ''
		);
		$timeInfo = array_merge($empties, $this->fieldValue);
		
		$timeString = $timeInfo['hour'].':'.$timeInfo['minute'].':'.$timeInfo['second'].' '.$timeInfo['meridian'];
		$timeString = preg_replace(array('/:[^0-9]/', '/^[\s:]+/'), '', $timeString);
		
		foreach ($require as $requiredKey) {
			if (!$timeInfo[$requiredKey]) {
				$this->fail($error);
				return false;
			}
		}
		
		if ($timeInfo['hour'] > 12) {
			$timeInfo['hour'] -= 12;
			$timeInfo['meridian'] = 'pm';
		}
		
		$timestamp = strtotime(date('Y-m-d ').$timeString);
		
		if ($timestamp === false || $timestamp === 0) {
			$this->fail($error);
			return false;
		}

		$this->fieldValue = date($format, $timestamp);
		return true;
	}
	
	/**
	 * After Process Options
	 *
	 * Runs after the parent processOptions() method.  Performs the job of processing values
	 * for separating option strings into arrays for use in runTest().
	 */
	public function afterProcessOptions() {
		$this->_options['require'] = preg_split('/[\s,]+/', $this->_options['require']);
	}
			
	/**
	 * Fail
	 *
	 * Regsiters the field as invalid with the appropriate error and resets
	 * the value to something that can be displayed in a form.
	 *
	 * @param string $error - The error message for failure.
	 */
	public function fail($error) {
		$this->fieldValue = $this->originalValue;
		$this->validator->registerInvalid($this->field, $this->formatError($error));
	}
}