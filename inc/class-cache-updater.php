<?php
defined('ABSPATH') || exit();

/**
 * Main plugin class Cache_Updater
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
class Cache_Updater {
	/**
	 * The single instance of the class
	 *
	 * @var Cache_Updater object
	 */
	private static $_instance = null;

	/**
	 * Table name
	 *
	 * @var string
	 */
	private $table;

	/**
	 * Current server public IP address
	 *
	 * @var string
	 */
	private $server_addr;

	/**
	 * Domain
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * Cache Updater Sync object
	 *
	 * @var object
	 */
	private $sync = null;

	/**
	 * Nginx cache path
	 *
	 * @var string
	 */
	private $nginx_cache_path = '/var/www/cache/rocketstack/';

	/**
	 * Constructor
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	public function __construct() {
		// Prevent duplication of hooks
		if (self::$_instance) {
			return;
		}
		self::$_instance = $this;

		global $wpdb;

		$this->table = $wpdb->prefix . 'cache_updater';
		$this->domain = parse_url(home_url(), PHP_URL_HOST);

		add_action('cache_updater_page_cached', array($this, 'updating_cache_process'));
		add_action('update_cache_maybe_run', array($this, 'update_cache_maybe_run'));
		add_action('save_post', array($this, 'save_post'));
		add_action('cache_updater_update_expired', array($this, 'update_expired'));
		add_action('wp', array($this, 'add_cronjob'));
	}

	public function refresh_urls() {
		global $wpdb;

		$all_urls = array();

		// get posts URLs
		$posts_query = new WP_Query(array(
			'post_type'			=> 'any',
			'posts_per_page'	=> -1,
			'post_status'		=> 'publish'
		));
		if ($posts_query->have_posts()) {
			while ($posts_query->have_posts()) {
				$posts_query->the_post();
				$all_urls[] = parse_url(get_the_permalink(), PHP_URL_PATH);
			}
		}
		wp_reset_query();

		// get taxonomies URLs
		$terms = get_terms(array(
			'taxonomy'	=> get_taxonomies(['public' => true])
		));
		$terms_urls = is_a($terms, 'WP_Error') ? array() : array_map('get_term_link', $terms);
		foreach ($terms_urls as $url) {
			$all_urls[] = parse_url($url, PHP_URL_PATH);
		}

		// remove duplicates
		$all_urls = array_unique($all_urls);

		// remove URLs rejected by WP Rocket
		$cache_reject_uri = function_exists('get_rocket_option') ? get_rocket_option('cache_reject_uri') : false;
		if (is_array($cache_reject_uri)) {
			$all_urls = array_diff($all_urls, $cache_reject_uri);
		}

		// existing URLs in DB
		$db_urls = $wpdb->get_col(
			"SELECT URL
			 FROM {$this->table}"
		);

		// delete non-existent URLs
		$urls_to_delete = array_diff($db_urls, $all_urls);
		if (!empty($urls_to_delete)) {
			$urls_to_delete = "'" . implode("','", $urls_to_delete) . "'";
			$wpdb->query(
				"DELETE FROM {$this->table} WHERE URL IN ({$urls_to_delete})"
			);
		}

		// insert new URLs
		$urls_to_insert = array_diff($all_urls, $db_urls);
		if (!empty($urls_to_insert)) {
			$urls_to_insert = "('" . implode("'),('", $urls_to_insert) . "')";
			$wpdb->query(
				"INSERT INTO {$this->table} (URL) VALUES {$urls_to_insert}"
			);
		}

		// TODO: set priority

		$this->log('refresh_urls: done');
	}


	public function update_cache() {
		global $wpdb;

		$this->log('update_cache: start');

		// prevent multiple updating processes at the same time
		if ($this->is_updating_cache()) {
			$this->log('update_cache: break. Another process is running');
			return false;
		}

		if (!$this->update_next()) {
			$this->log('update_cache: updating was stop by admin');
			$this->stop_updating(0);
			return false;
		}

		// add hook to the WP Rocket core
		$this->add_wp_rocket_hook();

		// get url for updating
		$url = $this->get_url_for_update();
		if (empty($url)){
			$this->log('update_cache: break. No urls for updating');
			return false;
		}

		$this->log('update_cache: updating url ' . $url);

		// mark the url as currently updating
		$wpdb->update("{$this->table}",
			['state' => 'updating', 'updated_time' => current_time('mysql')],
			['URL' => $url]
		);

		// delete cached html files
		$this->clean_files($url);

		// maybe delete minified css and js files
		$this->clean_minified_files($url);

		$this->clean_nginx_cache();

		// synchronize between all servers
		$this->get_sync()->sync(WP_ROCKET_CACHE_ROOT_PATH);
		$this->get_sync()->sync(dirname($this->nginx_cache_path) . '/');

		$this->clean_cloudflare_cache($url);

		// generate cache
		$response = wp_remote_get(esc_url_raw(home_url($url)), [
			'timeout'		=> 0.01,
			'user-agent'	=> 'WP Rocket/Preload',
			'blocking'		=> false,
			'redirection'	=> 0,
			'sslverify'		=> false,
			'headers'		=> array(
				'Accept'		=> 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9'
			)
		]);

		$this->update_cache_schedule(10);
	}

	public function update_cache_maybe_run() {
		if ($this->is_updating_cache()) {
			$this->update_cache_schedule();
		} else {
			$this->update_cache();
		}
	}

	public function update_cache_schedule($seconds = 60) {
		wp_unschedule_event(wp_next_scheduled('update_cache_maybe_run'), 'update_cache_maybe_run');
		wp_schedule_single_event(time() + $seconds, 'update_cache_maybe_run');
	}

	public function updating_cache_process($cache_dir_path) {
		global $wpdb;

		$this->log('updating_cache_process: start');

		// TODO: maybe handle cache for logged in users
		$wp_rocket_cache_dir = WP_ROCKET_CACHE_PATH . $this->domain;
		if (strpos($cache_dir_path, $wp_rocket_cache_dir) === false) {
			$this->log('updating_cache_process: break. strpos(' . $cache_dir_path . ', ' . $wp_rocket_cache_dir . ') === false');
			return false;
		}
		$url = str_replace($wp_rocket_cache_dir, '', $cache_dir_path);
		$url = $url . '/';

		$this->log('updating_cache_process: updating url ' . $url);

		$min = $this->get_minified_files($cache_dir_path);

		// mark the url as currently updated on the current server
		$wpdb->update("{$this->table}",
			['state' => 'updated', 'server_ip' => $this->get_current_server_ip(), 'updated_time' => current_time('mysql'), 'css_file' => $min['css_file'], 'js_file' => $min['js_file']],
			['URL' => $url]
		);

		// synchronize between all servers
		$this->get_sync()->sync(WP_ROCKET_CACHE_ROOT_PATH);
		$this->get_sync()->sync(dirname($this->nginx_cache_path) . '/');

		// prevent database overloading
		sleep(1);

		// update next url
		$this->update_cache();
	}

	private function get_url_for_update() {
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

	private function clean_files($url) {
		$dir = WP_ROCKET_CACHE_PATH . $this->domain . $url;

		if (!is_dir($dir)) return false;
	
		$entries = [];
		try {
			foreach (new FilesystemIterator($dir) as $entry) {
				$entries[] = $entry->getPathname();
			}
		} catch (Exception $e) { // phpcs:disable Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			$this->log('clean_files: error: ' . $e->getMessage() . '. URL: ' . $url);
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

	private function clean_minified_files($url) {
		global $wpdb;

		$min = $wpdb->get_row($wpdb->prepare(
			"SELECT css_file, js_file
			 FROM {$this->table}
			 WHERE URL = %s",
			 $url
		));

		if (is_file($min->css_file)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE css_file = %s",
				$min->css_file
			));

			if ($count == 1) {
				unlink($min->css_file);
			}
		}

		if (is_file($min->js_file)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE js_file = %s",
				$min->js_file
			));

			if ($count == 1) {
				unlink($min->js_file);
			}
		}
	}

	private function clean_nginx_cache() {
		if (is_dir($this->nginx_cache_path)) {
			$this->rrmdir($this->nginx_cache_path);
		}
	}

	private function rrmdir($dir) {
		$files = array_diff(scandir($dir), array('.','..'));
		foreach ($files as $file) {
			(is_dir("$dir/$file")) ? $this->rrmdir("$dir/$file") : unlink("$dir/$file");
		}
		rmdir($dir);
	}

	private function clean_cloudflare_cache($url) {
		$cf = array(
			'zone_id'	=> env('CF_ZONE_ID'),
			'email'		=> env('CF_EMAIL'),
			'api_token'	=> env('CF_API_TOKEN')
		);
		$cf = array_filter($cf);
		if (count($cf) != 3) return false;

		$url = 'https://' . $this->domain . $url;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $cf['zone_id'] . '/purge_cache');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('files'=>array($url))));

		$headers = array(
			'X-Auth-Email: ' . $cf['email'],
			'X-Auth-Key: ' . $cf['api_token'],
			'Content-Type: application/json'
		);
		curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

		$result = curl_exec($ch);		

		if (curl_errno($ch) || json_decode($result)->success !== true) {
			$this->log('Can\'t purge Cloudflare cache: ' . trim($result, PHP_EOL));
		}

		curl_close($ch);
	}

	private function get_minified_files($cache_dir_path) {
		$result = array(
			'css_file'	=> '',
			'js_file'	=> ''
		);

		$cache_file = $cache_dir_path . '/index-https.html';
		if (file_exists($cache_file)) {
			$html = file_get_contents($cache_file);

			$pattern = '~' . preg_quote(WP_ROCKET_MINIFY_CACHE_URL, '/') . '\S+~';
			preg_match_all($pattern, $html, $links);
			if (preg_match_all($pattern, $html, $links)) {
				foreach ($links[0] as $link) {
					$path = str_replace(WP_ROCKET_MINIFY_CACHE_URL, '', trim($link, '"'));
					if (strrpos($path, '.css') === (strlen($path) - 4)) {
						$result['css_file'] = $path;
					} elseif (strrpos($path, '.js') === (strlen($path) - 3) ) {
						$result['js_file'] = $path;
					}
				}
			}
		}

		return $result;
	}

	public function need_update($type = 'all') {
		global $wpdb;

		$sql = "UPDATE {$this->table} SET state = 'need-update', server_ip = '" . $this->get_current_server_ip() . "', updated_time = '" . current_time('mysql') . "' WHERE state != 'updating-error'";

		if ($type == 'expired') {
			$sql = $wpdb->prepare($sql . " AND updated_time < %s", date('Y-m-d H:i:s', strtotime("-12 hour")));
		} elseif ($type != 'all') {
			// TODO: add column type into database
			$sql = $wpdb->prepare($sql . " AND type = %s", $type);
		}

		$wpdb->query($sql);
	}

	public function need_update_url($urls) {
		global $wpdb;

		$urls = (array) $urls;
		$placeholders = array_fill(0, count($urls), '%s');
		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'need-update', server_ip = '" . $this->get_current_server_ip() . "', updated_time = '" . current_time('mysql') . "' WHERE URL IN ({$placeholders})",
			$urls
		));
	}

	private function updating_error($url) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'updating-error', server_ip = '" . $this->get_current_server_ip() . "', updated_time = '" . current_time('mysql') . "' WHERE URL = '{$url}'",
			$urls
		));
	}

	private function is_updating_cache() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT URL, updated_time
			 FROM {$this->table}
			 WHERE state = 'updating'"
		);

		$updating = false;
		foreach ($results as $row) {
			if ((strtotime($row->updated_time) + 60) < time()) {
				$this->updating_error($row->URL);
				$this->update_cache();
			}

			$updating = true;
		}

		return $updating;
	}

	public function get_state() {
		global $wpdb;

		$stopping = !$this->update_next();
		$is_updating_cache = $this->is_updating_cache();
		$need_update = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$this->table}
			 WHERE state = 'need-update'"
		);

		if ($stopping) {
			$need_update = $need_update - 2;
		} elseif ($is_updating_cache) {
			$need_update++;
		}

		return array(
			'$stopping'				=> $stopping,
			'$is_updating_cache'	=> $is_updating_cache,
			'state'					=> $is_updating_cache && !$stopping ? 'updating' : ($need_update ? 'need-update' : 'updated'),
			'need-update'			=> $need_update
		);
	}

	private function get_state_by_url($url) {
		global $wpdb;

		$state = $wpdb->get_var(
			"SELECT state
			 FROM {$this->table}
			 WHERE URL = '{$url}'"
		);

		return $state;
	}
	
	public function add_wp_rocket_hook() {
		$content = file_get_contents(CACHE_UPDATER_CLASS_CACHE_FILE);

		$hook_str = 'do_action("cache_updater_page_cached", $cache_dir_path);';
		if (strpos($content, $hook_str) === false) {
			$content = str_replace('$this->maybe_create_nginx_mobile_file( $cache_dir_path );', '$this->maybe_create_nginx_mobile_file( $cache_dir_path );' . PHP_EOL . PHP_EOL . "\t\t" . $hook_str, $content);
			file_put_contents(CACHE_UPDATER_CLASS_CACHE_FILE, $content);
			$this->log('add_wp_rocket_hook: added hook');

			$this->get_sync()->sync(WP_ROCKET_PATH);
		}
	}

	public function stop_updating($stop) {
		global $wpdb;

		$stop = empty($stop) ? 0 : 1;
		
		if ($stop) {
			$wpdb->query("UPDATE {$this->table} SET state = 'need-update', server_ip = '" . $this->get_current_server_ip() . "', updated_time = '" . current_time('mysql') . "' WHERE state = 'updating'");
		}

		wp_unschedule_event(wp_next_scheduled('update_cache_maybe_run'), 'update_cache_maybe_run');

		set_transient('cache_updater_stop', $stop);
	}

	private function update_next() {
		$val = get_transient('cache_updater_stop');
		return empty($val);
	}	
	
	public function save_post() {
		$this->refresh_urls();
		$this->update_cache();
	}

	public function update_expired() {
		$this->need_update('expired');
		$this->update_cache();
	}

	public function add_cronjob() {
		if (!wp_next_scheduled('cache_updater_update_expired')) {
			wp_schedule_event(time(), 'hourly', 'cache_updater_update_expired');
		}
	}

	private function get_current_server_ip() {
		if (empty($this->server_addr)) {
			$this->server_addr = @file_get_contents("http://169.254.169.254/latest/meta-data/public-ipv4");
		}

		return $this->server_addr;
	}

	public function get_sync() {
		if (is_null($this->sync)){
			$this->sync = new Cache_Updater_Sync($this->get_current_server_ip());
		}

		return $this->sync;
	}

	private function log($msg, $type = 'log') {
		if (!file_exists(CACHE_UPDATER_LOG_PATH)) {
			mkdir(CACHE_UPDATER_LOG_PATH);
		}

		$suffix = $type == 'error' ? '-error' : '';
		file_put_contents(CACHE_UPDATER_LOG_PATH . 'cache-updater' . $suffix . '.log', date("Y-m-d H:i:s") . ' ' . $msg . PHP_EOL, FILE_APPEND);
	}

	/**
	 * Main Cache_Updater instance
	 *
	 * @return Cache_Updater
	 */
	public static function instance() {
		if (!self::$_instance) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}
}