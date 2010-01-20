<?php

class SimpleDb_Item_Validator_Test {
	public $defaultOptions = array();
	public $passed = null;
	public $validator;

	protected $_db;
	protected $_item;
	protected $_options = array();
	
	public function __construct($field, $fieldValue, $options, $validator, $item, $db) {
		$this->field = $field;
		$this->fieldValue = $fieldValue;
		$this->validator = $validator;
		$this->_item = $item;
		$this->_db = $db;

		$this->processOptions($options);

		if ($this->passed === null) {
			$this->passed = $this->runTest();
		}
		
		if ($this->fieldValue !== $fieldValue) {
			// It was altered by the method, so adjust it in the model.
			$this->validator->setFieldValue($field, $this->fieldValue);
		}
	}
	
	public function processOptions($options) {
		$this->_options['error'] = $this->validator->defaultError;
		$this->_options = array_merge($this->_options, $this->defaultOptions);
		
		foreach ((array)$options as $key => $value) {
			// Value parsing.
			if (strtolower($value) === 'true') {
				$value = true;
			} elseif (strtolower($value) === 'false') {
				$value = false;
			} elseif (is_numeric($value)) {
				$value = floatval($value);				
			}
			
			if (array_key_exists($key, $this->_options)) {
				// Overwrite the value.
				$this->_options[$key] = $value;
				if ($key != 'error') {
					continue;
				}
			}
			
			$this->_options['extraOptions'][$key] = $value;
		}
		
		if ($this->afterProcessOptions() === false) {
			$this->passed = false;
		}
	}
	
	/**
	 * After Process Options
	 *
	 * Runs automatically after processOptions().  It is meant to be an extension
	 * for subclasses to jump in with their own methods.
	 *
	 * @param void
	 * @return bool False indicates that validation must be abandonned because of problems
	 * processing the options.  True indicates that everything went fine.
	 */
	public function afterProcessOptions() {
		return true;
	}
	
	public function formatError($errorString, $additionalReplacements = array()) {
		$defaultReplacements = array('field' => $this->field, 'value' => $this->fieldValue);
		
		$replacements = array_merge($this->_options, $defaultReplacements, $additionalReplacements);
		
		$errorString = $this->_replaceTokensInStringWithArray($errorString, $replacements);
		
		$matches = array();
		preg_match_all('/(\b)?(:\w+)(\b)?/i', $errorString, $matches);
		if (!isset($matches[2])) {
			// Bail if there are no :keyword replacements.
			return $errorString;
		}
		
		foreach ($matches[2] as $key) {
			$key = ltrim($key, ':');
			$errorString = str_replace(':'.$key, $this->_item->$key, $errorString);
		}
		
		return $errorString;
	}
	
	/**
	 * Replace Tokens in String with Array
	 *
	 * Goes through the given array and replaces tokens (":tokenName") with the
	 * value using the tokeh ("tokenName") as the key.  For example:
	 *
	 * $string = 'I like :city.';
	 * $array = array(
	 *		'city' => 'Orono'
	 *	);
	 *
	 * Works recursively for values in the array that are, in themselves, arrays.
	 *
	 * @param string $string A reference to the string on which we are operating.
	 * @param array $array Array which contains the tokens and associated values.
	 */
	protected function _replaceTokensInStringWithArray($string, $array) {
		foreach ($array as $key => $value) {
			if (is_array($value)) {
				$string = $this->_replaceTokensInStringWithArray($string, $value);
				continue;
			}
			
			$string = str_replace(':'.$key, $value, $string);
		}
		
		return $string;
	}
}