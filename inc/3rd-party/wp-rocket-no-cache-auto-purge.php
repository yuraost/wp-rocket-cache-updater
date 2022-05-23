<?php
/**
 * Disable cache auto purge.
 * @author Yuriy Ostapchuk
 * @since 1.0
 */

/**
 * Prevent loading this file directly.
 */
defined('ABSPATH') || exit();

/**
 * Remove all of WP Rocket's cache purging actions.
 * @since 1.0
 *
 * @hook wp_rocket_loaded
 */
add_action('wp_rocket_loaded', 'cache_updater_remove_purge_hooks');
function cache_updater_remove_purge_hooks()
{
	$clean_domain_hooks = array(
		'add_link',
		'avada_clear_dynamic_css_cache',
		'customize_save',
		'create_term',
		'deleted_user',
		'delete_term',
		'delete_link',
		'edited_terms',
		'edit_link',
		'profile_update',
		'permalink_structure_changed',
		'switch_theme',
		'update_option_theme_mods_' . get_option('stylesheet'),
		'update_option_sidebars_widgets',
		'update_option_category_base',
		'update_option_tag_base',
		'user_register',
		'wp_update_nav_menu'
	);
	foreach ($clean_domain_hooks as $key => $handle) {
		remove_action($handle, 'rocket_clean_domain');
	}

	$clean_post_hooks = array(
		'wp_trash_post',
		'delete_post',
		'clean_post_cache',
		'wp_update_comment_count',
	);
	foreach ($clean_post_hooks as $key => $handle) {
		remove_action($handle, 'rocket_clean_post');
	}

	remove_filter('widget_update_callback', 'rocket_widget_update_callback');
	remove_action('upgrader_process_complete', 'rocket_clean_cache_theme_update', 10, 2);
	remove_action('pre_post_update', 'rocket_clean_post_cache_on_status_change', 10, 2);
	remove_action('pre_post_update', 'rocket_clean_post_cache_on_slug_change', PHP_INT_MAX, 2);

	/**
	 * Disable User cache purge.
	 */
	$container = apply_filters('rocket_container', '');
	$container->get('event_manager')->remove_callback('profile_update', [$container->get('purge_actions_subscriber'), 'purge_user_cache']);
	$container->get('event_manager')->remove_callback('delete_user', [$container->get('purge_actions_subscriber'), 'purge_user_cache']);
	$container->get('event_manager')->remove_callback('create_term', [$container->get('purge_actions_subscriber'), 'maybe_purge_cache_on_term_change']);
	$container->get('event_manager')->remove_callback('edit_term', [$container->get('purge_actions_subscriber'), 'maybe_purge_cache_on_term_change']);
	$container->get('event_manager')->remove_callback('delete_term', [$container->get('purge_actions_subscriber'), 'maybe_purge_cache_on_term_change']);

	/**
	 * Disable cache clearing after saving a WooCoommerce product variation.
	 */
	$container->get('event_manager')->remove_callback('woocommerce_save_product_variation', [$container->get('woocommerce_subscriber'), 'clean_cache_after_woocommerce_save_product_variation']);

	/**
	 * Disable Elementor cache purge.
	 */
	add_action('wp_loaded', function () {
		$container = apply_filters('rocket_container', '');
		$container->get('event_manager')->remove_callback('added_post_meta', [$container->get('elementor_subscriber'), 'maybe_clear_cache'], 10, 3);
		$container->get('event_manager')->remove_callback('deleted_post_meta', [$container->get('elementor_subscriber'), 'maybe_clear_cache'], 10, 3);
		$container->get('event_manager')->remove_callback('elementor/core/files/clear_cache', [$container->get('elementor_subscriber'), 'clear_cache']);
		$container->get('event_manager')->remove_callback('update_option__elementor_global_css', [$container->get('elementor_subscriber'), 'clear_cache']);
		$container->get('event_manager')->remove_callback('delete_option__elementor_global_css', [$container->get('elementor_subscriber'), 'clear_cache']);
	});
}

/**
 * Remove Avada's cache purging actions.
 * @since 1.0
 *
 * @hook wp
 */
add_action('wp', 'cache_updater_avada_remove_purge_hooks');
function cache_updater_avada_remove_purge_hooks()
{
	remove_action('avada_clear_dynamic_css_cache', 'rocket_clean_domain');
	remove_action('fusion_cache_reset_after', 'rocket_avada_clear_cache_fusion_patcher');
}

/**
 * Disallow purge cache for all users.
 * @param array $allcaps
 * @return array
 * @since 1.0
 */
add_filter('user_has_cap', 'cache_updater_remove_cap_rocket_purge_cache', 99);
function cache_updater_remove_cap_rocket_purge_cache($allcaps)
{
	if (isset($allcaps['rocket_purge_cache'])) unset($allcaps['rocket_purge_cache']);
	if (isset($allcaps['rocket_manage_options'])) unset($allcaps['rocket_manage_options']);

	return $allcaps;
}

/**
 * Filter options.
 * @since 1.0
 * @hook pre_option_ls_clear_3rd_party_caches
 */
add_filter('pre_option_ls_clear_3rd_party_caches', '__return_false');

/**
 * Filter clean domain urls.
 * @since 1.0
 * @hook rocket_clean_domain_urls
 */
add_filter('rocket_clean_domain_urls', function () {
	return array();
});