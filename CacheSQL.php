<?php

/**
 * A CacheSQL object
 * @param string $host
 * <p>The hostname usually localhost
 * @param string $username
 * <p>The MySQL user name</p>
 * @param string $password
 * <p>The password for the user $username</p>
 * @param string $database
 * <p>The database that the querys will be run on, this can be changed with CacheSQL::select_db</p>
 * @param string $apc_or_memcache
 * <p>Either "apc" or "memcache" (defaults to apc)</p>
 * @param string $cache_host
 * <p>The caching host to connect to (will only be used if cache is memcache)(defaults to mysql hostname)</p>
 * @param string $cache_port
 * <p>The port that the cache will connect on (will only be used if cache is memcache)(defaults to ini_get('memcache.default_port'))</p>
 */
class CacheSQL {
	private $host, $username, $password, $database, $cache_type, $cache_host, $cache_port;
	
	private $db_type;
	private $db_connection;
	
	private $cache_connection;

	// <editor-fold defaultstate="collapsed" desc="constructor">
	
	/**
	 * 
	 * @param string $host
	 * <p>The hostname usually localhost
	 * @param string $username
	 * <p>The MySQL user name</p>
	 * @param string $password
	 * <p>The password for the user $username</p>
	 * @param string $database
	 * <p>The database that the querys will be run on, this can be changed with CacheSQL::select_db</p>
	 * @param string $apc_or_memcache
	 * <p>Either "apc" or "memcache" (defaults to apc)</p>
	 * @param string $cache_host
	 * <p>The caching host to connect to (will only be used if cache is memcache)(defaults to the mysql hostname)</p>
	 * @param string $cache_port
	 * <p>The port that the cache will connect on (will only be used if cache is memcache)(defaults to ini_get('memcache.default_port'))</p>
	 */
	function __construct($host, $username, $password, $database, $apc_or_memcache = null, $cache_host = null, $cache_port = null) {
		$this->host = $host;
		$this->username = $username;
		$this->password = $password;
		$this->database = $database;
		
		if (isset($apc_or_memcache) && $apc_or_memcache && extension_loaded($apc_or_memcache)) {
			$this->cache_type = $apc_or_memcache;
		} else {
			if (extension_loaded('apc')) {
				$this->cache_type = 'apc';
			} elseif (extension_loaded('memcache')) {
				$this->cache_type = 'memcache';
			} else {
				die ("You don't apc or memcache installed");
			}
		}
		$this->cache_host = isset($cache_host) ? $cache_host : $host;
		$this->cache_port = isset($cache_port) ? $cache_port : ini_get('memcache.default_port');
	}
	// </editor-fold>
	

	// <editor-fold defaultstate="collapsed" desc="private functions: connect, sql_query, fetch, real_escape, mysqli_prepare, PDO_prepare, set, get">
	private function cache_connect() {
		if ($this->cache_type == 'memcache' && !$this->cache_connection) {
			$this->cache_connection = new Memcache;
			$this->cache_connection->addServer($this->cache_host, $this->cache_port) or die ("Couldn't connect to the memcache server");
		}
	}

	private function connect() {
		$this->cache_connect();
		
		if (!$this->db_connection) {
			if (extension_loaded('mysqli')) {
				$this->db_type = 'mysqli';
				$this->db_connection = mysqli_connect($this->host, $this->username, $this->password, $this->database) or 
						die("couldn't set up the connection to the datebase");
			} else {
				$this->db_type = 'mysql';
				$this->db_connection = mysql_connect($this->host, $this->username, $this->password);
				if (!$this->db_connection or !mysql_select_db($this->database, $this->db_connection)) {
					die ("couldn't set up the connection to the datebase");
				}
			}
		}
	}
	private function sql_query($query) {
		return ($this->db_type == 'mysqli') ?
			mysqli_query($this->db_connection, $query) :
			mysql_query($query, $this->db_connection);
	}
	private function fetch($result) {
		return ($this->db_type == 'mysqli') ?
			mysqli_fetch_assoc($result) :
			mysql_fetch_assoc($result);
	}
	private function real_escape($string) {
		return ($this->db_type == 'mysqli') ?
			mysqli_real_escape_string($this->db_connection, $string) :
			mysql_real_escape_string($string, $this->db_connection);
	}
	
	/**
	 * A mysqli styled prepared statement. There also is a PDO flavored one
	 * @param string $str <p>
	 * The query string with placeholders as ? (question marks)</p>
	 * @param array $filler_array <p>
	 * The values that the placeholder should get replaced with</p>
	 * @param int $cached_time <p>
	 * An integer of seconds to cache the result of this particular query
	 * </p>
	 * @param bool $force <p>
	 * Forces the query to be re-evaluated even if it's cache didn't expire</p>
	 * @return array an array (of associative arrays) of each row matched
	 */
	private function mysqli_prepare($str, $filler_array, $cached_time = 60, $force = false) {
		// order and validity (and safeness) of the query doesn't matter, just need a consistent hash
		$hashed_query = sha1(preg_replace('/\s+/', ' ', trim(($str . implode(' ', $filler_array)))));
		
		$this->cache_connect();
		if (!$force && $cached_result = $this->get($hashed_query)) {
			return $cached_result;
		} else {
			$this->connect();
			
			// now we insert them into the $str
			$str_array = explode('?', $str);
			$query = array($str_array[0]);
			$count = count($str_array);
			for ($i = 1; $i < $count ; $i++) {
				$query[] = $this->real_escape($filler_array[$i - 1]); // don't forget to escape them
				$query[] = $str_array[$i];
			}
			$query = implode('', $query);
			
			// do the query
			$result = $this->sql_query($query);
			if (!$result) return false;
			
			// build the result array
			$return = array();
			while($row = $this->fetch($result)) {
				$return[] = $row;
			}
			
			// cache it and return it
			$this->set($hashed_query, $return, $cached_time);
			return $return;
		}
	}
	
	/**
	 * A PDO styled prepared statement. There also is a mysqli flavored one
	 * @param string $str <p>
	 * The query string with placeholders as :value</p>
	 * @param array $filler_array <p>
	 * The values that the placeholder should get replaced with i.e. array('value' => '15') no : (colon) in the key</p>
	 * @param int $cached_time <p>
	 * An integer of seconds to cache the result of this particular query
	 * </p>
	 * @param bool $force <p>
	 * Forces the query to be re-evaluated even if it's cache didn't expire</p>
	 * @return array an array (of associative arrays) of each row matched
	 */
	private function PDO_prepare($str, $filler_array, $cached_time = 60, $force = false) {
		// first we need a consistant (possibly unsafe) query string to check if we have it cached already. We need to save to this hash too.
		$str_array = preg_split('#(:[^\s]+)#', $str, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
		foreach ($str_array as &$value) {
			if ($value[0] == ':') {
				if (array_key_exists($value, $filler_array)) {
					$value = $filler_array[$value];
				} else {
					return false;
				}
			}
		}
		$unsafeQuery = preg_replace('# ?= ?#', '=', preg_replace('#\s+#', ' ', implode('', $str_array)));
		$hashed_query = sha1($unsafeQuery);

		$this->cache_connect();
		if (!$force && $cached_result = $this->get($hashed_query)) {
			return $cached_result;
		} else {
			$this->connect();
			
			// now we insert them into the $str
			$str_array = preg_split('#(:[^\s]+)#', $str, 0, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
			foreach ($str_array as &$value) {
				if ($value[0] == ':') {
					if (array_key_exists($value, $filler_array)) {
						$value = $this->real_escape($filler_array[$value]);
					} else {
						return false;
					}
				}
			}
			// which gives us a safe query string
			$query = preg_replace('# ?= ?#', '=', preg_replace('#\s+#', ' ', implode('', $str_array)));

			// do the query
			$result = $this->sql_query($query);
			if (!$result) return false;
			
			// build the array
			$return = array();
			while($row = $this->fetch($result)) {
				$return[] = $row;
			}
			
			// cache it and return it
			$this->set($hashed_query, $return, $cached_time);
			return $return;
		}
	}

	
	private function set($key, $value, $cache_time = 60) {
		return ($this->cache_type == 'apc') ?
			apc_add($key, $value, $cache_time) :
			$this->cache_connection->set($key, $value, MEMCACHE_COMPRESSED, $cache_time);
	}
	private function get($key) {
		return ($this->cache_type == 'apc') ?
			apc_fetch($key) :
			$this->cache_connection->get($key);
	}
	// </editor-fold>
	

	
	// <editor-fold defaultstate="collapsed" desc="public functions: affected_rows, errno, error, select_db, get_connection, get_connection_type">
	public function affected_rows() {
		if (!$this->db_connection) { return false; }
		return $this->db_type == 'mysqli' ?
			mysqli_affected_rows($this->db_connection) :
			mysql_affected_rows($this->db_connection);
	}
	public function errno() {
		if (!$this->db_connection) { return false; }
		return $this->db_type == 'mysqli' ?
			mysqli_errno($this->db_connection) :
			mysql_errno($this->db_connection);
	}
	public function error() {
		if (!$this->db_connection) { return false; }
		return $this->db_type == 'mysqli' ?
			mysqli_error($this->db_connection) :
			mysql_error($this->db_connection);
	}
	public function select_db($database) {
		$this->database = $database; // for the next connect() call
		if ($this->db_connection) { // for the active connection
			if ($this->db_type == 'mysqli') {
				mysqli_select_db($this->db_connection, $database);
			} else {
				mysql_select_db($database, $this->db_connection);
			}
		}
	}
	public function get_connection() {
		return $this->db_connection;
	}
	public function get_connection_type() {
		return $this->db_type;
	}

	// </editor-fold>


	
	/**
	 *
	 * @param string $query <p>
	 * The query string
	 * </p>
	 * @param int $cached_time <p>
	 * An integer of seconds to cache the result of this particular query
	 * </p>
	 * @param bool $force <p>
	 * Forces the query to be re-evaluated even if it's cache didn't expire</p>
	 * @return array an array (of associative arrays) of each row matched
	 */
	public function query($query, $cached_time = 60, $force = false) {
		$return = array();
		$query = preg_replace('/\s+/', ' ', trim($query)); // remove extra whitespace, since it's all the same query
		$hashed_query = sha1($query);

		$this->cache_connect();
		
		if (!$force && $cached_result = $this->get($hashed_query)) {
			return $cached_result;
		} else {
			$this->connect();
			$result = $this->sql_query($query);
			if (!$result) return false;
			
			while($row = $this->fetch($result)) {
				$return[] = $row;
			}
			
			$this->set($hashed_query, $return, $cached_time);
			return $return;
		}
	}
	

	/**
	 * A prepared statement that is executed immediatly
	 * <p>This can be in the form 'select * from ?' or 'select * from :table' or even 'select ? from ?' or 'select :cols from :table'</p>
	 * <p>Note: The fill in values will always be escaped using mysqli_real_escape_string so it should be safe to use on user input</p>
	 * @param string $str <p>
	 * The query string with placeholders as either ? or :value</p>
	 * @param array $filler_array <p>
	 * The values that the placeholder should get replaced with, question mark placeholders 
	 * need a numbered array (zero indexed), :value placeholders need an associative array (':value' => 1)</p>
	 * <p>If the array doesn't have colons as the start of the key, you can run it through CacheSQL::preparify($array)
	 * @param int $cached_time <p>
	 * An integer of seconds to cache the result of this particular query
	 * </p>
	 * @param bool $force <p>
	 * Forces the query to be re-evaluated even if it's cache didn't expire</p>
	 * @return array an array (of associative arrays) of each row matched
	 */
	public function prepare($str, $filler_array, $cached_time = 60, $force = false) {

		// we just need to determain if it's a mysqli prepared statement or a PDO prepared statement
		// there's higher chances of an array just happening to have numbered indexes over keys that match :values
		if (is_numeric(strpos($str, ':')) && preg_match('#:[^\s]*#', $str, $match)) {
			if (array_key_exists($match[0], $filler_array)) {
				return $this->PDO_prepare($str, $filler_array, $cached_time, $force);
			}
		} elseif (is_numeric(strpos($str, '?'))) {
			return $this->mysqli_prepare($str, $filler_array, $cached_time, $force);			
		} else {
			// regular query
			if (gettype($filler_array) == 'array') {
				// could have been a prepared statement if some placeholders would have been set
				return $this->query($str, $cached_time, $force);
			} else {
				// set some default values
				$cached_time = $filler_array ? $filler_array : 60;
				$force = $cached_time ? $cached_time : false;
				return $this->query($str, $cached_time, $force);
			}
		}
	}
	
	public function close() {
		if (!$this->db_connection) return;
		return $this->db_type == 'mysqli' ?
			mysqli_close($this->db_connection) :
			mysql_close($this->db_connection);
	}

	
	/**
	 * 
	 * @param array $array
	 * <p>An array that will be copied with a : (colon) prepended to each key
	 * @return array
	 * <p>The copied array with colons (:) prepended to each key
	 */
	public function preparify($array) {
		$return = array();
		foreach ($array as $key => $value) {
			if ($key[0] != ':') {
				$return[':' . $key] = $value;
			} else {
				$return[$key] = $value;
			}
		}
		return $return;
	}
}


