<?php
/**
 * Main plugin class Cache_Updater
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */

/**
 * Prevent loading this file directly.
 */
defined('ABSPATH') || exit();

class Cache_Updater
{
	/**
	 * The single instance of the class
	 * @var Cache_Updater object
	 */
	private static $_instance = null;

	/**
	 * Table name
	 * @var string
	 */
	private $table;

	/**
	 * Domain
	 * @var string
	 */
	private $domain;

	/**
	 * Full URL where WP Rocket stored minified css and js
	 * @var string
	 */
	private $minify_cache_url;

	/**
	 * Cache update queue priority
	 * @var array
	 */
	private $priority_map = array(
		'page' => 2,
		'post' => 1,
		'category' => 0
	);

	/**
	 * Define if need update post type and additional resources that should be updated with that type
	 * @var array
	 */
	private $type_update = array(
		'page' => [],
		'post' => [
			'url' => '/blog/'
		]
	);

	/**
	 * Constructor
	 * @since 1.0
	 */
	public function __construct()
	{
		/**
		 * Prevent duplication of hooks
		 */
		if (self::$_instance) {
			return;
		}
		self::$_instance = $this;

		global $wpdb;

		$this->table = $wpdb->prefix . 'cache_updater';
		$this->domain = parse_url(home_url(), PHP_URL_HOST);

		/**
		 * Add hooks
		 */
		add_action('cache_updater_page_cached', array($this, 'updating_cache_process'));
		add_action('post_updated', array($this, 'post_updated'), 10, 3);
		add_action('save_post', array($this, 'save_post'), 10, 3);
		add_action('wp_trash_post', array($this, 'trash_post'));
		add_action('acf/save_post', array($this, 'after_save_theme_settings'));
		add_filter('widget_update_callback', array($this, 'after_widget_update'));
		add_filter('delete_widget', array($this, 'after_widget_update'));
		add_action('cache_updater_update_expired', array($this, 'update_expired'));
		add_action('wp', array($this, 'add_cronjob'));
		add_action('init', array($this, 'maybe_run_cache_update'));
	}

	/**
	 * Main Cache_Updater instance
	 * @return Cache_Updater
	 * @since 1.0
	 */
	public static function instance()
	{
		if (!self::$_instance) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Run update cache process
	 * @since 1.0
	 */
	public function maybe_run_cache_update()
	{
		if (isset($_GET['run_cache_update']) && $_GET['run_cache_update'] == 1) {
			if ($_SERVER['SERVER_ADDR'] !== $_SERVER['REMOTE_ADDR']) {
				$this->log('maybe_run_cache_update: SERVER_ADDR and REMOTE_ADDR do not match', 'error');
				return false;
			}

			$this->update_cache();
		}
	}

	/**
	 * Write debug info into log file
	 * @param string $msg
	 * @param string $type Possible values are "log", "error" or "cloudflare".
	 * @since 1.0
	 */
	public function log($msg, $type = 'log')
	{
		if (!file_exists(CACHE_UPDATER_LOG_PATH)) {
			mkdir(CACHE_UPDATER_LOG_PATH);
		}

		$suffix = in_array($type, ['error', 'cloudflare']) ? '-' . $type : '';
		file_put_contents(CACHE_UPDATER_LOG_PATH . 'cache-updater' . $suffix . '.log', gmdate("Y-m-d H:i:s") . ' ' . $msg . PHP_EOL, FILE_APPEND);
	}

	/**
	 * Update cache process
	 * @since 1.0
	 */
	public function update_cache()
	{
		global $wpdb;

		$this->log('update_cache: start');

		// prevent multiple updating processes at the same time
		if ($this->is_updating_cache()) {
			$this->log('update_cache: break. Another process is running');
			return false;
		}

		// add hook to the WP Rocket core
		$this->add_wp_rocket_hook();

		// get url for updating
		$url = $this->get_url_for_update();
		if (empty($url)) {
			$this->log('update_cache: break. No urls for updating');
			set_transient('cache_updater_running', 0);
			return false;
		}

		if (!$this->stopping_update()) {
			set_transient('cache_updater_running', 1);
		}

		$this->log('update_cache: updating url ' . $url);

		// mark the url as currently updating
		$wpdb->update("{$this->table}",
			['state' => 'updating', 'updated_time' => current_time('mysql', true)],
			['URL' => $url]
		);

		// clean all cached files (html, css, js, cloudflare)
		$this->clean_cache_by_url($url);

		// generate cache
		$_url = esc_url_raw(home_url($url));

		$args = array(
			'timeout' => 15,
			'user-agent' => 'WP Rocket/Preload',
			'blocking' => true,
			'redirection' => 0,
			'sslverify' => false,
			'headers' => array(
				'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
				'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9'
			)
		);

		$response = wp_remote_get($_url, $args);
		$body = wp_remote_retrieve_body($response);
		if (empty($body)) {
			$this->log('update_cache: updating_error empty($body)');
			$this->updating_error($url);
		} else {
			if (get_rocket_option('do_caching_mobile_files') == 1) {
				$args['user-agent'] = 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.141 Mobile Safari/537.36';
				wp_remote_get($_url, $args);
			}
		}

		$wpdb->update($this->table,
			['state' => 'updating-error', 'updated_time' => current_time('mysql', true), 'css_file' => NULL, 'css_file_mob' => NULL, 'js_file' => NULL, 'js_file_mob' => NULL],
			['state' => 'updating']
		);

		usleep(1500000);

		if ($this->stopping_update()) {
			$this->log('update_cache: updating was stop by admin');
			set_transient('cache_updater_stop', 0);
			set_transient('cache_updater_running', 0);
		} else {
			$this->update_cache_async(1);
		}

		die();
	}

	/**
	 * Check if cache is updating
	 * @return bool
	 * @since 1.0
	 */
	private function is_updating_cache()
	{
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT URL, updated_time
			 FROM {$this->table}
			 WHERE state = 'updating'"
		);

		$updating = false;
		foreach ($results as $row) {
			if ((strtotime($row->updated_time) + 60) < current_time('timestamp', true)) {
				$this->log('is_updating_cache: updating_error ' . $row->URL);
				$this->updating_error($row->URL);
				continue;
			}

			$updating = true;
		}

		return $updating;
	}

	/**
	 * Write updating error status into DB
	 * @param string $url
	 * @since 1.0
	 */
	private function updating_error($url)
	{
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'updating-error', updated_time = '" . current_time('mysql', true) . "' WHERE URL = %s",
			$url
		));
	}

	/**
	 * Write necessary hook for cache updater into Wp Rocket class
	 * @since 1.0
	 */
	public function add_wp_rocket_hook()
	{
		$content = file_get_contents(CACHE_UPDATER_CLASS_CACHE_FILE);

		$hook_str = 'do_action("cache_updater_page_cached", $cache_dir_path);';
		if (strpos($content, $hook_str) === false) {
			$content = str_replace('$this->maybe_create_nginx_mobile_file( $cache_dir_path );', '$this->maybe_create_nginx_mobile_file( $cache_dir_path );' . PHP_EOL . PHP_EOL . "\t\t" . $hook_str, $content);
			file_put_contents(CACHE_UPDATER_CLASS_CACHE_FILE, $content);
			$this->log('add_wp_rocket_hook: added hook');
		}
	}

	/**
	 * Get url that need to be updated.
	 * @return string
	 * @since 1.0
	 */
	private function get_url_for_update()
	{
		global $wpdb;

		$url = $wpdb->get_var(
			"SELECT URL
			 FROM {$this->table}
			 WHERE state = 'need-update'
			 ORDER BY priority DESC
			 LIMIT 1"
		);

		return $url;
	}

	/**
	 * Stop cache updating process.
	 * @since 1.0
	 */
	public function stopping_update()
	{
		$val = get_transient('cache_updater_stop');
		return !empty($val);
	}

	/**
	 * Clean all cached resources by URL.
	 * @param string $url
	 * @since 1.0
	 */
	private function clean_cache_by_url($url)
	{
		// delete cached html files
		$this->clean_files($url);

		// maybe delete minified css and js files
		$this->clean_minified_files($url);

		// delete cloudflare cache
		$this->clean_cloudflare_cache($url);

		// delete pagination dir if exists
		$this->clean_pagination($url);
	}

	/**
	 * Remove HTML files by URL.
	 * @param string $url
	 * @since 1.0
	 */
	private function clean_files($url)
	{
		$dir = WP_ROCKET_CACHE_PATH . $this->domain . $url;

		if (!is_dir($dir)) return false;

		$entries = [];
		try {
			foreach (new FilesystemIterator($dir) as $entry) {
				$entries[] = $entry->getPathname();
			}
		} catch (Exception $e) {
			$this->log('clean_files: error: ' . $e->getMessage() . '. URL: ' . $url, 'error');
		}

		$delete_dir = true;
		foreach ($entries as $entry) {
			if (is_dir($entry)) {
				$delete_dir = false;
			}

			if (is_file($entry)) {
				unlink($entry);
			}
		}

		if ($delete_dir) {
			rmdir($dir);
		}
	}

	/**
	 * Remove minified CSS and JS  files by URL.
	 * @param string $url
	 * @since 1.0
	 */
	private function clean_minified_files($url)
	{
		global $wpdb;

		$min = $wpdb->get_row($wpdb->prepare(
			"SELECT css_file, css_file_mob, js_file, js_file_mob
			 FROM {$this->table}
			 WHERE URL = %s",
			$url
		));

		$clean_cf_urls = array();

		$files = array(
			'css_file' => $min->css_file,
			'css_file_mob' => $min->css_file_mob,
			'js_file' => $min->js_file,
			'js_file_mob' => $min->js_file_mob
		);
		$files = array_filter($files);
		foreach ($files as $key => $file) {
			if (is_file(WP_ROCKET_MINIFY_CACHE_PATH . $file)) {
				$count = $wpdb->get_var($wpdb->prepare(
					"SELECT COUNT(*)
				    FROM {$this->table}
				    WHERE {$key} = %s",
					$file
				));

				if ($count == 1) {
					unlink(WP_ROCKET_MINIFY_CACHE_PATH . $file);
					$clean_cf_urls[] = $this->get_minify_cache_url() . $file;
				}
			}
		}

		if (!empty($clean_cf_urls)) {
			$this->clean_cloudflare_cache($clean_cf_urls);
		}
	}

	/**
	 * Get URL of minified CSS and JS files.
	 * @return string
	 * @since 1.0
	 */
	private function get_minify_cache_url()
	{
		if (empty($this->minify_cache_url)) {
			$this->minify_cache_url = WP_ROCKET_MINIFY_CACHE_URL;

			if (function_exists('is_plugin_active') && is_plugin_active('hide_my_wp/hide-my-wp.php')) {
				$hide_my_wp = get_option('hide_my_wp');
				if (!empty($hide_my_wp['new_content_path'])) {
					$this->minify_cache_url = str_replace('/wp-content', $hide_my_wp['new_content_path'], $this->minify_cache_url);
				}
			}
		}

		return $this->minify_cache_url;
	}

	/**
	 * Remove cloudflare cached resources by URLs.
	 * @param array $urls
	 * @since 1.0
	 */
	private function clean_cloudflare_cache($urls)
	{
		$cf_email = get_rocket_option('cloudflare_email', null);
		$cf_api_key = (defined('WP_ROCKET_CF_API_KEY')) ? WP_ROCKET_CF_API_KEY : get_rocket_option('cloudflare_api_key', null);
		$cf_zone_id = get_rocket_option('cloudflare_zone_id', null);
		$is_api_keys_valid_cf = rocket_is_api_keys_valid_cloudflare($cf_email, $cf_api_key, $cf_zone_id, true);

		if (is_wp_error($is_api_keys_valid_cf)) return false;

		if (!is_array($urls)) {
			$urls = array($urls);
		}

		$parts = [];
		foreach ($urls as $k => $url) {
			$pk = floor(($k + 1) / 30);
			if (!isset($parts[$pk])) {
				$parts[$pk] = [];
			}
			$parts[$pk][] = strpos($url, 'https://' . $this->domain) !== 0 ? 'https://' . $this->domain . $url : $url;
		}

		foreach ($parts as $urls) {
			$data = json_encode(array('files' => $urls));

			$ch = curl_init();
			curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $cf_zone_id . '/purge_cache');
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_POST, 1);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $data);

			$headers = array(
				'X-Auth-Email: ' . $cf_email,
				'X-Auth-Key: ' . $cf_api_key,
				'Content-Type: application/json'
			);
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

			$result = curl_exec($ch);

			$this->log('Try to purge Cloudflare cache: ' . $data, 'cloudflare');
			if (curl_errno($ch) || json_decode($result)->success !== true) {
				$this->log('Can\'t purge Cloudflare cache: ' . trim($result, PHP_EOL), 'cloudflare');
			} else {
				$this->log('Cloudflare cache purged', 'cloudflare');
			}

			curl_close($ch);
		}
	}

	/**
	 * Remove pagination HTML files by URL.
	 * @param string $url
	 * @since 1.0
	 */
	private function clean_pagination($url)
	{
		$pagination_dir = WP_ROCKET_CACHE_PATH . $this->domain . $url . 'page/';
		if (is_dir($pagination_dir)) {
			$removed_paths = $this->rrmdir($pagination_dir);

			if (!empty($removed_paths)) {
				$removed_paths = array_map(function ($path) {
					return str_replace(WP_ROCKET_CACHE_PATH . $this->domain, '', (rtrim($path, '/') . '/'));
				}, $removed_paths);
				$this->clean_cloudflare_cache($removed_paths);
			}
		}
	}

	/**
	 * Remove directory recursive.
	 * @param string $dir
	 * @return array $paths Removed paths
	 * @since 1.0
	 */
	private function rrmdir($dir)
	{
		$paths = [];
		$iterator = new RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($iterator as $filename => $fileInfo) {
			if ($fileInfo->isDir()) {
				$paths[] = $filename;
				rmdir($filename);
			} else {
				unlink($filename);
			}
		}
		rmdir($dir);
		return $paths;
	}

	/**
	 * Run update process asynchronously.
	 * @param bool $autorun
	 * @since 1.0
	 */
	public function update_cache_async($autorun = false)
	{
		if (!$autorun) {
			set_transient('cache_updater_stop', 0);
			set_transient('cache_updater_running', 1);
		}

		$url = add_query_arg(array(
			'run_cache_update' => 1
		), home_url());

		$args = array(
			'timeout' => 0.01,
			'user-agent' => 'WP Rocket/Preload',
			'blocking' => false,
			'redirection' => 0,
			'sslverify' => false
		);

		wp_remote_get($url, $args);
	}

	/**
	 * Updating cache process.
	 * @param string $cache_dir_path
	 * @since 1.0
	 */
	public function updating_cache_process($cache_dir_path)
	{
		global $wpdb;

		$cache_mobile = get_rocket_option('do_caching_mobile_files') == 1;
		$mob = $cache_mobile && wp_is_mobile() ? '_mob' : '';

		$this->log('updating_cache_process: start ' . $mob);

		$wp_rocket_cache_dir = WP_ROCKET_CACHE_PATH . $this->domain;
		if (strpos($cache_dir_path, $wp_rocket_cache_dir) === false) {
			$this->log('updating_cache_process: break. strpos(' . $cache_dir_path . ', ' . $wp_rocket_cache_dir . ') === false ' . $mob);
			return false;
		}
		$url = str_replace($wp_rocket_cache_dir, '', $cache_dir_path);
		$url = $url . '/';

		$this->log('updating_cache_process: updating url ' . $url . ' ' . $mob);

		$min = $this->get_minified_files($cache_dir_path);

		$update = array(
			'updated_time' => current_time('mysql', true),
			'css_file' . $mob => $min['css_file'],
			'js_file' . $mob => $min['js_file']
		);

		if (!$cache_mobile || !empty($mob)) {
			$update['state'] = 'updated';
		}

		// mark the url as currently updated on the current server
		$wpdb->update("{$this->table}",
			$update,
			['URL' => $url]
		);
	}

	/**
	 * Find minified CSS and JS in HTML.
	 * @param string $cache_dir_path
	 * @return array
	 * @since 1.0
	 */
	private function get_minified_files($cache_dir_path)
	{
		$result = array(
			'css_file' => '',
			'js_file' => ''
		);

		$cache_file = $cache_dir_path . '/index-https.html';
		if (file_exists($cache_file)) {
			$html = file_get_contents($cache_file);

			$pattern = '~' . preg_quote($this->get_minify_cache_url(), '/') . '\S+~';
			if (preg_match_all($pattern, $html, $links)) {
				foreach ($links[0] as $link) {
					$path = str_replace($this->get_minify_cache_url(), '', trim($link, '"'));
					if (strrpos($path, '.css') === (strlen($path) - 4)) {
						$result['css_file'] = $path;
					} elseif (strrpos($path, '.js') === (strlen($path) - 3)) {
						$result['js_file'] = $path;
					}
				}
			}
		}

		return $result;
	}

	/**
	 * Get cache updating status.
	 * @return array
	 * @since 1.0
	 */
	public function get_state()
	{
		global $wpdb;

		$running = !empty(get_transient('cache_updater_running'));

		$need_update = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$this->table}
			 WHERE state = 'need-update'"
		);

		return array(
			'state' => $running ? 'updating' : ($need_update ? 'need-update' : 'updated'),
			'need-update' => $need_update
		);
	}

	/**
	 * Stop cache updating process.
	 * @since 1.0
	 */
	public function stop_updating()
	{
		global $wpdb;

		$this->log('stop_updating');

		$wpdb->update($this->table,
			['state' => 'need-update', 'updated_time' => current_time('mysql', true)],
			['state' => 'updating']
		);

		set_transient('cache_updater_stop', 1);
		set_transient('cache_updater_running', 0);
	}

	/**
	 * Update cache after post was updated.
	 * @param int $id
	 * @param object $post_after
	 * @param object $post_before
	 * @since 1.0
	 */
	public function post_updated($id, $post_after, $post_before)
	{
		$this->log('post_updated: id ' . $id);

		if (isset($_GET['action']) && $_GET['action'] == 'trash') {
			$this->log('post_updated: id ' . $id . '. Not need to update. Reason: action == trash');
			return;
		}

		$url = $post_after->post_status == 'publish' ? get_permalink($post_after) : ($post_before->post_status == 'publish' ? get_permalink($post_before) : '');

		if (empty($url)) {
			$this->log('post_updated: break. Url is empty. Post status before: ' . $post_before->post_status . '. Post status after: ' . $post_after->post_status);
			return;
		}

		$url = parse_url($url, PHP_URL_PATH);
		$this->save_trash_post($id, $post_after->post_status, $url);
	}

	/**
	 * Run update cache process after post was saved or trashed.
	 * @param int $id
	 * @param string $status
	 * @param string $url
	 * @since 1.0
	 */
	private function save_trash_post($id, $status, $url)
	{
		$this->log('save_trash_post: id ' . $id . ', status ' . $status . ', url ' . $url);

		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
			$this->log('save_post: id ' . $id . '. Not need to update. Reason: DOING_AUTOSAVE');
			return;
		} elseif (false !== wp_is_post_revision($id)) {
			$this->log('save_post: id ' . $id . '. Not need to update. Reason: wp_is_post_revision');
			return;
		}

		$post_type = get_post_type($id);
		if (!isset($this->type_update[$post_type])) {
			$this->log('save_trash_post: not need update post type ' . $post_type);
			return;
		}

		$this->refresh_urls();

		$urls = array();

		if ($status == 'publish') {
			$urls[] = $url;
		} else {
			$this->clean_cache_by_url($url);
		}

		if (isset($this->type_update[$post_type])) {
			if (!empty($this->type_update[$post_type]['url'])) {
				$urls[] = $this->type_update[$post_type]['url'];
			}
			if (isset($this->type_update[$post_type]['taxonomy'])) {
				$terms = get_the_terms($id, $this->type_update[$post_type]['taxonomy']);
				if ($terms && !is_wp_error($terms)) {
					foreach ($terms as $term) {
						$urls[] = parse_url(get_term_link($term), PHP_URL_PATH);
					}
				}
			}
		}

		if (!empty($urls)) {
			$this->need_update_url($urls);
			$this->update_cache_async();
		}
	}

	/**
	 * Update cache updater DB table.
	 * @since 1.0
	 */
	public function refresh_urls()
	{
		global $wpdb;

		$all_urls = array();

		// get posts URLs
		$posts_query = new WP_Query(array(
			'post_type' => array('page', 'post'),
			'posts_per_page' => -1,
			'post_status' => 'publish'
		));
		if ($posts_query->have_posts()) {
			while ($posts_query->have_posts()) {
				$posts_query->the_post();
				$type = get_post_type();
				$all_urls[] = array(
					'url' => parse_url(get_the_permalink(), PHP_URL_PATH),
					'type' => $type,
					'priority' => isset($this->priority_map[$type]) ? $this->priority_map[$type] : 0
				);
			}
		}
		wp_reset_query();

		// get taxonomies URLs
		$terms = get_terms(array(
			'taxonomy' => array('category')
		));
		if (!is_a($terms, 'WP_Error')) {
			foreach ($terms as $term) {
				$all_urls[] = array(
					'url' => parse_url(get_term_link($term), PHP_URL_PATH),
					'type' => $term->taxonomy,
					'priority' => isset($this->priority_map[$term->taxonomy]) ? $this->priority_map[$term->taxonomy] : 0
				);
			}
		}

		// remove URLs rejected by WP Rocket
		$cache_reject_uri = function_exists('get_rocket_option') ? get_rocket_option('cache_reject_uri') : false;
		if (is_array($cache_reject_uri)) {
			foreach ($all_urls as $k => $url) {
				if (array_search($url['url'], $cache_reject_uri) !== false) {
					unset($all_urls[$k]);
				}
			}
			$all_urls = array_values($all_urls);
		}

		// existing URLs in DB
		$db_urls = $wpdb->get_results(
			"SELECT URL, type, priority
			 FROM {$this->table}",
			ARRAY_A
		);

		// delete non-existent URLs
		$urls_to_delete = array();
		foreach ($db_urls as $k => $url) {
			$ak = array_search($url['URL'], array_column($all_urls, 'url'));
			if ($ak === false || $url['type'] != $all_urls[$ak]['type'] || $url['priority'] != $all_urls[$ak]['priority']) {
				$urls_to_delete[] = $url['URL'];
				unset($db_urls[$k]);
			}
		}
		if (!empty($urls_to_delete)) {
			$urls_to_delete = "'" . implode("','", $urls_to_delete) . "'";
			$wpdb->query(
				"DELETE FROM {$this->table} WHERE URL IN ({$urls_to_delete})"
			);
			$db_urls = array_values($db_urls);
		}

		// insert new URLs
		$urls_to_insert = array();
		foreach ($all_urls as $url) {
			$dk = array_search($url['url'], array_column($db_urls, 'URL'));
			if ($dk === false) {
				$urls_to_insert[] = '"' . implode('","', array($url['url'], $url['type'], $url['priority'])) . '"';
			}
		}
		if (!empty($urls_to_insert)) {
			$urls_to_insert = '(' . implode('),(', $urls_to_insert) . ')';
			$wpdb->query(
				"INSERT INTO {$this->table} (URL, type, priority) VALUES {$urls_to_insert}"
			);
		}

		$this->log('refresh_urls: done');
	}

	/**
	 * Set need-update status for URLs.
	 * @param array $urls
	 * @since 1.0
	 */
	public function need_update_url($urls)
	{
		global $wpdb;

		$this->log('need_update_url: ' . json_encode($urls));

		$this->update_rocket_minify_key();

		$urls = (array)$urls;
		$placeholders = implode(',', array_fill(0, count($urls), '%s'));
		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'need-update', updated_time = '" . current_time('mysql', true) . "' WHERE URL IN ({$placeholders})",
			$urls
		));
	}

	/**
	 * Update WP Rocket options minify_css_key and minify_js_key.
	 * @since 1.0
	 */
	private function update_rocket_minify_key()
	{
		$wp_rocket_settings = get_option('wp_rocket_settings');
		$wp_rocket_settings['minify_css_key'] = function_exists('create_rocket_uniqid') ? create_rocket_uniqid() : '';
		$wp_rocket_settings['minify_js_key'] = function_exists('create_rocket_uniqid') ? create_rocket_uniqid() : '';
		update_option('wp_rocket_settings', $wp_rocket_settings);
	}

	/**
	 * Update cache after new post was inserted.
	 * @param int $id
	 * @param object $post
	 * @param bool $update
	 * @since 1.0
	 */
	public function save_post($id, $post, $update)
	{
		// handle only if insert new post
		if ($update) return;
		$this->log('save_post: id ' . $id);
		$url = parse_url(get_permalink($id), PHP_URL_PATH);
		$this->save_trash_post($id, $post->post_status, $url);
	}

	/**
	 * Update cache after post was trashed.
	 * @param int $id
	 * @since 1.0
	 */
	public function trash_post($id)
	{
		$this->log('trash_post: id ' . $id);
		$url = parse_url(get_permalink($id), PHP_URL_PATH);
		$this->save_trash_post($id, 'trash', $url);
	}

	/**
	 * Update cache after theme settings was updated.
	 * @since 1.0
	 */
	public function after_save_theme_settings()
	{
		$option_pages = array(
			'toplevel_page_theme-general-settings',
			'theme-settings_page_home-prices-and-taxes',
			'theme-settings_page_ppc-landing-settings',
			'theme-settings_page_shortcodes-settings',
			'theme-settings_page_exit-popups'
		);
		$screen = get_current_screen();
		if (isset($screen->id) && in_array($screen->id, $option_pages)) {
			$this->log('after_save_theme_settings: screen id ' . $screen->id);
			$this->need_update('all');
			$this->update_cache_async();
		}
	}

	/**
	 * Set need-update status by type.
	 * @param string $type
	 * @since 1.0
	 */
	public function need_update($type = 'all')
	{
		global $wpdb;

		$this->log('need_update: ' . $type);

		$this->update_rocket_minify_key();

		$sql = "UPDATE {$this->table} SET state = 'need-update', updated_time = '" . current_time('mysql', true) . "'";

		if ($type == 'expired') {
			$sql = $wpdb->prepare($sql . " WHERE updated_time < %s", gmdate('Y-m-d H:i:s', strtotime("-12 hour")));
		} elseif (in_array($type, array('page', 'guide_cat', 'guide', 'post', 'regions', 'category', 'archive'))) {
			$sql = $wpdb->prepare($sql . " WHERE type = %s", $type);
		} else {
			$sql = $wpdb->prepare($sql . " WHERE state != 'updating-error' OR (state = 'updating-error' AND updated_time < %s)", gmdate('Y-m-d H:i:s', strtotime("-12 hour")));
		}

		$wpdb->query($sql);
	}

	/**
	 * Update cache after widget was updated.
	 * @param object $instance
	 * @return object
	 * @since 1.0
	 */
	public function after_widget_update($instance)
	{
		if (is_admin()) {
			$this->log('after_widget_update');
			$this->need_update('all');
			$this->update_cache_async();
		}
		return $instance;
	}

	/**
	 * Update expired URLs.
	 * @since 1.0
	 */
	public function update_expired()
	{
		$this->log('update_expired');
		$this->need_update('expired');
		$this->update_cache_async();
	}

	/**
	 * Add cronjob for trigger update expired URLs.
	 * @since 1.0
	 */
	public function add_cronjob()
	{
		if (!wp_next_scheduled('cache_updater_update_expired')) {
			wp_schedule_event(time(), 'hourly', 'cache_updater_update_expired');
		}
	}
}