<?php
/**
 * Elgg configuration procedural code.
 *
 * Includes functions for manipulating the configuration values stored in the database
 * Plugin authors should use the {@link elgg_get_config()}, {@link elgg_set_config()},
 * {@link elgg_save_config()}, and {@unset_config()} functions to access or update
 * config values.
 *
 * Elgg's configuration is split among 2 tables and 1 file:
 * - dbprefix_config
 * - dbprefix_datalists
 * - engine/settings.php (See {@link settings.example.php})
 *
 * Upon system boot, all values in dbprefix_config are read into $CONFIG.
 *
 * @package Elgg.Core
 * @subpackage Configuration
 */

/**
 * Get the URL for the current (or specified) site
 *
 * @param int $site_guid The GUID of the site whose URL we want to grab
 * @return string
 * @since 1.8.0
 */
function elgg_get_site_url($site_guid = 0) {
	if ($site_guid == 0) {
		global $CONFIG;
		if(isset($CONFIG->wwwroot))
			return $CONFIG->wwwroot;
	}

//	$site = get_entity($site_guid);
	$site = elgg_get_site_entity(); // We only have one site, so pull it out by another method
	
	if (!$site instanceof ElggSite) {
		return false;
	}
	/* @var ElggSite $site */

	return $site->url;
}

/**
 * Get the plugin path for this installation
 *
 * @return string
 * @since 1.8.0
 */
function elgg_get_plugins_path() {
	global $CONFIG;
	return $CONFIG->pluginspath;
}

/**
 * Get the data directory path for this installation
 *
 * @return string
 * @since 1.8.0
 */
function elgg_get_data_path() {
	global $CONFIG;
	return $CONFIG->dataroot;
}

/**
 * Get the root directory path for this installation
 *
 * @return string
 * @since 1.8.0
 */
function elgg_get_root_path() {
	global $CONFIG;
	return $CONFIG->path;
}

/**
 * Get an Elgg configuration value
 *
 * @param string $name      Name of the configuration value
 * @param int    $site_guid NULL for installation setting, 0 for default site
 *
 * @return mixed Configuration value or null if it does not exist
 * @since 1.8.0
 */
function elgg_get_config($name, $site_guid = 0) {
	global $CONFIG;

	$name = trim($name);

	if (isset($CONFIG->$name)) {
		return $CONFIG->$name;
	}

	// installation wide setting
	$value = datalist_get($name);
	
	// @todo document why we don't cache false
	if ($value === false) {
		return null;
	}
	
	$CONFIG->$name = $value;
	return $value;
}

/**
 * Set an Elgg configuration value
 *
 * @warning This does not persist the configuration setting. Use elgg_save_config()
 *
 * @param string $name  Name of the configuration value
 * @param mixed  $value Value
 *
 * @return void
 * @since 1.8.0
 */
function elgg_set_config($name, $value) {
	global $CONFIG;

	$name = trim($name);

	$CONFIG->$name = $value;
}

/**
 * Save a configuration setting
 *
 * @param string $name      Configuration name (cannot be greater than 255 characters)
 * @param mixed  $value     Configuration value. Should be string for installation setting
 * @param int    $site_guid NULL for installation setting, 0 for default site
 *
 * @return bool
 * @since 1.8.0
 */
function elgg_save_config($name, $value, $site_guid = 0) {
	global $CONFIG;

	$name = trim($name);

	if (strlen($name) > 255) {
		elgg_log("The name length for configuration variables cannot be greater than 255", "ERROR");
		return false;
	}

	elgg_set_config($name, $value);

	if (is_object($value)) {
		return false;
	}
	return datalist_set($name, $value);
}

/**
 * Check that installation has completed and the database is populated.
 *
 * @throws InstallationException|DatabaseException
 * @return void
 * @access private
 */
function verify_installation() {
	global $CONFIG;

/*	if (isset($CONFIG->installed)) {
		return;
	}

	try {
		$dblink = get_db_link('read');
		if (!$dblink) {
			throw new DatabaseException();
		}

		mysql_query("SELECT value FROM {$CONFIG->dbprefix}datalists WHERE name = 'installed'", $dblink);
		if (mysql_errno($dblink) > 0) {
			throw new DatabaseException();
		}

		$CONFIG->installed = true;

	} catch (DatabaseException $e) {
		throw new InstallationException(elgg_echo('InstallationException:SiteNotInstalled'));
	}*/
	return;
}

/**
 * An array of key value pairs from the datalists table.
 *
 * Used as a cache in datalist functions.
 *
 * @global array $DATALIST_CACHE
 */
$DATALIST_CACHE = array();

/**
 * Get the value of a datalist element.
 *
 * @internal Datalists are stored in the datalist table.
 *
 * @tip Use datalists to store information common to a full installation.
 *
 * @param string $name The name of the datalist
 * @return string|null|false String if value exists, null if doesn't, false on error
 * @access private
 */
function datalist_get($name) {
	global $CONFIG, $DATALIST_CACHE;

	$name = trim($name);

	// cannot store anything longer than 255 characters in db, so catch here
	if (elgg_strlen($name) > 255) {
		elgg_log("The name length for configuration variables cannot be greater than 255", "ERROR");
		return false;
	}

	if (isset($DATALIST_CACHE[$name])) {
		return $DATALIST_CACHE[$name];
	}

	//check if this is set in settings.php	
	foreach($CONFIG as $k=>$v){
		if($k == $name){
			return $v;
		};
	}

	// If memcache enabled then cache value in memcache
	$value = null;
	static $datalist_memcache;
	if ((!$datalist_memcache) && (is_memcache_available())) {
		$datalist_memcache = new ElggMemcache('datalist_memcache');
	}
	if ($datalist_memcache) {
		$value = $datalist_memcache->load($name);
	}
	if ($value) {
		return $value;
	}

	if ($site = elgg_get_site_entity()) {
		//`var_dump($site);
		if($value = $site->$name){
			return $value;
		} else {
			$name = "config:$name";
			return $site->$name;
		}
	}
	
	return null;
}

/**
 * Set the value for a datalist element.
 *
 * @param string $name  The name of the datalist
 * @param string $value The new value
 *
 * @return bool
 * @access private
 */
function datalist_set($name, $value) {
	global $CONFIG, $DATALIST_CACHE;

        $site = elgg_get_site_entity(); 
        if ($site) {
			$name = "config:$name";
            $site->$name = $value;
        	return $site->save();
	} 
        
	return true;
}

/**
 * Run a function one time per installation.
 *
 * If you pass a timestamp as the second argument, it will run the function
 * only if (i) it has never been run before or (ii) the timestamp is >=
 * the last time it was run.
 *
 * @warning Functions are determined by their name.  If you change the name of a function
 * it will be run again.
 *
 * @tip Use $timelastupdatedcheck in your plugins init function to perform automated
 * upgrades.  Schedule a function to run once and pass the timestamp of the new release.
 * This will cause the run once function to be run on all installations.  To perform
 * additional upgrades, create new functions for each release.
 *
 * @warning The function name cannot be longer than 255 characters long due to
 * the current schema for the datalist table.
 *
 * @internal A datalist entry $functioname is created with the value of time().
 *
 * @param string $functionname         The name of the function you want to run.
 * @param int    $timelastupdatedcheck A UNIX timestamp. If time() is > than this,
 *                                     this function will be run again.
 *
 * @return bool
 */
function run_function_once($functionname, $timelastupdatedcheck = 0) {
	$lastupdated = datalist_get($functionname);
	if ($lastupdated) {
		$lastupdated = (int) $lastupdated;
	} elseif ($lastupdated !== false) {
		$lastupdated = 0;
	} else {
		// unable to check datalist
		return false;
	}
	if (is_callable($functionname) && $lastupdated <= $timelastupdatedcheck) {
		$functionname();
		datalist_set($functionname, time());
		return true;
	} else {
		return false;
	}
}

/**
 * Removes a config setting.
 *
 * @internal
 * These settings are stored in the dbprefix_config table and read during system
 * boot into $CONFIG.
 *
 * @param string $name      The name of the field.
 * @param int    $site_guid Optionally, the GUID of the site (current site is assumed by default).
 *
 * @return int|false The number of affected rows or false on error.
 *
 * @see get_config()
 * @see set_config()
 */
function unset_config($name, $site_guid = 0) {
	global $CONFIG;
return;
	if (isset($CONFIG->$name)) {
		unset($CONFIG->$name);
	}

	$name = sanitise_string($name);
	$site_guid = (int) $site_guid;
	if ($site_guid == 0) {
		$site_guid = (int) $CONFIG->site_id;
	}

	$query = "delete from {$CONFIG->dbprefix}config where name='$name' and site_guid=$site_guid";
	return delete_data($query);
}

/**
 * Add or update a config setting.
 *
 * If the config name already exists, it will be updated to the new value.
 *
 * @internal
 * These settings are stored in the dbprefix_config table and read during system
 * boot into $CONFIG.
 *
 * @param string $name      The name of the configuration value
 * @param string $value     Its value
 * @param int    $site_guid Optionally, the GUID of the site (current site is assumed by default)
 *
 * @return bool
 * @todo The config table doens't have numeric primary keys so insert_data returns 0.
 * @todo Use "INSERT ... ON DUPLICATE KEY UPDATE" instead of trying to delete then add.
 * @see unset_config()
 * @see get_config()
 * @access private
 */
function set_config($name, $value, $site_guid = 0) {
	global $CONFIG;

	$name = trim($name);

	// cannot store anything longer than 255 characters in db, so catch before we set
	if (elgg_strlen($name) > 255) {
		elgg_log("The name length for configuration variables cannot be greater than 255", "ERROR");
		return false;
	}

	$namespace = 'config:';
	$name = $namespace.$name;
	$site_guid = (int) $site_guid;
//	if ($site_guid == 0) {
//		$site_guid = (int) $CONFIG->site_id;
//	}
	$CONFIG->$name = $value;
	
	$site = get_entity($site_guid, 'site');
	$site->$name = json_encode($value);
	
	if($site->save()){
		return true;
	}

	return false;
}

/**
 * Gets a configuration value
 *
 * @internal
 * These settings are stored in the dbprefix_config table and read during system
 * boot into $CONFIG.
 *
 * @param string $name      The name of the config value
 * @param int    $site_guid Optionally, the GUID of the site (current site is assumed by default)
 *
 * @return mixed|null
 * @see set_config()
 * @see unset_config()
 * @access private
 */
function get_config($name, $site_guid = 0) {
	global $CONFIG;

	$site_guid = (int) $site_guid;
return false;
	// check for deprecated values.
	// @todo might be a better spot to define this?
	$new_name = false;
	switch($name) {
		case 'viewpath':
			$new_name = 'view_path';
			$dep_version = 1.8;
			break;

		case 'pluginspath':
			$new_name = 'plugins_path';
			$dep_version = 1.8;
			break;

		case 'sitename':
			$new_name = 'site_name';
			$dep_version = 1.8;
			break;
	}

	// @todo these haven't really been implemented in Elgg 1.8. Complete in 1.9.
	// show dep message
	if ($new_name) {
	//	$msg = "Config value $name has been renamed as $new_name";
		$name = $new_name;
	//	elgg_deprecated_notice($msg, $dep_version);
	}

	// decide from where to return the value
	if (isset($CONFIG->$name)) {
		return $CONFIG->$name;
	}

	if ($site_guid == 0) {
		$site_guid = (int) $CONFIG->site_id;
	}

	$result = get_data_row("SELECT value FROM {$CONFIG->dbprefix}config
		WHERE name = '{$name}' and site_guid = {$site_guid}");

	if ($result) {
		$result = $result->value;
		$result = unserialize($result->value);
		$CONFIG->$name = $result;
		return $result;
	}

	return null;
}

/**
 * Loads all configuration values from the dbprefix_config table into $CONFIG.
 *
 * @param int $site_guid Optionally, the GUID of the site (current site is assumed by default)
 *
 * @return bool
 * @access private
 */
function get_all_config($site_guid = 0) {
	global $CONFIG;

	$site_guid = (int) $site_guid;

	if ($site_guid == 0) {
		$site_guid = (int) $CONFIG->site_guid;
	}

/*	if ($result = get_data("SELECT * FROM {$CONFIG->dbprefix}config WHERE site_guid = $site_guid")) {
		foreach ($result as $r) {
			$name = $r->name;
			$value = $r->value;
			$CONFIG->$name = unserialize($value);
		}

		return true;
	}
*/
	return false;
}

/**
 * Loads configuration related to this site
 *
 * This loads from the config database table and the site entity
 * @access private
 */
function _elgg_load_site_config() {
	global $CONFIG;

	$CONFIG->site_guid = 1;
	$CONFIG->site_id = $CONFIG->site_guid;
		
	//can we load from settings.php? (this code is duplicated!)
	if(isset($CONFIG->site_name)){
		$site = new ElggSite();
		$site->guid = $CONFIG->site_guid;
		$site->name = $CONFIG->site_name;
		$site->url = $CONFIG->site_url;
		$site->email = $CONFIG->site_email;
		$site->description =  $CONFIG->site_description;
	} else {
		$site = get_entity($CONFIG->site_guid, 'site');
	}

	$CONFIG->site = $site;
	if (!$CONFIG->site) {
		throw new InstallationException(elgg_echo('InstallationException:SiteNotInstalled'));
	}
	
	$CONFIG->wwwroot = $CONFIG->site->getURL();
	$CONFIG->sitename = $CONFIG->site->name;
	$CONFIG->sitedescription = $CONFIG->site->description;
	$CONFIG->siteemail = $CONFIG->site->email;
	$CONFIG->url = $CONFIG->wwwroot;

	get_all_config();
	// gives hint to elgg_get_config function how to approach missing values
	$CONFIG->site_config_loaded = true;
}

/**
 * Loads configuration related to Elgg as an application
 *
 * This loads from the datalists database table
 * @access private
 */
function _elgg_load_application_config() {
	global $CONFIG;

	$install_root = str_replace("\\", "/", dirname(dirname(dirname(__FILE__))));
	$defaults = array(
		'path'			=>	"$install_root/",
		'view_path'		=>	"$install_root/views/",
		'plugins_path'	=>	"$install_root/mod/",
		'language'		=>	'en',

		// compatibility with old names for plugins not using elgg_get_config()
		'viewpath'		=>	"$install_root/views/",
		'pluginspath'	=>	"$install_root/mod/",
	);

	foreach ($defaults as $name => $value) {
		if (empty($CONFIG->$name)) {
			$CONFIG->$name = $value;
		}
	}

	$path = datalist_get('path');
	if (!empty($path)) {
		$CONFIG->path = $path;
	}
	$dataroot = datalist_get('dataroot');
	if (!empty($dataroot)) {
		$CONFIG->dataroot = $dataroot;
	}
	$simplecache_enabled = datalist_get('simplecache_enabled');
	if ($simplecache_enabled !== false) {
		$CONFIG->simplecache_enabled = $simplecache_enabled;
	} else {
		$CONFIG->simplecache_enabled = 1;
	}
	$system_cache_enabled = datalist_get('system_cache_enabled');
	if ($system_cache_enabled !== false) {
		$CONFIG->system_cache_enabled = $system_cache_enabled;
	} else {
		$CONFIG->system_cache_enabled = 1;
	}
		
	//say we are using cassandra!
	//$CONFIG->cassandra = true;

	// initialize context here so it is set before the get_input call
	$CONFIG->context = array();

	// needs to be set before system, init for links in html head
	$viewtype = get_input('view', 'default');
	
	if(!isset($CONFIG->lastcache)){
		$lastcached = datalist_get("simplecache_lastcached_$viewtype") ?: datalist_get("lastcache");
		$CONFIG->lastcache = $lastcached;
		if(minds_is_multisite())
			$CONFIG->lastcache = $CONFIG->lastcache_multi . $CONFIG->lastcache;
	}

		$CONFIG->i18n_loaded_from_cache = false;

	// this must be synced with the enum for the entities table
	$CONFIG->entity_types = array('group', 'object', 'site', 'user', 'plugin', 'notification');
}
