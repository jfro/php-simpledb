<?php
/**
 * List class file
 * 
 *
 * @package SimpleDb
 * @author Jeremy Knope <jerome@buttered-cat.com>
 * @author Matt James <matt@rainstormconsulting.com>
 */

/**
 * List class
 * 
 * Handles table level actions, like retrieving rows, filtering, sorting, etc.
 *
 * @package SimpleDb
 */
class SimpleDb_List implements Iterator,Countable,ArrayAccess {
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
	public $config;
	
	/**
	 * Class name for table item class
	 *
	 * @var string
	 */
	protected $_class;
	
	/**
	 * Name of the table we are working with.
	 *
	 * @var string
	 */
	protected $_table;
	
	/**
	 * Zend_Db_Select instance used for filtering/querying
	 *
	 * @var Zend_Db_Select
	 */
	protected $_select;
	
	/**
	 * True if we need to execute the query, if filter has changed etc.
	 *
	 * @var boolean
	 */
	protected $_needsExecute = true;
	
	/**
	 * The executed statement produced from the select object
	 *
	 * @var Zend_Db_Statement
	 */
	protected $_statement;
	
	/**
	 * Row index for iterator to use
	 *
	 * @var integer
	 */
	protected $_rowIndex = 0;
	
	/**
	 * The current record (item class) used in iterator
	 *
	 * @var SimpleDb_Item
	 */
	protected $_currentItem = false;
	
	/**
	 * Flag for whether or not we've done a join yet
	 * @var boolean
	 */
	protected $_isJoined = false;
	
	/**
	 * Whether or not we've done a LIMIT
	 * @var boolean
	 */
	protected $_isLimited = false;
	
	/**
	 * Whether or not we've done a GROUP BY
	 * @var boolean
	 */
	protected $_isGrouped = false;
	
	/**
	 * Holds table alias if any was specified
	 * @var boolean|string
	 */
	protected $_alias = false;
	
	/**
	 * Fields to select from the primary table
	 * @var string
	 */
	protected $_fields = array('*');
	
	/**
	 * Holds an internal count.
	 * @var boolean|int
	 */
	protected $_count = false;
	
	/**
	 * Keeps track of all joins performed so that we can later
	 * re-perform them in the event that we have to reset the
	 * query's FROM because we added an alias.
	 *
	 * @var array
	 */
	protected $_joinHistory = array();
	
	/**
	 * Creates a new list class instance for given table class
	 *
	 * @param string $class name of table class
	 */
	public function __construct($class, $table=false, $simpleDb=null) {
		$this->_class = $class;
		if ($table !== false) {
			$this->_table = $table;
		}
		
		$this->_select = $simpleDb->zendDb()->select()->from($this->tableName());
		$this->_zendDb = $simpleDb->zendDb();
		$this->_db = $simpleDb;
		$this->config = $this->_db->config;
	}
		
	/**
	 * Specifies fields to select from primary table, takes variable arguments of field names
	 * @param string|array $fields
	 * @param ...
	 */
	public function fields($fields) {
		$arguments = func_get_args();
		if (count($arguments) == 1) {
			$fields = (array)$arguments[0];
		} else {
			$fields = $arguments;
		}
		
		// Note that if the current fields array has columns with aliases and we are sending a different column
		// with the same alias, this will make it so that only the later column uses that alias.
		$this->_fields = array_merge($this->_fields, $fields);
		$this->_select->reset(Zend_Db_Select::COLUMNS)->columns($this->_fields);
		
		return $this;
	}
	
	/**
	* Alias
	*
	* Sets an internal alias variable that is used if, and only if, we haven't operated a method on the internal
	* $_select variable.  This could be changed in the future so that alias() can be called at any time regardless,
	* but that is more difficult and requires a deeper understanding of Zend_Db_Select internals.
	*
	* @access public
	* @param  string $alias - The alias to use for the table.
	* @return SimpleDb_List object.
	*/
	public function alias($alias) {
		$this->_alias = $alias;
		
		// In order to do the alias, we have to clear the FROM.
		$this->_select->reset(Zend_Db_Select::FROM)->reset(Zend_Db_Select::COLUMNS)->from(array($this->_alias => $this->tableName()), $this->_fields);
		
		// Now, since we cleared the FROM, we should go through and re-perform all the joins that were cleared out as well.
		$joinHistory = $this->_joinHistory;
		
		// Clearing the joinHistory because it will get re-populated when we perform the joins below.
		unset($this->_joinHistory);
		foreach ($joinHistory as $join) {
			call_user_func_array(array($this->_select, $join['function']), $join['arguments']);
		}
		
		return $this;
	}
	
	/**
	 * Id
	 *
	 * Returns a single item that matches a given id (with an optional key).
	 *
	 * @access public
	 * @param  mixed $id - The value of the primary key.
	 * @param  string $key - The name of the column that represents a unique primary key.
	 * @return SimpleDb_Item or extension thereof.
	 */
	public function id($id, $key='id') {
		if ($id === null || $id === '') {
			return null;
		}
		
		return $this->where($this->_zendDb->quoteIdentifier($key).' = ?', $id)->first();
	}
	
	/**
	 * Uses disgusting eval() to get the class name from the table class
	 *
	 * @return string
	 */
	public function tableName() {
		if (!$this->_table) {
			eval('$tableName = '.$this->_class.'::$table;');
			$this->_table = $tableName;
		}

		return $this->_table;
	}
	
	/**
	 * Returns either the table name, or if one exists, and alias.
	 *
	 * @return string
	 */
	public function tableNameOrAlias() {
		if ($this->_alias) {
			return $this->_alias;
		}
		
		return $this->tableName();
	}
	
	/**
	 * Magic string function, produces a friendly output useful in debugging
	 *
	 * @return string
	 */
	public function __toString() {
		$out = 'SimpleDb_List('.$this->_class.'): <br /><br />Query: <br />'.$this->query().' <pre>';
		$limit = 5;
		try {
			foreach($this as $item) {
				$out .= (string)$item."\n";
				$limit--;
				if($limit == 0) {
					break;
				}
			}
		}
		catch (Exception $e) {
			$out .= 'Error: '.$e->getMessage().' with query: '.$this->query();
		}
		return $out.'</pre>';
	}
	
	/**
	 * Produces the string version of the query that's built so far
	 * @return string
	 */
	public function query() {
		try {
			return $this->_select->__toString();
		} catch (Exception $e) {
			return $e->getMessage();
		}
	}
	
	/**
	 * Allows one to quickly do an id IN MySQL query.
	 *
	 * @param array $values
	 */
	public function whereIdIsIn($values) {
		return $this->whereFieldValueIsIn('id', $values);
	}
	
	/**
	 * Allows one to quickly do an id NOT IN MySQL query.
	 *
	 * @param array $values
	 */
	public function whereIdIsNotIn($values) {
		return $this->whereFieldValueIsNotIn('id', $values);
	}
	
	/**
	 * Allows one to quickly do an IN MySQL query.
	 *
	 * @param string $field - The field to check against.
	 * @param array $values
	 */
	public function whereFieldValueIsIn($field, $values) {
		if (!count($values)) {
			return $this;
		}
		
		// Escape the values.
		$inString = '';
		foreach ($values as $value) {
			$inString .= $this->_zendDb->quote($value).',';
		}
		$inString = rtrim($inString, ',');
		
		$this->where($this->_zendDb->quoteIdentifier($this->tableNameOrAlias().'.'.$field).' IN ('.$inString.')');
		return $this;
	}
	
	/**
	 * Allows one to quickly do a NOT IN MySQL query.
	 *
	 * @param string $field - The field to check against.
	 * @param array $values
	 */
	public function whereFieldValueIsNotIn($field, $values) {
		if (!count($values)) {
			return $this;
		}
		
		// Escape the values.
		$inString = '';
		foreach ($values as $value) {
			$inString .= $this->_zendDb->quote($value).',';
		}
		$inString = rtrim($inString, ',');
		
		$this->where($this->_zendDb->quoteIdentifier($this->tableNameOrAlias().'.'.$field).' NOT IN ('.$inString.')');
		return $this;
	}
	
	/**
	 * Dynamically calls Zend_Db_Select methods if they match
	 */
	public function __call($functionName, $arguments) {
		// "new" is a reserved word so the declaration of a method
		// named new() raises a parse error.  This is how we get around this.
		if ($functionName == 'new' || $functionName == 'clone') {
			return call_user_func_array(array($this, '_'.$functionName), $arguments);
		}
		
		// Methods that start with "call" are presumed to be executed on all items
		// in a list.
		if (preg_match('/^call/', $functionName)) {
			$functionName = substr($functionName, 4);
			// lcfirst() is not in this PHP build !
			$functionName = strtolower(substr($functionName, 0, 1)).substr($functionName, 1);
			
			// Make sure the class responds to the method.
			$classMethods = get_class_methods($this->_class);
			if (!in_array($functionName, $classMethods)) {
				throw new SimpleDb_Exception($functionName.' is unavailable in '.$this->_class);
			}
			
			foreach ($this as $item) {
				call_user_func_array(array($item, $functionName), $arguments);
			}
			return;
		}
	
		if(!method_exists($this->_select, $functionName)) {
			// We see if the method is in the item class.
			throw new SimpleDb_Exception($functionName.' is unavailable in Zend_Db_Select');
		}
		$this->_needsExecute = true;

		if(strstr($functionName, 'join') !== false) {
			$this->_isJoined = true;
			$this->_joinHistory[] = array('function' => $functionName, 'args' => $arguments);
		} else if(strstr($functionName, 'limit') !== false) {
			$this->_isLimited = true;
		} else if (strstr($functionName, 'group') !== false) {
			$this->_isGrouped = true;
		} else if(strstr($functionName, 'reset') !== false) {
			if(count($arguments) == 0) {
				$this->_isJoined = false;
				$this->_isLimited = false;
				$this->_isGrouped = false;
			}
			else {
				switch($arguments[0]) {
					case Zend_Db_Select::FROM:
						$this->_isJoined = false;
						break;
					case Zend_Db_Select::LIMIT_OFFSET:
					case Zend_Db_Select::LIMIT_COUNT:
						$this->_isLimited = false;
						break;
					case Zend_Db_Select::GROUP:
						$this->_isGrouped = false;
						break;
				}
			}
		} 
		
		call_user_func_array(array($this->_select, $functionName), $arguments);
		$this->_count = false;
		return $this;
	}
	
	/**
	 * Join
	 *
	 * Joins a table, without selecting any columns by default and allowing MySQL table alias syntax
	 *
	 * @access public
	 * @param array|string|Zend_Db_Expr $name
	 * @param string $cond
	 * @param array|string $cols
	 * @param string $schema
	 * @return $this - Chainable.
	 */
	public function join($name, $cond, $cols = array(), $schema = null) {
		$this->_joinHistory[] = array('function' => 'join', 'args' => func_get_args());
	
		if(is_string($name) && strpos($name, ' ')) {
			list($table, $alias) = explode(' ', $name);
			$name = array($alias => $table);
		}
		$this->_isJoined = true;
		$this->_select->join($name, $cond, $cols, $schema);
		$this->_count = false;
		return $this;
	}
	
	/**
	 * Join Left
	 *
	 * Joins a table, without selecting any columns by default and allowing MySQL table alias syntax
	 *
	 * @access public
	 * @param array|string|Zend_Db_Expr $name
	 * @param string $cond
	 * @param array|string $cols
	 * @param string $schema
	 * @return $this - Chainable.
	 */
	public function joinLeft($name, $cond, $cols = array(), $schema = null) {
		$this->_joinHistory[] = array('function' => 'joinLeft', 'args' => func_get_args());
		
		if(is_string($name) && strpos($name, ' ')) {
			list($table, $alias) = explode(' ', $name);
			$name = array($alias => $table);
		}
		$this->_isJoined = true;
		$this->_select->joinLeft($name, $cond, $cols, $schema);
		$this->_count = false;
		return $this;
	}
	
	/**
	 * Join Inner
	 *
	 * Joins a table, without selecting any columns by default and allowing MySQL table alias syntax
	 *
	 * @access public
	 * @param array|string|Zend_Db_Expr $name
	 * @param string $cond
	 * @param array|string $cols
	 * @param string $schema
	 * @return $this - Chainable.
	 */
	public function joinInner($name, $cond, $cols = array(), $schema = null) {
		$this->_joinHistory[] = array('function' => 'joinInner', 'args' => func_get_args());
		
		if(is_string($name) && strpos($name, ' ')) {
			list($table, $alias) = explode(' ', $name);
			$name = array($alias => $table);
		}
		$this->_isJoined = true;
		$this->_select->joinInner($name, $cond, $cols, $schema);
		$this->_count = false;
		return $this;
	}
	
	/**
	 * Where
	 *
	 * This method overrides the Zend_DB_Select's where method so that we can have a little
	 * more flexibility.  Specifically, there are 6 use cases for this method.
	 *
	 * $list->where('id = 5');
	 *
	 *		A SQL condition string is passed.  If this is all that is passed, we forward along
	 * 		to the Zend_DB_Select method.
	 *
	 * $list->where('firstname = ? OR lastname = ?', 'James');
	 *
	 *		A SQL condition string is passed along with a single replacement value for all "?".
	 * 		In this case, we pass everything along as well since Zend_DB_Select handles this
	 *		use case.
	 *
	 * $list->where('firstname = ? AND lastname = ?', 'Tom', 'Jones');
	 *
	 *		A SQL condition string is passed along with a variable argument list, each representing
	 * 		a "?" placeholder value in the condition string.
	 *
	 * $list->where('firstname = :0 AND lastname = :1', 'Tom', 'Jones');
	 *
	 * 		A SQL condition string is passed along with a variable argument list, each representing
	 * 		a ":index" placeholder value in the condition string.
	 *
	 * $list->where('firstname = :0 AND lastname = :1', array('Tom', 'Jones'));
	 *
	 *		A SQL condition string is passed along with an array where each value represents
	 * 		a ":index" placeholder value in the condition string.
	 *
	 * $list->where('firstname = :firstname AND lastname = :lastname', array('firstname' => 'Tom', 'lastname' => 'Jones'));
	 *
	 *		A SQL condition string is passed along with an array where each value represents
	 * 		a ":key" placeholder value in the condition string.
	 *
	 * @param string $condition the condition string
	 * @param ... variable list of arguments to replace into condition string
	 */
	public function where($condition) {
		$args = func_get_args();
		if (count($args) > 1) {
			$condition = call_user_func_array(array($this->_db, 'quoteInto'), $args);
		}
		
		$this->_select->where($condition);
		$this->_count = false;
		
		return $this;
	}
	
	/**
	 * First
	 * 
	 * Returns the first item in the list.
	 * 
	 * @access public
	 * @return SimpleDB_Item
	 */
	 public function first() {
		$this->limit(1);
		$item = $this[0];
		// We don't want the LIMIT hanging around and affecting the list long-term.
		$this->reset(Zend_Db_Select::LIMIT_COUNT);
		$this->reset(Zend_Db_Select::LIMIT_OFFSET);
		return $item;
	}
	
	/**
	 * Last
	 *
	 * Returns the last item in the list.
	 *
	 * @param void
	 * @return SimpleDb_Item
	 */
	public function last() {
		return $this[count($this)-1];
	}
	
	/**
	 * Enables the Zend_Db_Profiler to help with debugging, returns self allowing method chaining
	 *
	 * @param boolean $enable
	 * @return Siteturbine_FrontEnd_Database_List
	 */
	public function setDebug($enable=true) {
		$this->_debug = $enable ? true : false;
		$this->_zendDb->getProfiler()->setEnabled($this->_debug);
		return $this;
	}

	/**
	 * Prints out profiling information for queries executed
	 *
	 */
	public function showProfile($showQueries=false) {
		$profiler = $this->_zendDb->getProfiler();
		$totalTime    = $profiler->getTotalElapsedSecs();
		$queryCount   = $profiler->getTotalNumQueries();
		$longestTime  = 0;
		$longestQuery = null;
		
		if($showQueries) {
			print '<table border="1" cellspacing="0" cellpadding="5">';
		}
		foreach ($profiler->getQueryProfiles() as $i=>$query) {
			if($showQueries) {
				print '<tr><td>'.$i.'</td>';
				print '<td>'.$query->getElapsedSecs().'</td><td>'.$query->getQuery().'</td>';
				print '</tr>';
			}
			if ($query->getElapsedSecs() > $longestTime) {
				$longestTime  = $query->getElapsedSecs();
				$longestQuery = $query->getQuery();
			}
		}
		if($showQueries) {
			print '</table>';
		}
		print '<pre>';
		echo 'Executed ' . $queryCount . ' queries in ' . $totalTime . ' seconds' . "\n";
		echo 'Average query length: ' . $totalTime / $queryCount . ' seconds' . "\n";
		echo 'Queries per second: ' . $queryCount / $totalTime . "\n";
		echo 'Longest query length: ' . $longestTime . "\n";
		echo "Longest query: \n" . $longestQuery . "\n";
		print '</pre>';
	}
	
	/**
	 * Protected function that executes the built query if it needs to be
	 *
	 */
	protected function _execute() {
		if($this->_needsExecute) {
			$this->_rowIndex = 0;
			$this->_statement = $this->_select->query();
			$this->_needsExecute = false;
		}
	}
	
	public function _clone() {
		return clone $this;
	}
	
	public function __clone() {
		if ($this->_statement) {
			$this->_statement = clone $this->_statement;
		}
		
		if ($this->_select) {
			$this->_select = clone $this->_select;
		}
	}
	
	/**
	 * Function to handle pagination a results
	 * @param integer $currentPageNumber The current page number we're on
	 * @param integer $perPage Number of results per page to fetch
	 */
	public function paginate($currentPageNumber, $perPage) {
		// Clear out any previous limits imposed on the query.
		$this->reset(Zend_Db_Select::LIMIT_COUNT);
		$this->reset(Zend_Db_Select::LIMIT_OFFSET);
		
		// Affect the query.
		$totalCount = $this->count();
		$this->limitPage($currentPageNumber, $perPage);
		
		// Figure out the count on the page and store it.
		$offset = $this->_select->getPart(Zend_Db_Select::LIMIT_OFFSET);
		if (($offset + $perPage) > $totalCount) {
			// We are on the last page and may have less than the number per page.
			$this->_count = $totalCount - $offset;
		} else {
			// We are on a regular page.
			$this->_count = $perPage;
		}
		
		// Return the number of pages created.
		return ceil($totalCount / $perPage);
	}
	
	/**
	 * Creates and returns a new Item instance (but doesn't save it).
	 *
	 * @param  array $info - The array of info for the record.
	 */
	protected function _new($row=array()) {
		$class = $this->_class;
		return new $class($row, $this->tableName(), $this->_db);
	}
	
	/**
	 * Returns an array where the keys are values from a given
	 * attribute of each item and the values are the values from
	 * a different attribute.
	 *
	 * @param  string $keyAttribute - The attribute to be the keys in the array.
	 * @param  string $valueAttribute - The attribute to be the values in the array.
	 * @return array
	 */
	public function keyValue($keyAttribute, $valueAttribute) {
		$return = array();
		foreach ($this as $item) {
			$return[$item->$keyAttribute] = $item->$valueAttribute;
		}
		
		return $return;
	}
	
	/**
	 * Returns all the items in array form.
	 *
	 * @return array
	 */
	public function toArray() {
		$return = array();
		foreach ($this as $item) {
			$return[] = $item->info();
		}
		
		return $return;
	}
	
	/**
	 * Returns the IDs of the items in the list.
	 *
	 * @return array
	 */
	public function ids() {
		$return = array();
		foreach ($this as $item) {
			$return[] = $item->id;
		}
		
		return $return;		
	}
	
	public function itemClass() {
		return $this->_class;
	}
	
	/**
	 * Implements countable, returns count of records
	 */
	public function count() {
		if ($this->_count !== false) {
			return $this->_count;
		}
		
		// Originally, we chose to replace the selected fields part of the query with a COUNT(*), however
		// we ran into several conditions where this would not work such as if the query has a join, a limit,
		// a group by, a sub-select as a field, or orders by a derived field.  Therefore, we do a sub-select
		// of our own to find the count.
		$this->_count = (int)$this->_zendDb->fetchOne('SELECT COUNT(*) as rowCount FROM ('.$this->query().') as t1');
		return $this->_count;
	}
	
	/**
	 * Returns current item for iterator usage
	 *
	 * @return SimpleDb_Item
	 */
	public function current() {
		return $this->_currentItem;
	}
	
	/**
	 * Returns index of current item
	 *
	 * @return integer
	 */
	public function key() {
	    return $this->_rowIndex;
	}
	
	/**
	 * Increments iterator to next item
	 *
	 */
	public function next() {
		$this->_rowIndex += 1;
	}
	
	/**
	 * Resets iterator to beginning
	 *
	 */
	public function rewind() {
		$this->_needsExecute = true;
	}
	
	/**
	 * Returns true if there's a valid entry for current iterator position
	 *
	 * @return boolean
	 */
	public function valid() {
		$this->_execute();
		$item = $this->_statement->fetch();
		if($item) {
			$class = $this->_class;
			$this->_currentItem = new $class($item, $this->tableName(), $this->_db);
			
			return true;
		}
	    return false;
	}
	
	/** 
	 * ArrayAccess functions
	 */
	public function offsetExists($offset) {
		// PDO does not support scrollable cursor for MySQL, so we can't use the cursor
		// and offset parameters of _statement->fetch().
		// Therefore, our only real option is to put a limit on the list temporarily and
		// then reset it.
		$previousLimitCount = $this->_select->getPart(Zend_Db_Select::LIMIT_COUNT);
		$previousLimitOffset = $this->_select->getPart(Zend_Db_Select::LIMIT_OFFSET);
		
		$this->limit(1, $offset);
		$this->_execute();
		$item = $this->_statement->fetch();
		$this->reset(Zend_Db_Select::LIMIT_COUNT);
		$this->reset(Zend_Db_Select::LIMIT_OFFSET);
		$this->limit($previousLimitCount, $previousLimitOffset);
		
		if ($item) {
			return true;
		}
	}
	
	/**
	 * Returns a specific record given it's offset/index
	 * @param integer $offset Offset of record to fetch
	 */
	public function offsetGet($offset) {
		// PDO does not support scrollable cursor for MySQL, so we can't use the cursor
		// and offset parameters of _statement->fetch().
		// Therefore, our only real option is to put a limit on the list temporarily and
		// then reset it.
		$previousLimitCount = $this->_select->getPart(Zend_Db_Select::LIMIT_COUNT);
		$previousLimitOffset = $this->_select->getPart(Zend_Db_Select::LIMIT_OFFSET);
		
		$this->limit(1, $offset);
		$this->_execute();
		$item = $this->_statement->fetch();
		$this->reset(Zend_Db_Select::LIMIT_COUNT);
		$this->reset(Zend_Db_Select::LIMIT_OFFSET);
		$this->limit($previousLimitCount, $previousLimitOffset);
		
		if ($item) {
			$class = $this->_class;
			return new $class($item, $this->tableName(), $this->_db);
		}
		return false;
	}
	
	/**
	 * Sets an offset, which is not supported
	 */
	public function offsetSet($offset, $value) {
		throw new SimpleDb_Exception('Programmer error: you can not set a list record via array access');
	}
	
	/**
	 * Unsets an offset, also not supported
	 */
	public function offsetUnset($offset) {
		throw new SimpleDb_Exception('Programmer error: you can not unset a list record via array access');
	}
	/* *************** */
}
