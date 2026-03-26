<?php
/**
 * Plugin Name: Alynt Customer Groups
 * Plugin URI:  https://github.com/NichlasB/alynt-customer-groups
 * Description: Implements custom customer groups and pricing tiers for WooCommerce.
 * Version: 2.0.0
 * Author: Alynt
 * Author URI: https://alynt.com
 * GitHub Plugin URI: NichlasB/alynt-customer-groups
 * Text Domain: alynt-customer-groups
 * Domain Path: /languages
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package Alynt_Customer_Groups
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WCCG_VERSION', '2.0.0' );
define( 'WCCG_FILE', __FILE__ );
define( 'WCCG_PATH', plugin_dir_path( WCCG_FILE ) );
define( 'WCCG_URL', plugin_dir_url( WCCG_FILE ) );
define( 'WCCG_BASENAME', plugin_basename( WCCG_FILE ) );

require_once WCCG_PATH . 'includes/class-activator.php';
require_once WCCG_PATH . 'includes/class-deactivator.php';
require_once WCCG_PATH . 'includes/class-plugin-admin-notices.php';
require_once WCCG_PATH . 'includes/class-plugin-dependencies.php';
require_once WCCG_PATH . 'includes/class-plugin-bootstrap.php';
require_once WCCG_PATH . 'includes/class-security-helper.php';
require_once WCCG_PATH . 'includes/class-input-sanitizer.php';
require_once WCCG_PATH . 'includes/class-rate-limiter.php';
require_once WCCG_PATH . 'includes/class-logger.php';
require_once WCCG_PATH . 'includes/class-utilities.php';
require_once WCCG_PATH . 'includes/class-database.php';
require_once WCCG_PATH . 'includes/class-user-group-repository.php';
require_once WCCG_PATH . 'includes/class-pricing-rule-repository.php';
require_once WCCG_PATH . 'includes/class-maintenance-repository.php';
require_once WCCG_PATH . 'includes/class-core.php';

/**
 * Main plugin entry point. Bootstraps initialization and delegates to WCCG_Plugin_Bootstrap.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
final class WCCG_Customer_Groups extends WCCG_Plugin_Admin_Notices {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Customer_Groups|null
	 */
	private static $instance = null;

	/**
	 * Plugin bootstrap coordinator.
	 *
	 * @var WCCG_Plugin_Bootstrap
	 */
	private $bootstrap;

	/**
	 * Dependency checker and upgrade scheduler.
	 *
	 * @var WCCG_Plugin_Dependencies
	 */
	private $dependencies;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Customer_Groups
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize bootstrap and dependency services.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->bootstrap    = WCCG_Plugin_Bootstrap::instance();
		$this->dependencies = WCCG_Plugin_Dependencies::instance();
		$this->dependencies->schedule_upgrade_check();
		$this->bootstrap->register( $this );
	}

	/**
	 * Check whether all plugin dependencies (PHP, WP, WooCommerce versions) are met.
	 *
	 * @since  1.0.0
	 * @return bool True if all dependencies pass, false otherwise.
	 */
	public function check_dependencies() {
		return $this->bootstrap->check_dependencies();
	}

	/**
	 * Load the plugin text domain for translations.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function load_textdomain() {
		$this->bootstrap->load_textdomain();
	}

	/**
	 * Initialize core plugin components after dependency checks pass.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init_plugin() {
		$this->bootstrap->init_plugin();
	}

	/**
	 * Register custom WP-Cron intervals.
	 *
	 * @since  1.1.0
	 * @param  array $schedules Existing cron schedule definitions.
	 * @return array Modified schedules including the wccg_five_minutes interval.
	 */
	public function add_cron_schedules( $schedules ) {
		return $this->bootstrap->add_cron_schedules( $schedules );
	}

	/**
	 * Run early init tasks hooked to WordPress 'init'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function init() {
		$this->bootstrap->init();
	}

	/**
	 * Execute scheduled database cleanup tasks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function run_cleanup_tasks() {
		$this->bootstrap->run_cleanup_tasks();
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
/**
 * Return the main plugin instance.
 *
 * @since  1.0.0
 * @return WCCG_Customer_Groups
 */
function WCCG() {
	return WCCG_Customer_Groups::instance();
}
// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed

register_activation_hook( WCCG_FILE, array( 'WCCG_Activator', 'activate' ) );
register_deactivation_hook( WCCG_FILE, array( 'WCCG_Deactivator', 'deactivate' ) );

WCCG();
