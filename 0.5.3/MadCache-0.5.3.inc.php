<?php

/*
 * Simple caching object can be used to cache entire pages or
 * part of a page. Can also be used to cache variables such as query results.
 * This is also heavily inspired by Nathan Faber's phpCache from many years back.
 *
 * Changelog:
 * 4/18/2011 - 
 * Added GZ compression to the cache routine. The trade off is going to be the fact that if I am 
 * going to have to do decompression before display we can no longer use the include file cache
 * read method. This will slow our cache down considerably in order to save disk space. On the faster
 * boxes it won't matter but the reason I'm adding GZ is for EditX disk.
 */


/**
 * @TODO Make it has the host folder into the same # of folders as the other
 * hashing algo. Having MAX_HASH x # OF HOSTS folders is a little redic. 
 */

class MadCache
{
	const CACHE_VERSION = "0.5.3";
	const CACHE_TYPE = "OB"; // Use output buffering to cache the parts you want. Or MANUAL to start the cache and store variables/data
	const CACHE_TAG = true; // This sets whether or not you want a <!-- coment --> with some data in it
	const CACHE_PATH = "/var/www/mad_cache/files"; // path to cache folder
	const CACHE_MAX_HASH = 10; // The max # of folders to seperate cache files into. Don't change unless you completely wipe the entire cache structure.

	/*
	 * CACHE_CHECK_DIR
	 *  ONLOAD - This will create each part of the directory structure as it is needed. Slower than the TRUE/FALSE version.
	 *  STAT - This will create stat files that way the dir structure routine is created once.
	 *  NOCHECK - This is if you are 100% sure the cache directory or cache/host_directory has a .madCache.stat file in it. Considerable performance increase.
	 */
	const CACHE_CHECK_DIR = "ONLOAD";

	const CACHE_STORAGE_PERM = 0775; // Default permissions of a cache storage directory. Be careful with 0700 etc if you can't override apache.

	const CACHE_PER_HOST = TRUE; // If this is true, you can put different caches in diff folders by setting the key like FOLDER_NAME::whatever ..

	const CACHE_KEY_METHOD = "md5"; // crc32, sha1, md5

	const CACHE_LOG = FALSE; // For debug purposes.

	const CACHE_LOG_TYPE = "combined"; // host, combined

	const CACHE_LOG_LIMIT = 100000; // size in bytes

	const CACHE_MAX_AGE = 604800; // max cache age - used for garbage collection on simple cache files.

	const CACHE_GC = -1; // set to -1 if you use the cron cleaner.

	/**
	 * Use a single install to cover multiple web-roots
	 */
	const CACHE_MULTI_INSTALL = false;

	/**
	 * Never expire cache files.
	 */
	const CACHE_NO_EXPIRE = FALSE; // Use this to override the cache expire, ie: if u dont want anything to expire while testing or for emergency

	/**
	 * Compress Data to save space
	 */
	const CACHE_USE_GZIP = TRUE;


	private $_now = '';
	private $_pretty_time;
	private $_key;
	private $_expire;
	private $_cache_content;
	private $_original_key;
	private $_host_folder;
	private $_cache_file;
	private $_cache_mtime;
	private $_cache_age;
	private $_cache_path;
	private $_cache_running = array();
	private $_cache_variables = array();
	private $_cache_method = "diskread";
	private $_calling_script;
	private $_initialized = FALSE;
	private $_default_key = FALSE;
	public $cache_tag_out = '';
	public $check_only = FALSE;
	public $cache_tag_or = FALSE;
	protected $cache_data;

	function __construct()
	{
		$this->initialize();
	}

	function initialize()
	{
		date_default_timezone_set('America/New_York');
		$this->_now = time();
		$this->_pretty_time = date("Y-m-d h:i:s", $this->_now);
		if ( self::CACHE_MULTI_INSTALL == true )
		{
			$calling_script = !isSet($_SERVER['DOCUMENT_ROOT']) || empty($_SERVER['DOCUMENT_ROOT']) || strlen($_SERVER['DOCUMENT_ROOT']) < 2 ? "default" : $_SERVER['DOCUMENT_ROOT'];
			$calling_script = strtolower($calling_script);
			if ( strpos($calling_script, '.') )
				$this->_calling_script = $this->make_hash($calling_script, "crc32");
			$this->_cache_path = self::CACHE_PATH . "/$this->_calling_script";
			if ( !is_dir($this->_cache_path) )
			{
				@mkdir($this->_cache_path, self::CACHE_STORAGE_PERM);
				$this->_write_flock(self::CACHE_PATH . "/mapping", "$this->_calling_script => " . $_SERVER['DOCUMENT_ROOT'] . "\n", "a+");
			}
		}
		else
		{
			$this->_cache_path = self::CACHE_PATH;
		}
		$this->_initialized = TRUE;
	}

	function __destruct()
	{
		$this->cache_reset();
	}

	public function start_cache($EXPIRE, $KEY=NULL, $CHECK_ONLY=FALSE)
	{
		/* Use default Cache Key ..
		 * This will make the key unique to the page based on request_uri, post and get
		 */
		if ( $this->_initialized == FALSE )
			if ( !$this->initialize() )
				return TRUE;

		/*
		 * Emergency Mode
		 */
		if ( self::CACHE_NO_EXPIRE == true && $EXPIRE > 0 )
			$EXPIRE = 365 * 86400;

		if ( $KEY == NULL )
		{
			$this->_default_key = TRUE;
			$KEY = $this->default_key();
		}

		$this->_original_key = $KEY;
		if ( self::CACHE_PER_HOST == TRUE )
		{
			if ( $this->_default_key == TRUE || !strstr($this->_original_key, "::") )
			{
				$this->_host_folder = $_SERVER['HTTP_HOST'];
			}
			else
			{
				$tmp_key = explode("::", $this->_original_key);
				$this->_host_folder = $tmp_key[0];
			}
			/*
			 * Protect against weird host folders.
			 */
			if ( preg_match("/[^A-Za-z0-9\.]/", $this->_host_folder, $matches) || $this->_host_folder == "0" || $this->_host_folder == "" )
				$this->_host_folder = "garbage_bin";
		}
		else if ( strpos($this->_original_key, "::") !== FALSE )
		{
			list($this->_host_folder, $this->_original_key) = explode("::", $this->_original_key);
		}
		
		/*
		 * Do any modifications to the cache_path based on the host asccessing
		 * the cached iteme.
		 */
		$this->_host_folder = strtolower($this->_host_folder);
		if ( strpos($this->_host_folder, "www") !== FALSE )
			$this->_host_folder = str_replace("www.", "", $this->_host_folder);
		
		/*
		 * Append host folder to the path
		 */
		$this->_cache_path .= "/$this->_host_folder";

		if ( !is_dir($this->_cache_path) )
			@mkdir($this->_cache_path, self::CACHE_STORAGE_PERM);
		$this->_expire = $EXPIRE;
		$this->_key = $this->make_hash($this->_original_key);
		$this->_cache_path = $this->make_path();
		$this->_cache_file = $this->_cache_path . "/" . $this->_key;

		/*
		 * This will be used eventually for caching specific items
		 * such as database results, variables, sessions, etc.
		 */
		if ( self::CACHE_TYPE == "MANUAL" )
		{
			return TRUE;
		}

		if ( $CHECK_ONLY === TRUE )
		{
			return $this->cache_check();
		}

		if ( $this->cache_check() == TRUE )
		{
			$this->_cache_running[$this->_key] = TRUE;
			ob_start();
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	public function end_cache()
	{
		if ( isSet($this->_cache_running[$this->_key]) && $this->_cache_running[$this->_key] == TRUE )
		{
			$level = ob_get_level();
			$buffer_content = array();
			for ( $i = 0; $i < $level; $i++ )
			{
				$buffer_length = ob_get_length();
				if ( $buffer_length !== FALSE )
				{
					$buffer_content[] = ob_get_clean();
				}
			}
			$this->_cache_content = implode("", array_reverse($buffer_content));
			$this->cache_this($this->_cache_content);
			$this->_cache_running[$this->_key] = FALSE;
			if ( self::CACHE_TAG )
				$this->cache_tag("CACHE GENERATED AND WRITTEN");
		}
		$this->echo_cache();
	}

	public function echo_cache()
	{
		if ( strlen($this->_cache_content) > 0 )
			echo($this->_cache_content);
	}

	public function cache_reset()
	{
		$this->_key = $this->_expire = $this->_cache_content = $this->_original_key = $this->_cache_file = $this->_cache_file = "";
		$this->_cache_mtime = $this->_cache_age = $this->_cache_path = $this->_calling_script = "";
		$this->_initialized = FALSE;
		$this->_cache_running = array();
		$this->_cache_variables = array();
		$this->check_only = FALSE;
	}

	/**
	 *
	 * @param type $how
	 * @param type $what 
	 */
	public function cache_expire($how="key", $what=NULL)
	{
		/*
		 * Options for expiring are going to be all or by host
		 */
		if ( $how == "key" )
		{
			$cache_file = $this->_cache_path;
			if ( $what == NULL )
				$what = $this->default_key();
			if ( self::CACHE_PER_HOST == TRUE )
			{

				if ( $what == NULL || !strstr($what, "::") )
				{
					$host_folder = $_SERVER['HTTP_HOST'];
				}
				else
				{
					$tmp_key = explode("::", $what);
					$host_folder = $tmp_key[0];
				}
				$cache_file .= "/" . $host_folder;
			}
			$what = $this->make_hash($what);
			$path = $this->make_path($what);
			$cache_file .= $path . "/" . $what;
			if ( file_exists($cache_file) )
				unlink($cache_file);
			$this->cache_log("Manually expired $cache_file", TRUEs);
		}
		else if ( $how == "host" )
		{
			$cache_path = $this->_cache_path;
			if ( $what == NULL && self::CACHE_PER_HOST == true )
			{
				$what = $_SERVER['HTTP_HOST'];
			}
			$cache_path .= "/" . $what;
			if ( is_dir($cache_path) )
			{
				for ( $a = 0; $a < self::CACHE_MAX_HASH; $a++ )
				{
					$thedira = $cache_path . "/$a/";
					if ( !is_dir($thedira) )
						continue;
					for ( $b = 0; $b < self::CACHE_MAX_HASH; $b++ )
					{
						$thedirb = $cache_path . "/$a/$b/";
						if ( !is_dir($thedirb) )
							continue;
						for ( $c = 0; $c < self::CACHE_MAX_HASH; $c++ )
						{
							$thedirc = $cache_path . "/$a/$b/$c";
							if ( !is_dir($thedirc) )
								continue;
							$this->_clean_dir($thedirc);
							rmdir($thedirc);
						}
						rmdir($thedirb);
					}
					rmdir($thedira);
				}
			}
			@unlink($cache_path . "/.madCache.stat");
		}
	}

	public function set_method($method)
	{
		switch ($method)
		{
			CASE 'include': $this->_cache_method = "include";
				break;
			CASE 'diskread': $this->_cache_method = "diskread";
				break;
		}
	}

	public function cache_variable($var)
	{
		if ( !is_array($var) )
		{
			$this->cache_log("Attempted to cache a non array variable Must be an array(\"var_name\"=>\"var_value\")");
		}
		else
		{
			foreach ( $var as $k => $v )
			{
				$this->_cache_variables[$k] = $v;
			}
			$this->cache_log("Cached a set of variables");
		}
	}

	public function cache_method($method)
	{
		$options = array("diskread", "include");
		if ( in_array($options, $method) )
		{
			$this->_cache_method = $method;
		}
	}

	private function cache_tag($action)
	{
		if ( $this->cache_tag_or == TRUE )
			return;

		if ( !isSet($_SERVER['HTTP_HOST']) )
			return;

		$pretty_cache_mtime = $this->_cache_mtime != 0 ? date("Y-m-d h:i:s", $this->_cache_mtime) : 0;
		$vers = self::CACHE_VERSION;
		$this->cache_tag_out = "<!--
	VERSION:     MadCache-{$vers}
	TIME NOW:    $this->_pretty_time
	ACTION:      $action
	CACHE AGE:   $this->_cache_age seconds
	CACHE MTIME: $pretty_cache_mtime
	CACHE KEY:   $this->_key
	ORIG. KEY:   $this->_original_key
	EXPIRE:      $this->_expire
	CACHE PATH:  $this->_cache_path
	CACHE FILE:  $this->_cache_file
	CACHE HOST:  $this->_host_folder
	IP #:	{$_SERVER['REMOTE_ADDR']}
-->\n";

		//register_shutdown_function('echo', $this->cache_tag_out);
	}

	public function cache_check()
	{
		if ( file_exists($this->_cache_file) )
		{
			$this->_cache_mtime = filemtime($this->_cache_file);
			$this->_cache_age = $this->_now - $this->_cache_mtime;
			if ( $this->_cache_age > $this->_expire )
			{
				$this->cache_log("Cache file has expired ($this->_cache_age secs / exp: $this->_expire)");
				return TRUE;
			}
			else
			{
				$this->cache_log("Cache file is current ($this->_cache_age secs / exp: $this->_expire)");
				return FALSE;
			}
		}
		else
		{
			$this->_cache_mtime = 0;
			$this->_cache_age = 0;
			$this->cache_log("Cache file doesn't exist.");
			return TRUE;
		}
	}

	private function cache_this($content=null)
	{
		$replace_cmds = array("force_exp=1", "force_exp_all=1", "?force_exp=1", "?force_exp_all=1");
		$content = str_replace($replace_cmds, "", $content);
		if ( self::CACHE_USE_GZIP == TRUE )
			$content = gzdeflate($content, 2);
		if ( $this->_cache_method == "diskread" )
		{
			$this->cache_data = array();
			$this->cache_data['key'] = $this->_key;
			$this->cache_data['expire'] = $this->_expire;
			$this->cache_data['mtime'] = $this->_now;
			$this->cache_data['variables'] = $this->_cache_variables;
			$this->cache_data['content'] = $content;
			$this->cache_data = serialize($this->cache_data);
		}
		else
		{
			$this->cache_data = $content;
		}

		if ( $this->_write_flock($this->_cache_file, $this->cache_data) )
		{
			$this->cache_log("Cache file was written.");
			return TRUE;
		}
		else
		{
			$this->cache_log("Cache file failed to write.");
			return FALSE;
		}
	}

	private function make_hash($key, $hash_type=NULL)
	{
		if ( $hash_type == NULL )
			$hash_type = self::CACHE_KEY_METHOD;
		switch ($hash_type)
		{
			CASE 'crc32':
				$key = crc32($key);
				$key = sprintf("%u", $key);
				break;
			CASE 'md5': $key = md5($key);
				break;
			CASE 'sha1': $key = sha1($key);
				break;
		}
		return $key;
	}

	private function _cache_read_serialized($cache_file)
	{
		return unserialize($this->_read_flock($cache_file));
	}

	/* Revamped Read Cache Function to smartly deal with GZ compression */

	public function read_cache($echo_content = TRUE)
	{
		# Run Garbage Collection
		if ( self::CACHE_GC > 0 )
			$this->_cache_gc();
		/*
		 * If we are using the diskread method that means that we passed in an array of values
		 * and whatever else (the cached content, variables, etc) and it caches it. It also stores
		 * the expire time and some other things in the cache file itself.
		 */
		$gz_used = $compressed_size = $actual_size = 0;
		$ret = false;
		if ( $this->_cache_method == "diskread" )
		{
			$this->cache_data = $this->_cache_read_serialized($this->_cache_file);
			$this->_cache_content = $this->cache_data['content'];
			if ( self::CACHE_USE_GZIP == TRUE )
			{
				$compressed_size = strlen($this->_cache_content);
				if ( ($gz_check = @gzinflate($this->_cache_content) ) )
				{
					$gz_used = 1;
					$this->_cache_content = $gz_check;
				}
				$actual_size = strlen($this->_cache_content);
			}

			if ( $echo_content == TRUE )
			{
				$this->echo_cache();
				unset($this->cache_data['content']);
			}
			$ret = $this->cache_data;
		}
		else
		{

			$this->_cache_content = file_get_contents($this->_cache_file);
			if ( self::CACHE_USE_GZIP == TRUE )
			{
				$compressed_size = strlen($this->_cache_content);
				if ( ($gz_check = @gzinflate($this->_cache_content) ) )
				{
					$gz_used = 1;
					$this->_cache_content = $gz_check;
				}
				$actual_size = strlen($this->_cache_content);
			}
			$this->echo_cache();
			$ret = true;
		}

		$diff_size = $actual_size - $compressed_size;
		if ( self::CACHE_TAG )
			$this->cache_tag("CACHE READ: $this->_cache_method - GZ Used: $gz_used  Saved $diff_size bytes.");
		$this->cache_log("Cache file read via: " . $this->_cache_method);
	}

	private function cache_log($what, $override = FALSE)
	{
		$host_ident = "";
		$log_folder = self::CACHE_PATH . "/logs";
		if ( self::CACHE_LOG == TRUE || $override == TRUE )
		{
			if ( self::CACHE_LOG_TYPE != "combined" && self::CACHE_PER_HOST == TRUE )
			{
				$log_file = "$log_folder/" . $this->_host_folder . ".log";
			}
			else
			{
				if ( self::CACHE_PER_HOST == TRUE )
					$host_ident = $this->_host_folder . "|";
				$log_file = "$log_folder/cache.log";
			}

			$log_line = "{$host_ident}$this->_pretty_time|$this->_original_key|$what\n";
			if ( !is_dir($log_folder) )
				mkdir($log_folder, 0777, true);
			$this->_write_flock($log_file, $log_line, "a+");
		}
	}

	private function default_key()
	{
		$KEY['SERVER'] = $_SERVER['HTTP_POST'];
		$KEY['REQUEST_URI'] = $_SERVER['REQUEST_URI'];
		$KEY['GET'] = $_GET;
		$KEY['POST'] = $_POST;
		$KEY = serialize($KEY);
		return $KEY;
	}

	private function make_dir_structure()
	{
		/* If caching per host, then look for a cache file in that dir, if not cache root */
		if ( self::CACHE_PER_HOST == TRUE )
		{
			$stat_file = $this->_cache_path . "/" . $this->_host_folder . "/.madCache.stat";
		}
		else
		{
			$stat_file = $this->_cache_path . "/.madCache.stat";
		}
		if ( file_exists($stat_file) )
			return TRUE;
		$failed = 0;
		for ( $a = 0; $a < self::CACHE_MAX_HASH; $a++ )
		{
			$thedir = $this->_cache_path . "/$a/";
			$failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
			for ( $b = 0; $b < self::CACHE_MAX_HASH; $b++ )
			{
				$thedir = $this->_cache_path . "/$a/$b/";
				$failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
				for ( $c = 0; $c < self::CACHE_MAX_HASH; $c++ )
				{
					$thedir = $this->_cache_path . "/$a/$b/$c";
					$failed|=!@mkdir($thedir, self::CACHE_STORAGE_PERM);
				}
			}
		}
		$now = date("Y-m-d h:i:s", time());
		if ( $failed == 0 )
			$this->_write_flock($stat_file, $now);
		$this->cache_log("Full cache directory structure created for $this->_host_folder");
		return TRUE;
	}

	private function make_path($tmp_key=NULL)
	{
		if ( $tmp_key === NULL )
		{
			$tmp_key = $this->_key;
			$folder = $this->_cache_path;
			$check = TRUE;
		}
		else
		{
			$check = FALSE;
			$folder = "";
		}

		// get the folder structure
		for ( $i = 0; $i < 3; $i++ )
		{
			$thenum = abs(crc32(substr($tmp_key, $i, 4))) % self::CACHE_MAX_HASH;
			$folder .= "/" . $thenum;
		}

		if ( $check == TRUE )
		{
			// I think I am going to build a gradual directory maker in.	
			if ( self::CACHE_CHECK_DIR == "STAT" )
			{
				$this->make_dir_structure();
			}
			else if ( self::CACHE_CHECK_DIR == "ONLOAD" )
			{
				if ( !is_dir($folder) )
				{
					if ( !mkdir($folder, self::CACHE_STORAGE_PERM, TRUE) )
						$this->cache_log("Directory strctured failed! (onload) $folder",true);
					else
						$this->cache_log("Directory strctured created (onload) $folder");
				}
			}
		}
		return $folder;
	}

	public function expire_host($host = null)
	{
		$cp = $this->_cache_path;
		if ( $host != null )
			$cp .= "/$host";
		$test = $this->_clean_dir($cp, true);
		var_dump($test);
		if ( !$test )
			echo("$cp is not a dir");
	}

	private function _clean_dir($path, $verbose=false)
	{
		if ( !is_dir($path) )
			return false;
		if ( strpos($path, "..") !== false )
			return 'Can\'t use .. dirs.';
		if ( strpos($path, self::CACHE_PATH) === false )
			return 'Can\'t leave our path.';

		$dh = @opendir($path);
		if ( $verbose )
			echo("Opening $path ...\n");
		if ( $dh )
		{
			while ( ($file = readdir($dh)) !== false )
			{
				if ( $file == "." || $file == ".." )
					continue;
				$actual_file = $path . "/" . $file;
				if ( is_dir($actual_file) )
				{
					if ( $verbose )
						echo("Recursing into $actual_file\n");
					$tmp = $this->_clean_dir($actual_file, $verbose);
				} else
				{
					if ( $verbose )
						echo("Trying to remove $actual_file\n");
					@unlink($actual_file);
				}
				flush();
			}
			closedir($dh);
			if ( $verbose )
				echo("Finished cleaning $path\n");
			$this->cache_log("Directory cleaned: $path");
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	public function collect_garbage_depc()
	{
		$this->cache_log("Manual garbage collection running.", TRUE);
		$this->_cache_gc(1);
	}

	private function _cache_gc($start=0)
	{
		$precision = 100000;
		$r = (mt_rand() % $precision) / $precision;
		if ( $start == 1 || $r <= (self::CACHE_GC / 100) )
		{
			$dh = @opendir($this->_cache_path);
			if ( $dh )
			{
				while ( ($file = readdir($dh)) !== false )
				{
					if ( $file == "." || $file == ".." )
						continue;
					$file = $this->_cache_path . "/" . $file;
					if ( $this->_cache_method == "include" )
					{
						$mtime = filemtime($file);
						if ( ($this->_now - $mtime) > self::CACHE_MAX_AGE )
							unlink($file);
					}
					else
					{
						$tmp_cache = $this->_cache_read_serialized($file);
						if ( ($this->_now - $tmp_cache['mtime']) > $tmp_cache['expire'] )
							unlink($file);
					}
				}
				closedir($dh);
				$this->cache_log("GC Sucessfully run on $this->_cache_path", TRUE);
			}
			else
			{
				$this->cache_log("GC Failed to run.", TRUE);
			}
		}
	}

	/*
	  Function for doing cron based garbage collection. It is going to expire files based on the MAX_CACHE_AGE value.
	  1. Open the garbage collector stat file.
	  2. Get a list of all of the main cache folders.
	  3. Pick one based on either lack of existence in the stat folder of last clean time.
	  4. Generate folder structure array.
	  5. Walk the structure removing old caches.
	 */

	public function collect_garbage($verbose=FALSE)
	{
		$total_kill = 0;
		$start_time = microtime(true);
		//echo("Starting Garbage Collection @ ".date("h:i:s m-d-Y")."\n");
		// exclude this from the directory scan
		$not_cache_files = array("." . "..", "logs", "0", "cache_files", "mapping");

		/* Garbage.stat will hold when the main folders were last checked. */
		// load garbage stats
		if ( !file_exists(self::CACHE_PATH . "/logs/garbage.stat") )
		{
			$main_stats = array();
		}
		else
		{
			$main_stats = file_get_contents(self::CACHE_PATH . "/logs/garbage.stat");
			$main_stats = unserialize($main_stats);
		}

		/**
		 * This is where I get the list of the mapped directories
		 */
		$cache_folders = array();
		$main_folders = array();
		$file_list = scandir(self::CACHE_PATH);
		for ( $i = 0; $i < count($file_list); $i++ )
		{
			if (
					is_dir(self::CACHE_PATH . "/" . $file_list[$i]) &&
					!in_array($file_list[$i], $not_cache_files) &&
					$file_list[$i] != "." &&
					$file_list[$i] != ".." &&
					!array_key_exists($file_list[$i], $main_stats)
			)
				$main_stats[$file_list[$i]] = 0;
		}

		/**
		 * Sort the stats file, pick a folder, incrememnt by 1 so the next time the low # gets picked
		 */
		asort($main_stats, SORT_NUMERIC);
		foreach ( $main_stats as $ck => $cs )
			$main_folders[] = array("key" => $ck, "count" => $cs);
		$main_folder = $main_folders[0]["key"];
		$main_stats[$main_folder] = time();


		/**
		 * Write the updated main stats file 
		 */
		if ( file_put_contents(self::CACHE_PATH . "/logs/garbage.stat", serialize($main_stats)) )
			if ( $verbose )
				echo("Main stats file written.\n");

		/* Now check for sub stats file */
		if ( !file_exists(self::CACHE_PATH . "/logs/garbage-{$main_folder}.stat") )
		{
			$cache_stats = array();
		}
		else
		{
			$cache_stats = file_get_contents(self::CACHE_PATH . "/logs/garbage-{$main_folder}.stat");
			$cache_stats = unserialize($cache_stats);
		}


		/**
		 * Go through the list of folders in whichever main folder we picked
		 */
		$sub_file_list = scandir(self::CACHE_PATH . "/" . $main_folder);
		for ( $ii = 0; $ii < count($sub_file_list); $ii++ )
		{
			if ( $sub_file_list[$ii] != "." && $sub_file_list[$ii] != ".." )
			{
				$sub_path = self::CACHE_PATH . "/$main_folder/" . $sub_file_list[$ii];
				if ( is_dir($sub_path) && !preg_match("/[^A-Za-z0-9\.]/", $sub_file_list[$ii], $matches) )
					$cache_folders[] = $sub_path;
			}
		}


		foreach ( $cache_folders as $cf )
		{
			$cf_sum = md5($cf);
			if ( !array_key_exists($cf_sum, $cache_stats) )
			{
				$cache_stats[$cf_sum] = array("path" => $cf, "crawled" => 0);
			}
		}

		// Find the oldest crawl.
		$now = time();
		$oldest = 0;
		foreach ( $cache_stats as $cf_sum => $vals )
		{

			$crawl_age = $now - $vals['crawled'];
			/* If never crawled, do this one */
			if ( $vals['crawled'] == 0 )
			{
				$crawl_this = $cf_sum;
				break;
			}
			else
			{
				/* Check to see if this crawl age is greater than the oldest thus far */
				if ( $crawl_age > $oldest )
				{
					$oldest = $crawl_age;
					$crawl_this = $cf_sum;
				}
			}
		}
		$crawl_path = $cache_stats[$crawl_this]['path'];

		if ( empty($crawl_path) )
		{
			print_r($main_stats);
			print_r($cache_stats);
		}

		$cache_stats[$crawl_this]['crawled'] = $now;

		// print_r($cache_stats);

		/* Make list of folders */
		$crawl_folders = array();
		for ( $a = 0; $a < self::CACHE_MAX_HASH; $a++ )
			for ( $b = 0; $b < self::CACHE_MAX_HASH; $b++ )
				for ( $c = 0; $c < self::CACHE_MAX_HASH; $c++ )
					if ( is_dir($crawl_path . "/$a/$b/$c") )
						$crawl_folders[] = $crawl_path . "/$a/$b/$c";

		/* Do cleaning */
		if ( $verbose == TRUE )
		{
			echo("Decided to crawl $crawl_path\n");
			echo("There are " . count($crawl_folders) . " folders to crawl\n");
		}

		foreach ( $crawl_folders as $cf )
		{
			$actual_path = str_replace(self::CACHE_PATH, "", $cf);
			if ( is_dir($cf) )
			{
				$total_kill += $this->_expire_dir($cf, $verbose);
				if ( $this->_is_empty_dir($cf) )
				{
					if ( $verbose )
						echo("EMPTY [$actual_path]\n");
					$total_kill += 1;
					rmdir($cf);
				}
			}
			else
			{
				if ( $verbose )
					echo("NF    [$actual_path]\n");
			}
		}

		if ( file_put_contents(self::CACHE_PATH . "/logs/garbage-{$main_folder}.stat", serialize($cache_stats)) )
			if ( $verbose )
				echo("Done crawling. Wrote garbage stat file.\n");
		$end_time = microtime(true);
		$spent_time = $end_time - $start_time;
		//echo("Ending Garbage Collection @ ".date("h:i:s m-d-Y")."\n");		
		$msg = "[" . date("h:i:s m-d-Y") . "] CRAWLED [$total_kill x'd] [$crawl_path] in {$spent_time}s";
		$this->_garbage_log($msg);
		if ( $verbose )
			echo($msg . "\n");
	}

	private function _garbage_log($msg)
	{
		$file = self::CACHE_PATH . "/logs/garbage.log";
		$msg = "$msg\n";
		if ( $this->_write_flock($file, $msg, "a+") )
			return TRUE;
		else
			return FALSE;
	}

	// Function for clearing contents of folder if file is older than the maximum cache age.
	private function _expire_dir($path, $verbose = false)
	{
		$kill_count = 0;
		$now = time();
		if ( !is_dir($path) )
			return FALSE;
		$dh = @opendir($path);
		if ( $dh )
		{
			while ( ($file = readdir($dh)) !== false )
			{
				if ( $file == "." || $file == ".." )
					continue;
				$actual_file = $path . "/" . $file;
				$actual_path = str_replace(self::CACHE_PATH, "", $actual_file);

				/* If a directory is somehow in here let's log it so I can figure it out */
				if ( is_dir($file) )
				{
					$this->_write_flock(self::CACHE_PATH . "/garbage.log", "FILE  [$actual_path]\n");
					continue;
				}
				$fs = filesize($actual_file);
				$this_age = $now - filemtime($actual_file);
				if ( $fs == 0 || ($this_age > self::CACHE_MAX_AGE) )
				{
					if ( $verbose )
						echo("DEL   [$this_age][$actual_path]\n");
					unlink($actual_file);
					$kill_count++;
				}
				else
				{
					if ( $verbose )
						echo("OK    [$this_age][$actual_path]\n");
				}
			}
			closedir($dh);
			return $kill_count;
		}
		else
		{
			return FALSE;
		}
	}

	private function _is_empty_dir($dir)
	{
		return (($files = @scandir($dir)) && count($files) <= 2);
	}

	private function _read_flock($cache_file = NULL, $serialized=TRUE)
	{
		if ( $cache_file === NULL )
			$cache_file = $this->_cache_file;
		$buff = "";
		$fp = @fopen($cache_file, "r");
		if ( $fp )
		{
			flock($fp, LOCK_SH);
			while ( ($tmp = fread($fp, 4096) ) )
			{
				$buff .= $tmp;
			}
			flock($fp, LOCK_UN);
			fclose($fp);
			if ( $serialized == TRUE )
				$buff = unserialize($buff);
			return $buff;
		}
		else
		{
			$this->cache_log("Cache file could not be opened: $cache_file");
			return FALSE;
		}
	}

	private function _write_flock($path, $what, $mode="w")
	{
		$blocksize = 4096;
		$fp = fopen($path, $mode);
		if ( $fp )
		{
			flock($fp, LOCK_EX);
			$fwrite = 0;
			for ( $written = 0; $written < strlen($what); $written += $fwrite )
			{
				if ( $written != 0 && $fwrite != $blocksize )
				{
					$diff = $blocksize - $fwrite;
					$written -= $diff;
				}
				$line = substr($what, $written, $blocksize);
				$fwrite = fwrite($fp, $line);
			}
			flock($fp, LOCK_UN);
			fclose($fp);
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

	private function _truncate_flock($path)
	{
		$fp = @fopen($path, "w");
		if ( $fp )
		{
			flock($fp, LOCK_EX);
			ftruncate($fp, 0);
			flock($fp, LOCK_UN);
			fclose($fp);
			return TRUE;
		}
		else
		{
			return FALSE;
		}
	}

}

?>
