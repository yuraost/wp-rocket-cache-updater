<?php
/**
 * Plugin Name:	WP Rocket Cache Updater
 * Description:	Gradually updates the cache of all pages and other post types.
 * Author:		Yuriy Ostapchuk
 * Version:		1.0
 */

/**
 * Prevent loading this file directly
 */
defined('ABSPATH') || exit();

/**
 * Cache Updater definitions
 */
define('CACHE_UPDATER_NAME',				'WP Rocket Cache Updater');
define('CACHE_UPDATER_VERSION',				'1.0');
define('CACHE_UPDATER_WP_VERSION',			'5.6');
define('CACHE_UPDATER_PHP_VERSION',			'7.2');
define('CACHE_UPDATER_WP_ROCKET_VERSION',	'3.8');
define('CACHE_UPDATER_FILE',				__FILE__);
define('CACHE_UPDATER_PATH',				plugin_dir_path(CACHE_UPDATER_FILE));
define('CACHE_UPDATER_INC_PATH',			CACHE_UPDATER_PATH . 'inc/');
define('CACHE_UPDATER_3RD_PARTY_PATH',			CACHE_UPDATER_INC_PATH . '3rd-party/');
define('CACHE_UPDATER_LOG_PATH',			CACHE_UPDATER_PATH . 'log/');
define('CACHE_UPDATER_URL',					plugin_dir_url(CACHE_UPDATER_FILE));
define('CACHE_UPDATER_ASSETS_URL',			CACHE_UPDATER_URL . 'assets/');

require CACHE_UPDATER_INC_PATH . 'class-cache-updater-requirements.php';
require CACHE_UPDATER_INC_PATH . 'class-cache-updater.php';

/**
 * Check requirements and run Cache Updater on plugins loaded event
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
add_action('plugins_loaded', 'cache_updater_init');
function cache_updater_init() {
	define('CACHE_UPDATER_CLASS_CACHE_FILE',	WP_ROCKET_INC_PATH . 'classes/Buffer/class-cache.php');

	$cache_updater_requirements = new Cache_Updater_Requirements(
		array(
			'plugin_name'		=> CACHE_UPDATER_NAME,
			'plugin_version'	=> CACHE_UPDATER_VERSION,
			'wp_version'		=> CACHE_UPDATER_WP_VERSION,
			'php_version'		=> CACHE_UPDATER_PHP_VERSION,
			'wp_rocket_version'	=> CACHE_UPDATER_WP_ROCKET_VERSION,
			'class_cache_php'	=> CACHE_UPDATER_CLASS_CACHE_FILE
		)
	);

	if ($cache_updater_requirements->check()) {
		$GLOBALS['Cache_Updater'] = Cache_Updater::instance();

		require CACHE_UPDATER_3RD_PARTY_PATH . 'wp-rocket-no-cache-auto-purge.php';
		require CACHE_UPDATER_3RD_PARTY_PATH . 'wp-rocket-no-cache-for-admins.php';
        require CACHE_UPDATER_3RD_PARTY_PATH . 'hide-my-wp.php';

        require CACHE_UPDATER_INC_PATH . 'admin.php';
	}

	unset($cache_updater_requirements);
}

/**
 * Create DB table on plugin activation
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
register_activation_hook(CACHE_UPDATER_FILE, 'cache_updater_activation');
function cache_updater_activation() {
    global $wpdb;

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta(
		"CREATE TABLE {$wpdb->prefix}cache_updater (
			URL VARCHAR(150) NOT NULL,
			type VARCHAR(10) DEFAULT NULL,
			updated_time TIMESTAMP NULL DEFAULT NULL,
			state VARCHAR(20) NOT NULL DEFAULT 'need-update',
  			css_file VARCHAR(150) DEFAULT NULL,
  			css_file_mob VARCHAR(150) DEFAULT NULL,
  			js_file VARCHAR(150) DEFAULT NULL,
  			js_file_mob VARCHAR(150) DEFAULT NULL,
  			priority SMALLINT(6) UNSIGNED NOT NULL DEFAULT '0',
  			PRIMARY KEY (URL),
			UNIQUE URL (URL)
		)
		DEFAULT CHARACTER SET {$wpdb->charset} COLLATE {$wpdb->collate};"
	);

	Cache_Updater::instance()->refresh_urls();
}

/**
 * Delete DB table on plugin deactivation
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
register_deactivation_hook(CACHE_UPDATER_FILE, 'cache_updater_deactivation');
function cache_updater_deactivation() {
	global $wpdb;

	$wpdb->query(
		"DROP TABLE IF EXISTS {$wpdb->prefix}cache_updater"
	);

	delete_transient('cache_updater_stop');
	delete_transient('cache_updater_running');
}