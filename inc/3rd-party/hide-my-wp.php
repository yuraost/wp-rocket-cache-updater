<?php
/**
 * Integration for Hide My WP Security Plugin.
 * @author Yuriy Ostapchuk
 * @since 1.0
 */

/**
 * Prevent loading this file directly.
 */
defined('ABSPATH') || exit();

/**
 * Convert file URL to path.
 * @param string $file File URL.
 * @return string File path.
 * @since 1.0
 *
 * @hook rocket_url_to_path
 *
 */
add_filter('rocket_url_to_path', 'cache_updater_hmwp_url_to_path');
function cache_updater_hmwp_url_to_path($file)
{
	$hide_my_wp = get_option('hide_my_wp');
	$plugins = get_option('rm_hide_my_wp_plugins');

	if (!function_exists('is_plugin_active') || !is_plugin_active('hide_my_wp/hide-my-wp.php')) {
		return $file;
	}

	if ($plugins && isset($hide_my_wp['rename_plugins']) && !empty($hide_my_wp['rename_plugins']) && isset($hide_my_wp['new_plugin_path']) && !empty($hide_my_wp['new_plugin_path'])) {
		foreach ($plugins as $index => $item) {
			if (isset($item['original_path']) && isset($item['rewrite_path'])) {
				$search_arr[] = $item['rewrite_path'];
				$replace_arr[] = $item['original_path'];
			}
		}
	}

	if (isset($hide_my_wp['new_theme_path']) && !empty($hide_my_wp['new_theme_path'])) {
		$search_arr[] = $hide_my_wp['new_theme_path'];
		$replace_arr[] = str_replace(site_url(), '', get_stylesheet_directory_uri());
	}
	if (isset($hide_my_wp['new_plugin_path']) && !empty($hide_my_wp['new_plugin_path'])) {
		$search_arr[] = $hide_my_wp['new_plugin_path'];
		$replace_arr[] = str_replace(site_url(), '', plugins_url());
	}
	if (isset($hide_my_wp['new_include_path']) && !empty($hide_my_wp['new_include_path'])) {
		$search_arr[] = $hide_my_wp['new_include_path'];
		$replace_arr[] = str_replace(site_url(), '', includes_url());
	}
	if (isset($hide_my_wp['new_content_path']) && !empty($hide_my_wp['new_content_path'])) {
		$search_arr[] = $hide_my_wp['new_content_path'];
		$replace_arr[] = str_replace(site_url(), '', content_url());
	}

	if (!empty($search_arr) && !empty($replace_arr)) {
		$file = str_replace($search_arr, $replace_arr, $file);
	}

	return $file;
}