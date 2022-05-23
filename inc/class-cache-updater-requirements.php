<?php
defined('ABSPATH') || exit();

/**
 * Class to check if the current WordPress, WP Rocket and PHP versions meet our requirements
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
class Cache_Updater_Requirements
{
	/**
	 * Plugin Name
	 *
	 * @var string
	 */
	private $plugin_name;

	/**
	 * Plugin version
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Required WordPress version
	 *
	 * @var string
	 */
	private $wp_version;

	/**
	 * Required PHP version
	 *
	 * @var string
	 */
	private $php_version;

	/**
	 * Required WP Rocket version
	 *
	 * @var string
	 */
	private $wp_rocket_version;

	/**
	 * Path to WP Rocket class-cache.php
	 *
	 * @var string
	 */
	private $class_cache_php;

	/**
	 * Store notices if any
	 *
	 * @var array
	 */
	private $notices;

	/**
	 * Constructor
	 *
	 * @param array $args {
	 *     Arguments to populate the class properties
	 *
	 * @type string $plugin_name Plugin name
	 * @type string $plugin_version Plugin version
	 * @type string $wp_version Required WordPress version
	 * @type string $php_version Required PHP version
	 * @type string $wp_rocket_version Required WP Rocket version
	 * }
	 * @author Yuriy Ostapchuk
	 *
	 * @since 1.0
	 */
	public function __construct($args)
	{
		foreach ($args as $arg => $value) {
			if (property_exists('Cache_Updater_Requirements', $arg)) {
				$this->$arg = $value;
			}
		}
	}

	/**
	 * Checks if all requirements passed, if not - display a notice
	 *
	 * @return bool
	 * @author Yuriy Ostapchuk
	 *
	 * @since 1.0
	 */
	public function check()
	{
		$this->notices = array();

		$this->php_version_notice();
		$this->wp_version_notice();
		$this->wp_rocket_notice();

		if (!empty($this->notices)) {
			add_action('admin_notices', array($this, 'notice'));
			return false;
		}

		return true;
	}

	/**
	 * Checks if the current PHP version is equal or superior to the required PHP version
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	private function php_version_notice()
	{
		if (!version_compare(PHP_VERSION, $this->php_version, '>=')) {
			$this->add_notice('PHP ' . $this->php_version);
		}
	}

	/**
	 * Add notice
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	private function add_notice($notice)
	{
		$this->notices[] = $notice;
	}

	/**
	 * Checks if the current WordPress version is equal or superior to the required WordPress version
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	private function wp_version_notice()
	{
		global $wp_version;
		if (!version_compare($wp_version, $this->wp_version, '>=')) {
			$this->add_notice('WordPress ' . $this->wp_version);
		}
	}

	/**
	 * Checks if the WP Rocket is active and the current WP Rocket version is equal or superior to the required WP Rocket version
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	private function wp_rocket_notice()
	{
		if (!in_array('wp-rocket/wp-rocket.php', get_option('active_plugins', array()))) {
			$this->add_notice('WP Rocket ' . $this->wp_rocket_version . ' activated');
		} elseif (!version_compare(WP_ROCKET_VERSION, $this->wp_rocket_version, '>=')) {
			$this->add_notice('WP Rocket ' . $this->wp_rocket_version);
		} elseif (!file_exists($this->class_cache_php)) {
			$this->add_notice('WP Rocket class Cache in file ' . $this->class_cache_php);
		}
	}

	/**
	 * Warns if one of the requirements did not pass
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	public function notice()
	{
		printf(
			'<div class="notice notice-error">
				<p>To function properly, <strong>%s %s</strong> requires at least: </p>
				<ul>
					<li>%s</li>
				</ul>
			</div>',
			$this->plugin_name,
			$this->plugin_version,
			implode('</li><li>', $this->notices)
		);
	}
}