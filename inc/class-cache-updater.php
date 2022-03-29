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
	 * Domain
	 *
	 * @var string
	 */
	private $domain;

	/**
	 * Full URL where WP Rocket stored minified css and js
	 *
	 * @var string
	 */
	private $minify_cache_url;

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
		add_action('save_post', array($this, 'save_post'));
		add_action('trashed_post', array($this, 'after_delete_post'));
		add_action('after_delete_post', array($this, 'after_delete_post'));
		add_action('cache_updater_update_expired', array($this, 'update_expired'));
		add_action('wp', array($this, 'add_cronjob'));
		add_action('init', array($this, 'maybe_run_cache_update'));
	}

	public function refresh_urls() {
		global $wpdb;

		$all_urls = array('/');

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

		// set priority for home page
		$wpdb->query(
			"UPDATE {$this->table} SET priority = 1 WHERE URL = '/'"
		);

		$this->log('refresh_urls: done');
	}

	public function update_cache_async($autorun = 0) {
		if (!$autorun) {
			set_transient('cache_updater_stop', 0);
			set_transient('cache_updater_running', 1);
		}

		$url = add_query_arg(array(
			'run_cache_update'	=> 1
		), home_url());

		$args = array(
			'timeout'		=> 0.01,
			'user-agent'	=> 'WP Rocket/Preload',
			'blocking'		=> false,
			'redirection'	=> 0,
			'sslverify'		=> false
		);

		wp_remote_get($url, $args);
	}

	public function maybe_run_cache_update() {
		if (isset($_GET['run_cache_update']) && $_GET['run_cache_update'] == 1) {
			if ($_SERVER['SERVER_ADDR'] !== $_SERVER['REMOTE_ADDR']) {
				$this->log('maybe_run_cache_update: SERVER_ADDR and REMOTE_ADDR do not match', 'error');
				return false;
			}

			$this->update_cache();
		}
	}

	public function update_cache() {
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
		if (empty($url)){
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
			['state' => 'updating', 'updated_time' => current_time('mysql')],
			['URL' => $url]
		);

		// delete cached html files
		$this->clean_files($url);

		// maybe delete minified css and js files
		$this->clean_minified_files($url);

        $this->clean_cloudflare_cache($url);

		// generate cache
		$_url = esc_url_raw(home_url($url));

		$args = array(
			'timeout'		=> 15,
			'user-agent'	=> 'WP Rocket/Preload',
			'blocking'		=> true,
			'redirection'	=> 0,
			'sslverify'		=> false,
			'headers'		=> array(
				'Accept'		=> 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9',
				'HTTP_ACCEPT'	=> 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.9'
			)
		);

		$response = wp_remote_get($_url, $args);
		$body = wp_remote_retrieve_body($response);
		if (empty($body)){
			$this->log('update_cache: updating_error empty($body)');
			$this->updating_error($url);
		} else {
			if (get_rocket_option('do_caching_mobile_files') == 1) {
				$args['user-agent'] = 'Mozilla/5.0 (Linux; Android 8.0.0; SM-G955U Build/R16NW) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/87.0.4280.141 Mobile Safari/537.36';
				wp_remote_get($_url, $args);
			}
		}

		$wpdb->update($this->table,
			['state' => 'updating-error', 'updated_time' => current_time('mysql'), 'css_file' => NULL, 'css_file_mob' => NULL, 'js_file' => NULL, 'js_file_mob' => NULL],
			['state' => 'updating']
		);

		sleep(1);
		
		if ($this->stopping_update()) {
			$this->log('update_cache: updating was stop by admin');
			set_transient('cache_updater_stop', 0);
			set_transient('cache_updater_running', 0);
		} else {
			$this->update_cache_async(1);
		}

		die();
	}

	public function updating_cache_process($cache_dir_path) {
		global $wpdb;

        $cache_mobile = get_rocket_option('do_caching_mobile_files') == 1;
		$mob = $cache_mobile && wp_is_mobile() ? '_mob' : '';

		$this->log('updating_cache_process: start ' . $mob);

		// TODO: maybe handle cache for logged in users
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
			'updated_time' => current_time('mysql'),
			'css_file'.$mob => $min['css_file'],
			'js_file'.$mob => $min['js_file']
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
			"SELECT css_file, css_file_mob, js_file, js_file_mob
			 FROM {$this->table}
			 WHERE URL = %s",
			 $url
		));

        $clean_cf_urls = array();

        // TODO: optimize 4 blocks under
		if (is_file(WP_ROCKET_MINIFY_CACHE_PATH . $min->css_file)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE css_file = %s",
				$min->css_file
			));

			if ($count == 1) {
				unlink(WP_ROCKET_MINIFY_CACHE_PATH . $min->css_file);
                $clean_cf_urls[] = $this->get_minify_cache_url() . $min->css_file;
			}
		}

		if (is_file(WP_ROCKET_MINIFY_CACHE_PATH . $min->css_file_mob)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE css_file_mob = %s",
				$min->css_file_mob
			));

			if ($count == 1) {
				unlink(WP_ROCKET_MINIFY_CACHE_PATH . $min->css_file_mob);
                $clean_cf_urls[] = $this->get_minify_cache_url() . $min->css_file_mob;
			}
		}

		if (is_file(WP_ROCKET_MINIFY_CACHE_PATH . $min->js_file)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE js_file = %s",
				$min->js_file
			));

			if ($count == 1) {
				unlink(WP_ROCKET_MINIFY_CACHE_PATH . $min->js_file);
                $clean_cf_urls[] = $this->get_minify_cache_url() . $min->js_file;
			}
		}

		if (is_file(WP_ROCKET_MINIFY_CACHE_PATH . $min->js_file_mob)) {
			$count = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*)
				 FROM {$this->table}
				 WHERE js_file_mob = %s",
				$min->js_file_mob
			));

			if ($count == 1) {
				unlink(WP_ROCKET_MINIFY_CACHE_PATH . $min->js_file_mob);
                $clean_cf_urls[] = $this->get_minify_cache_url() . $min->js_file_mob;
			}
		}

        if (!empty($clean_cf_urls)) {
            $this->clean_cloudflare_cache($clean_cf_urls);
        }
	}

    private function clean_cloudflare_cache($url) {
        $cf_email = get_rocket_option('cloudflare_email', null);
        $cf_api_key = (defined('WP_ROCKET_CF_API_KEY')) ? WP_ROCKET_CF_API_KEY : get_rocket_option('cloudflare_api_key', null);
        $cf_zone_id = get_rocket_option('cloudflare_zone_id', null);
        $is_api_keys_valid_cf = rocket_is_api_keys_valid_cloudflare($cf_email, $cf_api_key, $cf_zone_id, true);

        if (is_wp_error($is_api_keys_valid_cf)) return false;

        if (!is_array($url)) {
            $url = array($url);
        }

        foreach ($url as $k => $_url) {
            if (strpos($_url, 'https://' . $this->domain) !== 0)  {
                $url[$k] = 'https://' . $this->domain . $_url;
            }
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.cloudflare.com/client/v4/zones/' . $cf_zone_id . '/purge_cache');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(array('files'=>$url)));

        $headers = array(
            'X-Auth-Email: ' . $cf_email,
            'X-Auth-Key: ' . $cf_api_key,
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

			$pattern = '~' . preg_quote($this->get_minify_cache_url(), '/') . '\S+~';
			if (preg_match_all($pattern, $html, $links)) {
				foreach ($links[0] as $link) {
					$path = str_replace($this->get_minify_cache_url(), '', trim($link, '"'));
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

	private function get_minify_cache_url() {
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

	public function need_update($type = 'all') {
		global $wpdb;

		$this->log('need_update: ' . $type);

		$this->update_rocket_minify_key();

		$sql = "UPDATE {$this->table} SET state = 'need-update', updated_time = '" . current_time('mysql') . "'";

		if ($type == 'expired') {
			$sql = $wpdb->prepare($sql . " WHERE updated_time < %s", date('Y-m-d H:i:s', strtotime("-12 hour")));
		} else {
			$sql = $wpdb->prepare($sql . " WHERE state != 'updating-error' OR (state = 'updating-error' AND updated_time < %s)", date('Y-m-d H:i:s', strtotime("-12 hour")));
		}

		$wpdb->query($sql);
	}

	public function need_update_url($urls) {
		global $wpdb;

		$this->log('need_update_url: ' . json_encode($urls));

		$this->update_rocket_minify_key();

		$urls = (array) $urls;
		$placeholders = implode(',', array_fill(0, count($urls), '%s'));
		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'need-update', updated_time = '" . current_time('mysql') . "' WHERE URL IN ({$placeholders})",
			$urls
		));
	}

	private function update_rocket_minify_key(){
		$wp_rocket_settings = get_option('wp_rocket_settings');
		$wp_rocket_settings['minify_css_key'] = function_exists('create_rocket_uniqid') ? create_rocket_uniqid() : '';
		$wp_rocket_settings['minify_js_key'] = function_exists('create_rocket_uniqid') ? create_rocket_uniqid() : '';
		update_option('wp_rocket_settings', $wp_rocket_settings);
	}

	private function updating_error($url) {
		global $wpdb;

		$wpdb->query($wpdb->prepare(
			"UPDATE {$this->table} SET state = 'updating-error', updated_time = '" . current_time('mysql') . "' WHERE URL = '{$url}'",
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
			if ((strtotime($row->updated_time) + 60) < current_time('timestamp')) {
				$this->log('is_updating_cache: updating_error ' . $row->URL);
				$this->updating_error($row->URL);
				continue;
			}

			$updating = true;
		}

		return $updating;
	}

	public function get_state() {
		global $wpdb;

		$running = !empty(get_transient('cache_updater_running'));

		$need_update = $wpdb->get_var(
			"SELECT COUNT(*)
			 FROM {$this->table}
			 WHERE state = 'need-update'"
		);

		return array(
			'state'					=> $running ? 'updating' : ($need_update ? 'need-update' : 'updated'),
			'need-update'			=> $need_update
		);
	}
	
	public function add_wp_rocket_hook() {
		$content = file_get_contents(CACHE_UPDATER_CLASS_CACHE_FILE);

		$hook_str = 'do_action("cache_updater_page_cached", $cache_dir_path);';
		if (strpos($content, $hook_str) === false) {
			$content = str_replace('$this->maybe_create_nginx_mobile_file( $cache_dir_path );', '$this->maybe_create_nginx_mobile_file( $cache_dir_path );' . PHP_EOL . PHP_EOL . "\t\t" . $hook_str, $content);
			file_put_contents(CACHE_UPDATER_CLASS_CACHE_FILE, $content);
			$this->log('add_wp_rocket_hook: added hook');
		}
	}

	public function stop_updating() {
		global $wpdb;

		$this->log('stop_updating');

		$wpdb->update($this->table,
			['state' => 'need-update', 'updated_time' => current_time('mysql')],
			['state' => 'updating']
		);

		set_transient('cache_updater_stop', 1);
		set_transient('cache_updater_running', 0);
	}

	public function stopping_update() {
		$val = get_transient('cache_updater_stop');

		return !empty($val);
	}	
	
	public function save_post($id) {
		$this->log('save_post');
		$this->refresh_urls();
		$url = parse_url(get_permalink($id), PHP_URL_PATH);
		$this->need_update_url($url);
		$this->update_cache_async();
	}

	public function after_delete_post() {
		$this->log('after_delete_post');
		$this->refresh_urls();
		$this->need_update('all');
		$this->update_cache_async();
	}

	public function update_expired() {
		$this->log('update_expired');
		$this->need_update('expired');
		$this->update_cache_async();
	}

	public function add_cronjob() {
		if (!wp_next_scheduled('cache_updater_update_expired')) {
			wp_schedule_event(time(), 'hourly', 'cache_updater_update_expired');
		}
	}

	public function log($msg, $type = 'log') {
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