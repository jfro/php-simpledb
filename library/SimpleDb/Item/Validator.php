<?php
class SimpleDb_Item_Validator {
	public $passed = true;
	public $defaultError = 'Please ensure that you filled in all required fields.';

	protected $_db;
	protected $_item;
	protected $_instructions = array();
	protected $_invalids = array();
	protected $_validatedFields = array(); // Contains an array of fields that have gone through validatiion, whether they passed or not.
	
	/**
	 * Constructor
	 * 
	 * @access public
	 * @param SimpleDb_Item The item to validate.
	 * @param SimpleDb The database connection.
	 * @return void
	 */
	public function __construct($item, SimpleDb $db)
	{
		$this->_item = $item;
		$this->_db = $db;
	}
	
	/**
	  * Set Instructions
	  *
	  * Uses the array given to set the instructions for Validator to follow.
	  * 
	  * @access public 
	  * @param  array
	  * @return void
	  */
	public function setInstructions($instructions)
	{
		$this->_instructions = array();
		foreach ($instructions as $key => $value) {
			if (is_int($key)) {
				// Only a field was given, so we assume notBlank as a requirement.
				$field = $value;
				$instruction = null;
			} else {
				// A field and the instructions were given.
				$field = $key;
				$instruction = $value;
			}
			
			// Apply the instructions to the field.
			$this->addInstruction($field, $instruction);
		}
	}
	
	/**
	 * Add Instruction
	 * 
	 * Adds an instruction to the list of instructions.
	 * 
	 * @access public
	 * @param  string $field - Field to receive the instructions in the second parameter.
	 * @param  string $instruction (optional) - Instruction to be added as a requirement to pass the validation process.
	 * @return void
	 */
	public function addInstruction($field, $instruction=null)
	{
		if ($instruction == null) {
			$instruction = "notBlank";
		}
		
		if (!is_array($instruction)) {
			$this->_instructions[$field][] = $instruction;	
		} else {
		
			if (!isset($this->_instructions[$field])) {
				$this->_instructions[$field] = array();
			}
			
			$this->_instructions[$field] = array_merge((array)$this->_instructions[$field], $instruction);
		}
	}
	
	/**
	 * Validate
	 * 
	 * Performs the actual validation.
	 *
	 * @access public
	 * @param  string $subset - A subset of instructions.
	 * @return void
	 */
	public function validate($subset=false)
	{
		$validation = $this->_item->validation;
		if (!$validation) {
			return;
		}
		
		if ($subset && array_key_exists($subset, $validation)) {
			$validation = $validation[$subset];
		}
		
		if (!count($validation)) {
			return;
		}
		
		$this->setInstructions($validation);
		foreach ($this->_instructions as $field => $instructions) {
			$this->validateField($field);
		}
	}
	
	/**
	 * Validate Field
	 *
	 * Runs all instructions on a given field.
	 *
	 * @access public
	 * @param  string $field
	 * @return void
	 */
	public function validateField($field)
	{
		if (!array_key_exists($field, $this->_instructions) || isset($this->_validatedFields[$field])) {
			return;
		}
		
		foreach ($this->_instructions[$field] as $instruction) {
			$parsedInstruction = $this->_parseInstruction($instruction);;
			
			$result = $this->validateFieldUsingMethod($field, $parsedInstruction['method'], $parsedInstruction['params']);

			if ($result == false || $result == null) {
				$this->_validatedFields[$field] = $result;
				return;
			}
		}
		
		$this->_validatedFields[$field] = true;
	}
	
	/**
	  * Validate Field Using Method
	  *
	  * Validates a given field with a given method and given options.
	  * 
	  * @access public 
	  * @param  string $field - The field to validate.
	  * @param  string $method - The name of the method that needs validation.
	  * @param  string $options - The options to use.
	  * @return boolean - Whether or not the field validated.
	  */
	public function validateFieldUsingMethod($field, $method, $options)
	{		
		// Prefix.
		$originalMethod = $method;
		$method = 'validate'.ucfirst($method);
		
		$fieldValue = $this->getFieldValue($field);
		if (method_exists($this->_item, $method)) {
			// We make the call locally -- THIS IS DEPRECATED.  Please use objects always.
			$host = array($this->_item, $method);
			$result = call_user_func_array($host, array($field, $fieldValue, $options, $this));
		} else {
			/**
			 * This whole section is dedicated to finding a Test class that resides outside
			 * of SimpleDb's PEAR package.  Ie, one that is custom and specific to the codebase
			 * _using_ SimpleDb.
			 *
			 * The idea is to loop over the item and all parent classes it may have and look for
			 * a class which will respond to the given test.
			 *
			 * If we are unable to find a class, we resort to SimpleDb's test suite.
			 *
			 * Example: 
			 *		"MAE_Cart" => "MAE_Test_AreCoursesAvailable" // Not found.
			 *		"CourseStorm_Cart" => "CourseStorm_Test_AreCoursesAvailable" // Found!
			 *		"SimpleDb_Item_Validator_Test_AreCoursesAvailable" // Never even gets there.
			 */
			$classWithPrefix = get_class($this->_item);
			$foundTestClass = false;
			do {
				$testClass = preg_replace('/[^_]+$/', 'Test_'.ucfirst($originalMethod), $classWithPrefix);
				
				// MJ (1/4/09): This should be Zend's job, but there is no generic method to figure out a file path for a class as of 1.7.2.
				// There is also no method on Zend_Loader to let us know if a class is available, but not load it (because attempting to load it
				// causes a warning rather than exception like it used to if the file doesn't exist).
				$testClassFile = str_replace('_', DIRECTORY_SEPARATOR, $testClass).'.php';
				
				if (Zend_Loader::isReadable($testClassFile)) {
					$foundTestClass = true;
					break;
				}
			} while ($classWithPrefix = get_parent_class($classWithPrefix));
			
			if (!$foundTestClass) {
				$testClass = __CLASS__.'_Test_'.ucfirst($originalMethod);
			}
			
			$test = new $testClass($field, $fieldValue, $options, $this, $this->_item, $this->_db);
			$result = $test->passed;
		}
		
		if ($result === true || $result === null) {
			// It did not fail the validation proceedure, however, we don't list it as
			// fully validated until it passes all methods of validation.
			return $result;
		}
		
		if (!isset($this->_invalids[$field]) || count($this->_invalids[$field]) == 0) {
			// It did not return a valid result, but it is not in the invalid list.
			// We need to add it to the invalid list.
			$this->registerInvalid($field, $this->defaultError);
		}
		
		$this->passed = false;
		return false;
	}
	
	/**
	  * Is Field Invalid
	  *
	  * Checks to see if a field has been registered as invalid.
	  * 
	  * @access public
	  * @param  string $field - The of the field we are checking.
	  * @return boolean - Whether or not the field is invalid.
	  */
	public function isFieldInvalid($field)
	{
		// The field has already been validated.
		if (isset($this->_validatedFields[$field])) {
			// Note that the value could be null, which is why we use this operator.
			return ($this->_validatedFields[$field] === false) ? true : false;
		}
		
		$this->validateField($field);
		
		return (isset($this->_validatedFields[$field]) && $this->_validatedFields[$field] === false) ? true : false;
	}
	
	/**
	 * Get Field Value
	 * 
	 * Returns the value of a given field.  Examples include the simple (name) to
	 * the advanced (classes[0][name]).
	 * 
	 * @access public
	 * @param  string $field - Field to be tested.
	 * @return mixed $value - Value of the given field.
	 */
	public function getFieldValue($field)
	{
		$matches = array();
		if (!preg_match('/\[.+\]/', $field, $matches)) {
			// It is just a plain key, so return its value.
			return $this->_item->$field;
		}
		
		// It is a complex key, so we need to parse it and get a value.
		preg_match('/([a-zA-Z0-9_]+)\[/', $field, $matches);
		$source = trim($matches[1]);
		
		$leftOvers = '['.preg_replace('/[a-zA-Z0-9_]+\[/', '', $field);
		$varString = '$this->_item->'.$source.$leftOvers;
		// Make sure to add single quotes around the keys.
		$varString = preg_replace('/\[([^\]]+)\]/', "['$1']", $varString);
		
		return eval('return '.$varString.';');
	}
	
	/**
	 * Set Field Value
	 * 
	 * Sets the valud of a given field.
	 *
	 * @access public 
	 * @param string $field - Field to be assigned a value.
	 * @param string $value - Value to be assigned to field.
	 * @return void
	 */
	public function setFieldValue($field, $value)
	{
		$matches = array();
		if (!preg_match('/\[.+\]/', $field, $matches)) {
			// It is just a plain key, so set the value and return.
			$this->_item->$field = $value;
			return;
		}
		
		// It is a complex key, so we need to parse it first.
		preg_match('/([a-zA-Z0-9_]+)\[/', $field, $matches);
		$source = trim($matches[1]);
		
		$leftOvers = '['.preg_replace('/[a-zA-Z0-9_]+\[/', '', $field);
		$varString = '$this->_item->'.$source.$leftOvers;
		// Make sure to add single quotes around the keys.
		$varString = preg_replace('/\[([^\]]+)\]/', "['$1']", $varString);
		
		eval($varString.' = $value;');
	}
	
	/**
	 * Parse Instruction
	 * 
	 * Parses an instruction for validation.
	 * 
	 * @access protected
	 * @param string $instruction - String to be parsed into function and parameters.
	 * @return array $parsed - Assoc array containing two keys: method (name of the method being called) and params (array of parameters to be passed to the method).
	 */
	protected function _parseInstruction($instruction)
	{
		// Get the method.
		$matches = array();
		if ($instruction != '' && !preg_match('/^[a-zA-Z0-9_\[\]]+ /', $instruction, $matches)) {
			return array(
				'method' => $instruction,
				'params'=>array()
			);
		}
		
		$method = trim($matches[0]);
		// Remove the method within the string so it doesn't get searched again.
		$instruction = preg_replace('/^[a-zA-Z0-9_\[\]]+ /', '', $instruction);

		// Get the parameters
		preg_match_all('/([a-zA-Z0-9_\[\]]+)=["|\']([^"|\']*)["|\']/', $instruction, $matches);
		
		// Go through each parameter and break it apart.
		$params = array();
		for ($i=0; $i<count($matches[0]); $i++) {
			$value = preg_replace('/^\'/', '', trim($matches[2][$i]));
			$params[trim($matches[1][$i])] = preg_replace('/\'$/', '', $value);
		}

		return array(
			'method' => $method,
			'params' => $params
		);
	}

	/**
	 * Register Invalid
	 * 
	 * Registers a field as invalid and gives a reason why it failed validation.
	 * 
	 * @param string $field - Name of the field being registered.
	 * @param string $reason - Reason why this field didn't pass validation.
	 * @return void
	 */
	public function registerInvalid($field, $reason)
	{
		if ($reason == null || $reason == '') {
			$reason = $this->defaultError;
		}
		
		if (!isset($this->_invalids[$field])) {
			$this->_invalids[$field] = array();
		}
		
		$this->_invalids[$field] = (array)$this->_invalids[$field];
		if (!in_array($reason, $this->_invalids[$field])) {
			// It is a new reason that this field was invalid.
			$this->_invalids[$field][] = $reason;
		}
		$this->passed = false;
	}
	
	/**
	 * Get Errors
	 *
	 * Returns an array of unique error strings.
	 *
	 * @return array
	 */
	public function getErrors()
	{
		$allErrors = array();
		foreach ($this->_invalids as $field => $errors) {
			foreach ($errors as $error) {
				$allErrors[] = $error;
			}
		}
		
		return array_unique($allErrors);
	}
	
	/**
	 * Get Invalid Fields
	 *
	 * Returns an array of fields that were marked invalid.
	 *
	 * @return array
	 */
	public function getInvalidFields()
	{
		return array_keys($this->_invalids);
	}
}