<?php
/**
 * Disable cache for administrators.
 * @author Yuriy Ostapchuk
 * @since 1.0
 */

/**
 * Prevent loading this file directly.
 */
defined('ABSPATH') || exit();

/**
 * Do not serve cached pages for administrators.
 * @since 1.0
 *
 * @hook init
 */
add_action('init', 'cache_updater_no_cache_for_admins');
function cache_updater_no_cache_for_admins()
{
	if (current_user_can('administrator') && get_rocket_option('cache_logged_user')) {
		add_action('template_redirect', 'cache_updater_donotcache', 1);
	}
}


/**
 * Define "do not cache" constants.
 * @since 1.0
 */
function cache_updater_donotcache()
{
	defined('DONOTCACHEPAGE') || define('DONOTCACHEPAGE', true);
	defined('DONOTROCKETOPTIMIZE') || define('DONOTROCKETOPTIMIZE', true);
}