<?php
defined('ABSPATH') || exit();

add_action('admin_bar_menu', 'cache_updater_admin_bar_menu', PHP_INT_MAX);
function cache_updater_admin_bar_menu($wp_admin_bar)
{
	$state = Cache_Updater::instance()->get_state();

	$wp_admin_bar->add_menu(array(
		'id' => 'cache-updater',
		'title' => 'Cache updater',
		'href' => '#',
		'meta' => array('class' => $state['state'])
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-status',
		'title' => '<span class="state-depended updated">Updated</span>
						<span class="state-depended need-update">Need update <span class="need-update-count">' . $state['need-update'] . '</span> item(s)</span>
						<span class="state-depended updating">Updating - <span class="need-update-count">' . $state['need-update'] . '</span> item(s) left</span>',
		'href' => '#'
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-stop',
		'title' => 'Stop updating',
		'href' => '#',
		'meta' => array('class' => 'state-depended updating')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-needed',
		'title' => 'Update cache - needed',
		'href' => '#needed',
		'meta' => array('class' => 'update-cache-start state-depended need-update')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start',
		'title' => 'Update cache',
		'href' => '#all',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-page',
		'title' => 'Update Pages',
		'href' => '#page',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-guide_cat',
		'title' => 'Update Guides Сategories',
		'href' => '#guide_cat',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-guide',
		'title' => 'Update Guides',
		'href' => '#guide',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-category',
		'title' => 'Update Posts Сategories',
		'href' => '#category',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-post',
		'title' => 'Update Posts',
		'href' => '#post',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-regions',
		'title' => 'Update Regions',
		'href' => '#regions',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));

	$wp_admin_bar->add_menu(array(
		'parent' => 'cache-updater',
		'id' => 'cache-updater-start-archive',
		'title' => 'Update Archives',
		'href' => '#archive',
		'meta' => array('class' => 'update-cache-start state-depended not-updating need-update updated')
	));
}

add_action('admin_enqueue_scripts', 'cache_updater_enqueue_scripts');
function cache_updater_enqueue_scripts()
{
	wp_enqueue_style('cache-updater', CACHE_UPDATER_ASSETS_URL . 'styles.css');
	wp_enqueue_script('cache-updater', CACHE_UPDATER_ASSETS_URL . 'scripts.js', array('jquery'), false, true);
}

add_action('wp_ajax_cache-updater-start', 'cache_updater_start_ajax');
function cache_updater_start_ajax()
{
	Cache_Updater::instance()->log('cache_updater_start_ajax: ' . json_encode($_POST['type']));

	if (in_array($_POST['type'], array('all', 'page', 'guide_cat', 'guide', 'post', 'regions', 'category', 'archive'))) {
		Cache_Updater::instance()->need_update($_POST['type']);
	}

	Cache_Updater::instance()->update_cache_async();
	wp_send_json(array(
		'state' => 'updating',
		'need-update' => '...'
	));
}

add_action('wp_ajax_cache-updater-stop', 'cache_updater_stop_ajax');
function cache_updater_stop_ajax()
{
	Cache_Updater::instance()->log('cache_updater_stop_ajax');

	Cache_Updater::instance()->stop_updating();
	$state = Cache_Updater::instance()->get_state();
	wp_send_json($state);
}

add_action('wp_ajax_cache-updater-state', 'cache_updater_state_ajax');
function cache_updater_state_ajax()
{
	$state = Cache_Updater::instance()->get_state();
	wp_send_json($state);
}

// wp-rocket options
add_filter('pre_get_rocket_option_cache_logged_user', function () {
	return 0;
}, PHP_INT_MAX);

add_filter('pre_get_rocket_option_purge_cron_interval', function () {
	return 0;
}, PHP_INT_MAX);

add_filter('pre_get_rocket_option_manual_preload', function () {
	return 0;
}, PHP_INT_MAX);