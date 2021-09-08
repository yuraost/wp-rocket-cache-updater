<?php
defined('ABSPATH') || exit();

/**
 * Class Cache_Updater_Sync for syncing between servers
 *
 * @since 1.0
 * @author Yuriy Ostapchuk
 */
class Cache_Updater_Sync {
	/**
	 * Current server IP address
	 *
	 * @var string
	 */
	private $current_server_ip;

	/**
	 * IP addresses of other servers in auto scaling group
	 *
	 * @var array
	 */
	private $other_servers_ip = null;

	/**
	 * Current server IP address
	 *
	 * @var string
	 */
	private $ftp_user = 'www-data';

	private $container_path = '/var/www/';
	private $volume_path = '/var/lib/docker/volumes/www/_data/';

	/**
	 * Constructor
	 *
	 * @since 1.0
	 * @author Yuriy Ostapchuk
	 */
	public function __construct($current_server_ip) {
		$this->current_server_ip = $current_server_ip;
	}

	private function get_other_servers_ip() {
		if (is_null($this->other_servers_ip)) {
			$this->other_servers_ip = array();

			try {
				$creds = new \Aws\Credentials\Credentials(env('AWS_ACCESS_KEY'), env('AWS_SECRET_KEY'));

				$asg_client = new \Aws\AutoScaling\AutoScalingClient(array(
					'version'		=> 'latest',
					'region'		=> env('AWS_REGION'),
					'credentials'	=> $creds
				));	

				$groups = $asg_client->describeAutoScalingGroups(array(
					'AutoScalingGroupNames'	=> array(env('ASG_NAME')),
					'MaxRecords'			=> 1
				));

				$group = array_shift($groups['AutoScalingGroups']);
				$instances_ids = array_column($group['Instances'], 'InstanceId');

				$ec2_client = new Aws\Ec2\Ec2Client(array(
					'version'		=> 'latest',
					'region'		=> env('AWS_REGION'),
					'credentials'	=> $creds
				));

				$instances = $ec2_client->describeInstances(array(
					'InstanceIds' => $instances_ids
				));

				foreach (array_column($instances['Reservations'], 'Instances') as $instances) {
					foreach ($instances as $instance) {
						if ($instance['PublicIpAddress'] != $this->current_server_ip && $this->valid_ipv4_address($instance['PublicIpAddress'])) {
							$this->other_servers_ip[] = $instance['PublicIpAddress'];
						}
					}
				}
			} catch (Exception $e) {
				$this->log($e->getMessage(), 'error');
			}
		}

		return $this->other_servers_ip;
	}

	private function valid_ipv4_address($ip) {
		$ipv4_pattern = '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/';

		return preg_match($ipv4_pattern, $ip);
	}

	public function sync($source) {
		if (empty($source) || !is_dir($source)) {
			$this->log('sync: ' . $source . ' is not dir', 'error');
			return false;
		}

		$other_servers_ip = $this->get_other_servers_ip();

		foreach ($this->other_servers_ip as $ip) {
			
			$fingerprint = false;
			exec("ssh-keygen -l -F {$ip}", $output);
			if (!empty($output) && is_array($output)) {
				foreach($output as $line){
					if (strpos($line, "Host {$ip} found") !== false) {
						$fingerprint = true;
						break;
					}
				}
			}

			if (!$fingerprint) {
				exec ("ssh-keyscan -H {$ip} >> /var/www/.ssh/known_hosts");
				$this->log("Fingerprint for {$ip} adedd. Current server ip {$this->current_server_ip}");
			}

			$destination = $this->ftp_user . '@' . $ip . ':' . $this->convert_path($source);

			exec('rsync -a -P --delete -e "ssh -i /var/www/.ssh/rsync" ' . $source . ' ' . $destination . ' > /dev/null');

			$this->log('rsync -a -P --delete -e "ssh -i /var/www/.ssh/rsync" ' . $source . ' ' . $destination . ' > /dev/null');
		}

		return true;
	}

	private function convert_path($path) {
		$path = strpos($path, $this->container_path) === 0 ? str_replace($this->container_path, $this->volume_path, $path) : '';
		return $path;
	}

	private function log($msg, $type = 'log') {
		if (!file_exists(CACHE_UPDATER_LOG_PATH)) {
			mkdir(CACHE_UPDATER_LOG_PATH);
		}

		$suffix = $type == 'error' ? '-error' : '';
		file_put_contents(CACHE_UPDATER_LOG_PATH . 'cache-updater-sync' . $suffix . '.log', date("Y-m-d H:i:s") . ' ' . $msg . PHP_EOL, FILE_APPEND);
	}

}