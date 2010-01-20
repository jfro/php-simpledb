<?php
/**
 * Simble DB
 *
 * Handles interaction with database. Includes cache and debug functionality.
 *
 * @author Jeremy Knope <jerome@buttered-cat.com>
 * @author Matt James <matt@rainstormconsulting.com>
 * @author Raymond J. Kolbe <ray@rainstorminc.com>
 *
 * @license http://www.opensource.org/licenses/lgpl-2.1.php GNU Lesser General Public License
 *
 * @package SimpleDb
 */
 
/**
 * Simble DB
 *
 * Handles interaction with database. Includes cache and debug functionality.
 *
 * @package SimpleDb
 */
class SimpleDb {
	/**
	 * Zend_Db object used for all queries or Zend_Db_Select generation
	 *
	 * @var Zend_Db
	 */
	protected $_db;

	/**
	 * Array of valid table names in database
	 *
	 * @var array
	 */
	protected $_tableNames;

	/**
	 * Array of info for each table in the DB.  Lazily loaded.
	 *
	 * @var array
	 */
	protected $_tableInfo = array();

	/**
	 * Array map of table names to model class names
	 *
	 * @var array
	 */
	protected $_tableMap = array();

	/**
	 * Cache object if enabled
	 *
	 * @var Zend_Cache
	 */
	protected $_cache = false;

	/**
	 * Logging object if enabled
	 *
	 * @var Zend_Log
	 */
	protected $_logger = null;

	/**
	 * Notifies profiler if we are to debug the query
	 *
	 * @see enableQueryStats()
	 * @var bool
	 */
	protected $_debug = false;

	/**
	 * Constructor
	 *
	 * Creates new database instance with given sqlite database file path.
	 *
	 * @param string $database database file path
	 * @param string|Zend_Cache $cache Zend_Cache object or path to put a Zend Cache
	 */
	public function __construct($database, $cache = null) {
		$this->_db = $database;

		if ($cache) {
			$this->setCache($cache);
		}

		$this->_loadTableNames();
	}

	/**
	 * Get (magic)
	 *
	 * Dynamic attribute getter, returns an SimpleDb_List instance if table is found.
	 *
	 * @param string $key Attribute name (table name in this case)
	 * @return SimpleDb_List
	 */
	public function __get($key) {
		if ($this->_isValidTableName($key)) {
			if (!array_key_exists($key, $this->_tableMap)) {
				return new SimpleDb_List('SimpleDb_Item', $key, $this);
			}

			return new SimpleDb_List($this->_tableMap[$key], null, $this);
		}
	}

	/**
	 * Call
	 *
	 * Acts as a wrapper so we can forward method calls to Zend_Db for doing things
	 * like inserting or deleting records directly.
	 *
	 * <code>
	 * 		$db->delete('users', 'email LIKE "%@hotmail.com"');
	 * </code>
	 *
	 * @deprecated Also, acts as a shortcut to the SimpleDb_List::alias() method.
	 *
	 * <code>
	 * 		$db->people('peeps') // 'peeps' will now be the alias for this table in SQL.
	 * </code>
	 *
	 * @param string $method Name of the method being called.
	 * @param array $arguments An array of arguments passed into the method.
	 * @return mixed
	 */
	public function __call($method, $arguments) {
		if ($this->_isValidTableName($method)) {
			// There is a table named this, so we are assuming they are using
			// the deprecated alias() shortcut.
			$table = $method;
			return count($arguments) ? $this->$table->alias($arguments[0]) : $this->$table;
		}

		if (!method_exists($this->_db, $method)) {
			// Bail. TODO: should we throw an error?
			return;
		}

		return call_user_func_array(array($this->_db, $method), $arguments);
	}

	/**
	 * Enable Logging
	 *
	 * Enables logging to a file named simpleDb.log.
	 *
	 * @param string $path Full directory path to log file, simpleDB.log
	 */
	public function enableLogging($path) {
		$this->_logger = new Zend_Log(new Zend_Log_Writer_Stream($path.'simpleDb.log'));
	}

	/**
	 * Set Cache
	 *
	 * Sets a Zend_Cache or path for cache that data objects can use.
	 *
	 * @param string|Zend_Cache $cache The Zend_Cache or path to put a Zend_Cache
	 * @param integer $lifetime The default lifetime for data cached
	 * @throws SimpleDb_Exception
	 * @return void
	 */
	public function setCache($cache, $lifetime = 7200) {
		if (is_object($cache) && $cache instanceof Zend_Cache_Core) {
			$this->_cache = $cache;
		} else if (is_string($cache) && is_writable($cache)) {
			$frontendOptions = array(
			   'lifetime' => $lifetime,
			   'automatic_serialization' => true
			);

			$backendOptions = array(
				'cache_db_complete_path' => rtrim($cache, '/').'/simpleDbCache.db' // Directory where to put the cache files
			);

			$this->_cache = Zend_Cache::factory('Core', 'Sqlite', $frontendOptions, $backendOptions);
		} else {
			throw new SimpleDb_Exception(__METHOD__.' -- Cache value must be Zend_Cache or a writable path');
		}
	}

	/**
	 * Zend DB
	 *
	 * Returns the Zend_Db connection instance (actually Zend_Db_Adapter).
	 *
	 * @return Zend_Db_Adapter
	 */
	public function zendDb() {
		return $this->_db;
	}

	/**
	 * Enable Query Stats
	 *
	 * Enables the Zend_Db_Profiler to help with debugging.
	 *
	 * @param bool $enable
	 * @return void
	 */
	public function enableQueryStats($enable = true) {
		$this->_debug = $enable ? true : false;
		$this->zendDb()->getProfiler()->setEnabled($this->_debug);
	}

	/**
	 * Log Query Stats
	 *
	 * Prints out profiling information for queries executed.
	 *
	 * @param bool $showQueries If true, queries themselves are printed to screen
	 * @return void
	 */
	public function logQueryStats($showQueries = false) {
		$profiler = $this->zendDb()->getProfiler();
		$totalTime = $profiler->getTotalElapsedSecs();
		$queryCount = $profiler->getTotalNumQueries();
		$longestTime = 0;
		$longestQuery = null;
		$out = '';

		if ($showQueries) {
			$out .= '<table border="1" cellspacing="0" cellpadding="5">';
		}

		foreach ($profiler->getQueryProfiles() as $i => $query) {
			if ($showQueries) {
				$out .= '<tr><td>'.$i.'</td>';
				$out .= '<td>'.$query->getElapsedSecs().'</td><td>'.$query->getQuery().'</td>';
				$out .= '</tr>';
			}

			if ($query->getElapsedSecs() > $longestTime) {
				$longestTime  = $query->getElapsedSecs();
				$longestQuery = $query->getQuery();
			}
		}

		if ($showQueries) {
			$out .= '</table>';
		}

		$out .= 'Executed ' . $queryCount . ' queries in ' . $totalTime . ' seconds' . "\n";
		$out .= 'Average query length: ' . $totalTime / $queryCount . ' seconds' . "\n";
		$out .= 'Queries per second: ' . $queryCount / $totalTime . "\n";
		$out .= 'Longest query length: ' . $longestTime . "\n";
		$out .= "Longest query: \n" . $longestQuery . "\n";

		if ($this->_logger) {
			$this->_logger->debug($out);
		} else {
			error_log($out);
		}
	}

	/**
	 * Destructor
	 *
	 * Logs query stats on object destruction.
	 *
	 * @return void
	 */
	public function __destruct() {
		if ($this->_debug && $this->_logger) {
			$this->logQueryStats();
		}
	}

	/**
	 * Cache
	 *
	 * Returns cache object.
	 *
	 * @return Zend_Cache
	 */
	public function cache() {
		return $this->_cache;
	}

	/**
	 * Add Classes
	 *
	 * Takes variable number of string arguments and adds them to the internal list of
	 * available Simple_Db_Item classes we can instantiate.
	 *
	 * @params string $args Variable number of strings to represent class names.
	 * @return void
	 */
	public function addClasses() {
		$classes = func_get_args();

		if (is_array($classes[0])) {
			$classes = $classes[0];
		}

		foreach ($classes as $class) {
			eval('$tableName = '.$class.'::$table;');
			$this->_tableMap[$tableName] = $class;
		}
	}
	
	/**
	 * Get Class for Table
	 *
	 * Returns the class name associated with the given table.
	 *
	 * @param string $tableName The table to look up
	 * @return string The class name
	 */
	public function getClassForTable($tableName) {
		if (!isset($this->_tableMap[$tableName])) {
			return false;
		}
		
		return $this->_tableMap[$tableName];
	}

	/**
	 * Attach
	 *
	 * Attaches a given SQLite database file into this connection.
	 *
	 * @param string $dbFile Database file to attach
	 * @param string $dbName Alias to give attached database file
	 * @return void
	 */
	public function attach($dbFile, $dbName) {
		$this->_db->query('ATTACH DATABASE "'.$dbFile.'" AS "'.$dbName.'"');

		$tableNames = $this->_db->fetchCol("SELECT name FROM ".$dbName.".sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'");
		foreach ($tableNames as $tableName) {
			if (in_array($tableName, $this->_tableNames)) {
				continue;
			}

			$this->_tableNames[] = $tableName;
		}

		$this->_saveTableNamesCache();
	}

	/**
	 * Execute
	 *
	 * Runs the given query while optionally using Zend_Db's quoteInto if multiple
	 * arguments are given.
	 *
	 * @see quoteInto
	 * @param string $query SQL String with placeholders if desired
	 * @param string $args Variable number of arguments for replacement values in $query
	 * @return bool
	 */
	public function execute($query) {
		$args = func_get_args();

		if (count($args) == 1) {
			$sql = $query;
		} else {
			$sql = call_user_func_array(array($this, 'quoteInto'), $args);
		}

		$statement = $this->zendDb()->query($sql);

		return $statement ? true : false;
	}

	/**
	 * Query
	 *
	 * Runs given query and replacements and returns the executed query statement to call fetch methods on it.
	 *
	 * @param string $query SQL string with placeholders if desired, @see quoteInto
	 * @param string $args Variable number of arguments for replacement values
	 * @return Zend_Db_Statement_Interface
	 */
	public function query($query) {
		$args = func_get_args();
		
		if (count($args) == 1) {
			$sql = $query;
		} else {
			$sql = call_user_func_array(array($this, 'quoteInto'), $args);
		}
		
		$statement = $this->zendDb()->query($sql);

		if (!is_object($statement)) {
			throw new SimpleDb_Exception('Failed to execute query: '.$query);
		}

		return $statement;
	}

	/**
	 * Quote Into
	 *
	 * This method overrides Zend_Db::where() so that we can have a little
	 * more flexibility.  Specifically, there are 6 use cases for this method.
	 *
	 * $db->quoteInto('id = 5');
	 *
	 *		A SQL string is passed.  If this is all that is passed, we forward along
	 * 		to the Zend_DB_Select method.
	 *
	 * $db->quoteInto('firstname = ? OR lastname = ?', 'James');
	 *
	 *		A SQL string is passed along with a single replacement value for all "?".
	 * 		In this case, we pass everything along as well since Zend_DB_Select handles this
	 *		use case.
	 *
	 * $db->quoteInto('firstname = ? AND lastname = ?', 'Tom', 'Jones');
	 *
	 *		A SQL string is passed along with a variable argument list, each representing
	 * 		a "?" placeholder value in the string.
	 *
	 * $db->quoteInto('firstname = :0 AND lastname = :1', 'Tom', 'Jones');
	 *
	 * 		A SQL string is passed along with a variable argument list, each representing
	 * 		a ":index" placeholder value in the string.
	 *
	 * $db->quoteInto('firstname = :0 AND lastname = :1', array('Tom', 'Jones'));
	 *
	 *		A SQL string is passed along with an array where each value represents
	 * 		a ":index" placeholder value in the string.
	 *
	 * $db->quoteInto('firstname = :firstname AND lastname = :lastname', array('firstname' => 'Tom', 'lastname' => 'Jones'));
	 *
	 *		A SQL string is passed along with an array where each value represents
	 * 		a ":key" placeholder value in the string.
	 *
	 * @param string $sql A SQL string
	 * @param string $args Variable list of arguments to replace into the string
	 * @return string The SQL string with escaped quotes
	 */
	public function quoteInto($sql) {
		$args = func_get_args();

		if (count($args) < 2 || (count($args) == 2 && !is_array($args[1]))) {
			// Nothing special happening here, pass it through to the default method.
			// We do this instead of calling $this->_zendDb->quoteInto($sql, $args[1])
			// because we're not sure if args[1] is there or not.
			return call_user_func_array(array($this->_db, 'quoteInto'), $args);
		}

		// We are going to run our own replacement method.
		$replacements = $args;
		array_shift($replacements);

		if (is_array($replacements[0])) {
			// Here, we are passed an array of replacements with key/value combos
			// that correspond to ":key" => "sqlValueToBeEscaped".
			$replacements = $replacements[0];
		}

		// If we are using "?" placeholders, we need to change them over to indexed-placeholders.
		$pieces = explode('?', $sql);
		$sql = '';

		foreach ($pieces as $i => $piece) {
			$sql .= $piece;

			if ($i == count($pieces) -1) {
				// We are on the last one, skip.
				break;
			}

			$sql .= ':'.$i;
		}

		// At this point, we are using ":key" placeholders.
		$pieces = preg_split('/:(\w+)\b/', $sql, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);
		$sql = '';

		foreach ($pieces as $key => $piece) {
			if ($key % 2 == 0) {
				// We are on a piece of the query.
				$sql .= $piece;
			} else {
				// We are on a placeholder.
				$sql .= $this->_db->quote($replacements[$piece]);
			}
		}

		return $sql;
	}

	/**
	 * Info for Table
	 *
	 * Returns an array of info for the given table where the keys
	 * are the columns and the values are info about that column.
	 *
	 * @see Zend_Db::describeTable()
	 * @param string $tableName Name of the table we're looking up.
	 * @return array
	 */
	public function infoForTable($tableName) {
		if (!isset($this->_tableInfo[$tableName])) {
			// We don't have it in this SimpleDb instance yet, so look it up.
			$this->_tableInfo[$tableName] = $this->_db->describeTable($tableName);
		}

		return $this->_tableInfo[$tableName];
	}

	/**
	 * Refresh Cache
	 *
	 * Clears all cached information for SimpleDb and refreshes items
	 * that will not refresh themselves.
	 *
	 * @param void
	 * @return void
	 */
	public function refreshCache() {
		$this->_cache->clean(Zend_Cache::CLEANING_MODE_ALL);
		$this->_loadTableNames();

		$this->_tableInfo = array();
	}

	/**
	 * Reload Table Names
	 *
	 * Reloads the table names found in the DB.
	 *
	 * @see SimpleDb::_loadTableNames()
	 * @param void
	 * @param void
	 */
	public function reloadTableNames() {
		$this->_loadTableNames();
	}

	/**
	 * Save Table Names Cache
	 *
	 * Saves the current value of $_tableNames into the cache.
	 *
	 * @return void
	 */
	protected function _saveTableNamesCache() {
		if (!$this->_cache) {
			return;
		}

		// store table list for 12 hours
		$this->_cache->save($this->_tableNames, 'rsc_simpledb_tablenames', array(), 43200);
	}

	/**
	 * Is Valid Table Name
	 *
	 * Checks to see if the given string represents a valid table in the DB.
	 *
	 * @param string $tableName Name of the table we are validating
	 * @return bool True if table exists, false otherwise
	 */
	protected function _isValidTableName($tableName) {
		return in_array($tableName, $this->_tableNames);
	}

	/**
	 * Load Table Names
	 *
	 * Looks at the DB and produces an array of valid table names.
	 *
	 * @return void
	 */
	private function _loadTableNames() {
		if ($this->_cache && $tableNames = $this->_cache->load('rsc_simpledb_tablenames')) {
			$this->_tableNames = $tableNames;
		}

		$this->_tableNames = $this->_db->listTables();

		if ($this->_cache) {
			$this->_saveTableNamesCache();
		}
	}
}
