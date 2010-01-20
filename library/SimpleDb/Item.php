<?php
/**
 * Simple DB Item
 *
 * Class for handling a single record from the database.  This is usually inherited by a 
 * specific table class.
 * 
 * @package SimpleDb
 * @author Jeremy Knope <jerome@buttered-cat.com>
 * @author Matt James <matt@rainstormconsulting.com>
 */
class SimpleDb_Item {
	/**
	 * Table name this item belongs to
	 *
	 * @var string
	 */
	public static $table;
	
	/**
	 * True if item existed in DB upon initialization, false otherwise
	 *
	 * @var bool
	 */
	public $existed = false;
	
	/**
	 * True if item currently exists in DB, false otherwise
	 *
	 * @var bool
	 */
	public $exists = false;
	
	/**
	 * An array of validation Rules
	 *
	 * @var array
	 */
	public $validation = array();
	
	/**
	 * Fields treated as an array with separator glue
	 *
	 * @var array
	 */
	public $arrayFields = array();
	
	/**
	 * Errored
	 *
	 * @var bool
	 */
	public $errored = false;
	
	/**
	 * Zend_Db instance
	 *
	 * @var Zend_Db
	 */
	protected $_zendDb;
	
	/**
	 * Simple DB instance
	 *
	 * @var db
	 */
	protected $_db;
	
	/**
	 * Config
	 *
	 * @var config
	 */
	protected $config;
	
	/**
	 * An array of columns in our table
	 *
	 * @var array
	 */
	protected $_fields = false;
	
	/**
	 * Record array
	 *
	 * @var array
	 */
	protected $_row;
	
	/**
	 * Original info array
	 *
	 * @var array
	 */
	protected $_originalRow;
	
	/**
	 * Info array after last save.
	 *
	 * @var array
	 */
	protected $_lastSaveRow;
	
	/**
	 * Stores dynamically fetched properties to avoid querying/calculating again
	 *
	 * @var array
	 */
	protected $_cachedAttributes = array();
	
	/**
	 * Caches the table name
	 *
	 * @var bool
	 */
	protected $_table = false;
	
	/**
	 * Validation engine
	 *
	 * @var SimpleDb_Item_Validator
	 */
	protected $_validator;
	
	/**
	 * Creates a new database object, a single record from a table
	 * 
	 * @param array $row associative array taken from a single record in the database
	 * @return SimpleDb_Object
	 */
	public function __construct($row, $table=false, $simpleDb=null) {
		if(!is_array($row)) {
			throw new SimpleDb_Exception('Row data has to be an array, received: '.var_export($row, true));
		}
		
		if ($table !== false) {
			$this->_table = $table;
		}
		
		$row = $this->_deserializeFields($row); // process arrayFields back into arrays/etc.
		
		$this->_row = $row;
		
		$this->_originalRow = $row;
		$this->_lastSaveRow = $row;
		$this->_db = $simpleDb;
		$this->_zendDb = $simpleDb->zendDb();
		$this->config = $simpleDb->config;
		$this->_validator = new SimpleDb_Item_Validator($this, $this->_db);
		
		if ($this->id) {
			$this->exists = true;
			$this->existed = true;
		}
		
		$this->init();
	}
	
	/**
	 * Acts as a callback that will perform initializations for the object.
	 */
	public function init() {
	}
	
	/**
	 * Returns a record field if it exists
	 *
	 * @param string $key name of column/field
	 * @return mixed
	 */
	public function __get($key) {
		if (in_array($key, $this->fields()) ||  array_key_exists($key, $this->_row)) {
			// Look for attribute
			return array_key_exists($key, $this->_row) ? $this->_row[$key] : null;
		} else if (method_exists($this, '_'.$key)) {
			// Method that loads relation.
			$method = '_'.$key;
			if(!array_key_exists($method, $this->_cachedAttributes)) {
				$this->_cachedAttributes[$method] = $this->$method();
			}

			return $this->_cachedAttributes[$method];
		} else if (0) {
			// Trying to find a relation on our own.
		}

		return null;
	}
	
	/**
	 * Sets a record field if it exists.
	 *
	 * @param string $key - name of column/field
	 * @param mixed $value
	 */
	public function __set($key, $value) {
		$originalValue = $this->$key;
		$value = $this->beforeSet($key, $value);
		if (in_array($key, $this->fields())) {
			$tableInfo = $this->tableInfo();
			if($tableInfo[$key]['NULLABLE'] && $value === '') {
				$value = null;
			}
			$this->_row[$key] = $value;
			$this->afterSet($key, $originalValue);
			return;
		}
		
		// Fall back to default behavior.
		$this->_row[$key] = $value;
		$this->afterSet($key, $originalValue);
	}
	
	/**
	 * Jumps in before a value is set.  Allows the user class to observe
	 * the change or to affect the value in some way.
	 *
	 * @access public
	 * @param  string $key - name of the column/field
	 * @param  mixed  $newValue - the value attempting to be applied.
	 * @return mixed  The value to be applied.
	 */
	public function beforeSet($key, $newValue) {
		return $newValue;
	}

	/**
	 * Jumps in after a value is set.
	 *
	 * @access public
	 * @param  string $key - name of the column/field
	 * @param  mixed $oldValue - The value that was replaced.
	 */	
	public function afterSet($key, $oldValue) {
	}
	
	/**
	 * Works to clear cached attributes properly when unsetting magic properties.
	 * Example: unset($this->files)
	 *
	 * @access public
	 * @param  string $key - Name of the column/field.
	 */
	public function __unset($key) {
		$method = '_'.$key;
		if (array_key_exists($method, $this->_cachedAttributes)) {
			unset($this->_cachedAttributes[$method]);
		}
	}
	
	/**
	 * Returns the table name for this item class, specified in static $table variable
	 *
	 * @return string
	 */
	public function tableName() {
		if(!$this->_table) {
			$class = get_class($this);
			eval('$tableName = '.$class.'::$table;');
			$this->_table = $tableName;
		}
		
		return $this->_table;
	}
	
	/**
	 * Returns an array of the fields for this item.
	 *
	 * @return array
	 */
	public function fields() {
		return array_keys($this->tableInfo());
	}
	
	public function tableInfo() {
		return $this->_db->infoForTable($this->tableName());
	}
	
	/**
	 * Returns DB data for this item.
	 *
	 * @param  bool $databaseColumnsOnly - Whether or not to only return data for columns found in the DB.
	 * @param  bool $serialize - Whether or not to "serialize" the array data before sending.
	 * @return array
	 */
	public function info($databaseColumnsOnly=false, $serialize=false) {
		if(!$databaseColumnsOnly) {
			return $this->_row;
		}
		
		$info = array_intersect_key($this->_row, array_flip($this->fields()));
		return $serialize ? $this->_serializeFields($info) : $info;
	}
	
	/**
	 * Updates the data array with new information.
	 *
	 * @param array $info
	 */
	public function setInfo($info) {
		if (!is_array($info)) {
			return;
		}
		$tableInfo = $this->tableInfo();
		foreach($info as $key=>$value) {
			if(isset($tableInfo[$key]) && $tableInfo[$key]['NULLABLE'] && $value === '') {
				$value = null;
			}
			$this->_row[$key] = $value;
		}
	}

	/**
	 * Have Changed
	 * 
	 * Alias for hasChanged()
	 */
	public function haveChanged($fields, $mode='any') {
		return $this->hasChanged($fields, $mode);
	}

	/**
	 * Compares 2 values, making sure to avoid ints within strings issues, like with zipcodes
	 * 
	 * @param mixed $value1 First value to compare
	 * @param mixed $value2 Second value to compare against
	 * @return boolean Returns true if they're equal
	 */
	protected function _valuesAreEqual($value1, $value2) {
		/**
		 * Bail if we have a condition where they could be equal integers but are inequal strings
		 * i.e. '04853' == '4853' we want to be not true unlike normal PHP behavior
		 */
		if(is_string($value1) && is_string($value2) && strlen($value1) != strlen($value2)) {
			return false;
		}
		
		/**
		 * General case
		 */
		return ($value1 == $value2);
	}
	
	/**
	 * Tells whether or not a given field (or set of fields) has changed
	 * since initialization.
	 *
	 * @param  mixed $fields - Either a single field or an array of fields.
	 * @param  string $mode - Can be 'any' or 'all'.
	 */
	public function hasChanged($fields, $mode='any') {
		$numChanged = 0;
		$fields = (array)$fields;
		foreach ($fields as $field) {
			//if ($this->$field != $this->originalValueForField($field)) {
			if (!$this->_valuesAreEqual($this->$field, $this->originalValueForField($field))) {
				if ($mode == 'any') {
					return true;
				}
				$numChanged++;
			}
		}

		// Mode is all and all have changed.		
		if ($mode == 'all' && $numChanged == count($fields)) {
			return true;
		}
		
		// Mode is any, but none have changed or mode is all and all have not changed.
		return false;
	}
	
	/**
	 * Tells whether or not a given field (or set of fields) has changed
	 * since the last save.
	 *
	 * @param  mixed $fields - Either a single field or an array of fields.
	 * @param  string $mode - Can be 'any' or 'all'.
	 */
	public function hasChangedSinceLastSave($fields, $mode='any') {
		$numChanged = 0;
		$fields = (array)$fields;
		foreach ($fields as $field) {
			if (!$this->_valuesAreEqual($this->$field, $this->valueForFieldAfterLastSave($field))) {
				if ($mode == 'any') {
					return true;
				}
				$numChanged++;
			}
		}

		// Mode is all and all have changed.		
		if ($mode == 'all' && $numChanged == count($fields)) {
			return true;
		}
		
		// Mode is any, but none have changed or mode is all and all have not changed.
		return false;
	}
	
	/**
	 * Allows string representation of the item, prints the row out
	 *
	 * @return string
	 */
	public function __toString() {
		// Note that the reason we have both checks is because of magic properties that wouldn't necessarily be in the fields array.
		if (!in_array('name', $this->fields()) && $this->name === null) {
			return var_export($this->_row, true);
		}
		
		if ($this->name === null) {
			return '';
		}
		
		return $this->name;
	}
	
	/**
	 * Saves the current attributes to the DB.
	 */
	public function save() {
		$this->beforeSave();
		
		// Trims out non-fields and serializes data for DB entry.
		$info = $this->info(true, true);
		
		if (!$this->exists) {
			$this->beforeCreate();
			$this->_zendDb->insert($this->tableName(), $info);
			if (!$this->id) {
				$this->id = $this->_zendDb->lastInsertId();
			}
			$this->_afterCommand('insert');
			$this->exists = true;
			$this->afterCreate();
		} else {
			$this->_zendDb->update($this->tableName(), $info, 'id = '.$this->_zendDb->quote($this->id));
			$this->_afterCommand('update');
		}
		
		$this->afterSave();
		
		$this->_lastSaveRow = $this->info();
	}
	
	/**
	 * Runs before save().
	 */
	public function beforeSave() {
	}
	
	/**
	 * Runs before an item is created in the DB,
	 * but after beforeSave().
	 */
	public function beforeCreate() {
	}
	
	/**
	 * Runs after an item is created in the DB,
	 * but before afterSave().
	 */
	public function afterCreate() {
	}
	
	/**
	 * Runs after save().
	 */
	public function afterSave() {
	}
	
	/**
	 * Deletes the current record from the DB.
	 */
	public function delete() {
		if (!$this->id) {
			return false;
		}
		
		$this->beforeDelete();
		$this->_zendDb->delete($this->tableName(), 'id = '.$this->id);
		$this->_afterCommand('delete');
		$this->afterDelete();
		return true;
	}
	
	/**
	 * Runs before delete().
	 */
	public function beforeDelete() {
	}
	
	/**
	 * Runs after delete().
	 */
	public function afterDelete() {
	}
	
	/**
	 * Validates
	 *
	 * Tells whether or not this item passed validation.
	 *
	 * @access public
	 * @param  string $subset - A subset of instructions.
	 */
	public function validates($subset=false) {
		$this->beforeValidates($subset);
		
		$this->_validator->validate($subset);
		$this->afterValidates($this->_validator->passed);
		if ($this->_validator->passed) {
			$this->errored = false;
			return true;
		}
		$this->errored = true;
		return false;
	}
	
	/**
	 * Runs before validates().
	 *
	 * @param string $subset
	 */
	public function beforeValidates($subset) {
	}
	
	/**
	 * Runs after validates().
	 *
	 * @param bool $passed
	 */
	public function afterValidates($passed) {
	}
	
	/**
	 * Is Unique
	 *
	 * Tells whether or not the given field is unique to this
	 * item in the DB.
	 *
	 * @access public
	 * @param  string $field
	 * @param  string|null $where - An optional where clause.
	 * @return bool
	 */
	public function isUnique($field, $where=null) {
		$conditionString = $field.' = ? AND id != ?';
		if ($where !== null) {
			$conditionString .= ' AND '.$where;
		}
		
		return !(bool)count($this->_db->{$this->tableName()}->where($conditionString, $this->$field, $this->id));
	}
	
	/**
	 * Original Value For Field
	 *
	 * Returns the original value of a given field
	 *
	 * @access public
	 * @param  string $field
	 * @return mixed
	 */
	public function originalValueForField($field) {
		if (isset($this->_originalRow[$field]) && $this->_originalRow[$field]) {
			return $this->_originalRow[$field];
		}
		
		return null;
	}
	
	/**
	 * Value for Field After Last Save
	 *
	 * Returns the value of a given field directly after
	 * the last save() operation.
	 *
	 * @param  string $field
	 * @return mixed
	 */
	public function valueForFieldAfterLastSave($field) {
		if (isset($this->_lastSaveRow[$field])) {
			return $this->_lastSaveRow[$field];
		}
		
		return null;
	}
	
	/**
	 * Error Message
	 *
	 * Returns an imploded string of all errors that occurred during validation.
	 *
	 * @access protected
	 */
	protected function _errorMessage() {
		return implode(' ', $this->_validator->getErrors());
	}
	
	/**
	 * Invalid Fields
	 *
	 * Returns an array of all invalid fields.
	 *
	 * @access protected
	 */
	protected function _invalidFields() {
		return $this->_validator->getInvalidFields();
	}
	
	/**
	 * Deserialize Fields
	 *
	 * Expands and returns the given info from strings to arrays if its key
	 * is listed in the $this->arrayFields property.
	 *
	 * @param  array $row - The info we start with.
	 * @return array A modified version of the original $row.
	 */
	protected function _deserializeFields($row) {
		// turn arrayFields into arrays
		foreach($this->arrayFields as $key=>$value) {
			$sep = ',';
			$field = $value;
			if(array_key_exists($key, $row)) {
				$sep = $value;
				$field = $key;
			}
			if(!array_key_exists($field, $row)) {
				continue; // just in case we don't have a full array, ignore it?
			}
			if($sep == 'serialize') {
				$row[$field] = unserialize($row[$field]);
			}
			else {
				$row[$field] = explode(',', $row[$field]);
			}
			
		}
		return $row;
	}
	
	/**
	 * Serialize Fields
	 *
	 * Collapses and returns the given info from ararys to strings if its key
	 * is listed in the $this->arrayFields property.
	 *
	 * @param  array $row - The info we start with.
	 * @return array A modified version of the original $row.
	 */
	protected function _serializeFields($info) {
		foreach($this->arrayFields as $key=>$value) {
			$sep = ',';
			$field = $value;
			if(in_array($key, $this->fields(), true)) {
				$sep = $value;
				$field = $key;
			}
			
			if(!in_array($field, $this->fields(), true)) {
				throw new SimpleDb_Exception('Field \''.$field.'\' specified in arrayFields does not exist in table');
			}

			if (!isset($info[$field]) || !is_array($info[$field])) {
				continue;
			}
			if($sep == 'serialize') {
				$info[$field] = serialize($info[$field]);
			}
			else {
				$info[$field] = implode($sep, $info[$field]);
			}
		}
		return $info;
	}
	
	/**
	 * After Command
	 *
	 * Placeholder callback method to be overridden in child classes.  The idea is to
	 * be notified when certain commands happen DIRECTLY after a database command.
	 * Note that this method is meant to be lower level than a regular before/after call.
	 *
	 * @param string $command insert/update/delete
	 * @return void
	 */
	protected function _afterCommand($command) {
	}
}
