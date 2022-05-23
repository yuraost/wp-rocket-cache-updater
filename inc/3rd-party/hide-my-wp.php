<?php
if (!defined('ABSPATH')) {
	exit;
}

define('ECDEV_PLUGINS_URL', plugins_url());
define('ECDEV_INCLUDES_URL', includes_url());
define('ECDEV_CONTENT_URL', content_url());
define('ECDEV_SITE_URL', site_url());
define('ECDEV_THEME_URI', get_stylesheet_directory_uri());

add_filter('rocket_url_to_path', function ($file, $url) {
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
		$replace_arr[] = str_replace(ECDEV_SITE_URL, '', ECDEV_THEME_URI);
	}
	if (isset($hide_my_wp['new_plugin_path']) && !empty($hide_my_wp['new_plugin_path'])) {
		$search_arr[] = $hide_my_wp['new_plugin_path'];
		$replace_arr[] = str_replace(ECDEV_SITE_URL, '', ECDEV_PLUGINS_URL);
	}
	if (isset($hide_my_wp['new_include_path']) && !empty($hide_my_wp['new_include_path'])) {
		$search_arr[] = $hide_my_wp['new_include_path'];
		$replace_arr[] = str_replace(ECDEV_SITE_URL, '', ECDEV_INCLUDES_URL);
	}
	if (isset($hide_my_wp['new_content_path']) && !empty($hide_my_wp['new_content_path'])) {
		$search_arr[] = $hide_my_wp['new_content_path'];
		$replace_arr[] = str_replace(ECDEV_SITE_URL, '', ECDEV_CONTENT_URL);
	}

	if (!empty($search_arr) && !empty($replace_arr)) {
		$file = str_replace($search_arr, $replace_arr, $file);
	}

	return $file;
}, 10, 2);