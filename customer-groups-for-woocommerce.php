<?php
/**
 * Plugin Name: Customer Groups for WooCommerce
 * Description: Implements custom customer groups and pricing tiers for WooCommerce.
 * Version: 1.0.0
 * Author: CueFox
 * Text Domain: wccg
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WCCG_VERSION', '1.1.0');
define('WCCG_FILE', __FILE__);
define('WCCG_PATH', plugin_dir_path(WCCG_FILE));
define('WCCG_URL', plugin_dir_url(WCCG_FILE));
define('WCCG_BASENAME', plugin_basename(WCCG_FILE));

/**
 * Main plugin class
 */
final class WCCG_Customer_Groups {
    /**
     * Single instance of the class
     *
     * @var WCCG_Customer_Groups
     */
    private static $instance = null;

    /**
     * Main plugin instance
     *
     * @return WCCG_Customer_Groups
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        // Check dependencies before proceeding

        // Handle upgrades
        $this->handle_upgrades();

        // Initialize hooks
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
    // Check WooCommerce dependency
        add_action('plugins_loaded', array($this, 'check_dependencies'), 1);

    // Load plugin text domain
        add_action('init', array($this, 'load_textdomain'));

    // Register activation and deactivation hooks
        register_activation_hook(WCCG_FILE, array($this, 'activate'));
        register_deactivation_hook(WCCG_FILE, array($this, 'deactivate'));

    // Initialize plugin components (only if dependencies are met)
        add_action('plugins_loaded', array($this, 'init_plugin'), 20);
    }

    /**
     * Check plugin dependencies
     *
     * @return bool
     */
    public function check_dependencies() {
        $dependencies_met = true;

    // Check PHP version
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            add_action('admin_notices', array($this, 'php_version_notice'));
            $dependencies_met = false;
        }

    // Check WordPress version
        if (version_compare($GLOBALS['wp_version'], '5.8', '<')) {
            add_action('admin_notices', array($this, 'wp_version_notice'));
            $dependencies_met = false;
        }

    // Check if WooCommerce is active
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_notice'));
            $dependencies_met = false;
        }

    // Check WooCommerce version if it's active
        if (class_exists('WooCommerce') && defined('WC_VERSION') && 
            version_compare(WC_VERSION, '5.0', '<')) {
            add_action('admin_notices', array($this, 'woocommerce_version_notice'));
        $dependencies_met = false;
    }

    return $dependencies_met;
}

    /**
 * Handle plugin upgrades
 */
    private function handle_upgrades() {
        $installed_version = get_option('wccg_version');

        if ($installed_version !== WCCG_VERSION) {
        // Only run upgrades after autoloader is initialized
            add_action('plugins_loaded', function() use ($installed_version) {
                if (class_exists('WCCG_Database')) {
                    $db = WCCG_Database::instance();
                    $upgrade_success = $db->run_upgrades($installed_version);

                    if ($upgrade_success) {
                        update_option('wccg_version', WCCG_VERSION);
                    } else {
                        if (class_exists('WCCG_Utilities')) {
                            WCCG_Utilities::instance()->log_error(
                                'Plugin upgrade failed',
                                array(
                                    'from_version' => $installed_version,
                                    'to_version' => WCCG_VERSION
                                ),
                                'critical'
                            );
                        }
                    }
                }
        }, 5); // Priority 5 to run before other plugin initializations
        }
    }

    /**
     * Load plugin text domain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wccg',
            false,
            dirname(WCCG_BASENAME) . '/languages/'
        );
    }

    /**
     * Plugin activation hook
     */
    public function activate() {
        require_once WCCG_PATH . 'includes/class-activator.php';
        WCCG_Activator::activate();
    }

    /**
     * Plugin deactivation hook
     */
    public function deactivate() {
        // Clear scheduled hooks
        wp_clear_scheduled_hook('wccg_cleanup_cron');
        wp_clear_scheduled_hook('wccg_check_expired_rules');
    }

    /**
     * Initialize plugin components
     */
    public function init_plugin() {
    // Only initialize if dependencies are met
        if (!$this->check_dependencies()) {
            return;
        }

    // Load autoloader first
        require_once WCCG_PATH . 'includes/class-autoloader.php';

    // Initialize autoloader
        new WCCG_Autoloader();

    // Verify autoloader is working
        if (!class_exists('WCCG_Admin')) {
            error_log('WCCG Autoloader failed to load WCCG_Admin class');
            return;
        }

    // Initialize core components
        $this->init_core_components();

    // Initialize admin or public components based on context
        if (is_admin()) {
            $this->init_admin();
        } else {
            $this->init_public();
        }

    // Initialize common hooks
        $this->init_common_hooks();
    }

    /**
     * Initialize core components
     */
    private function init_core_components() {
        // Initialize core functionality
        WCCG_Core::instance();

        // Initialize database handler
        WCCG_Database::instance();

        // Initialize utilities
        WCCG_Utilities::instance();
    }

    /**
     * Initialize admin components
     */
    private function init_admin() {
        // Initialize main admin handler
        $admin = WCCG_Admin::instance();

        // Initialize admin page handlers
        WCCG_Admin_Customer_Groups::instance();
        WCCG_Admin_User_Assignments::instance();
        WCCG_Admin_Pricing_Rules::instance();

        // Ensure admin menu is added
        add_action('admin_menu', array($admin, 'add_menu_items'));
    }


    /**
     * Initialize public components
     */
    private function init_public() {
        // Initialize public-facing functionality
        WCCG_Public::instance();
    }

    /**
     * Initialize common hooks
     */
    private function init_common_hooks() {
        // Add cleanup task hook
        add_action('wccg_cleanup_cron', array($this, 'run_cleanup_tasks'));

        // Register custom cron schedule for rule expiration checks
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));

        // Add init hook for potential future use
        add_action('init', array($this, 'init'));
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing cron schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules) {
        // Add 5-minute interval for rule expiration checks
        $schedules['wccg_five_minutes'] = array(
            'interval' => 5 * MINUTE_IN_SECONDS,
            'display'  => esc_html__('Every 5 Minutes', 'wccg')
        );
        return $schedules;
    }

    /**
     * WordPress init hook callback
     */
    public function init() {
        // Load text domain
        $this->load_textdomain();

        // Register any custom post types or taxonomies if needed in the future
    }

    /**
     * Run cleanup tasks
     */
    public function run_cleanup_tasks() {
        $db = WCCG_Database::instance();
        $db->cleanup_orphaned_data();
        $db->cleanup_old_logs();
    }

    /**
     * PHP version notice
     */
    public function php_version_notice() {
        $message = sprintf(
            esc_html__('Customer Groups for WooCommerce requires PHP version %s or higher.', 'wccg'),
            '7.4'
        );
        $this->display_error_notice($message);
    }

    /**
     * WordPress version notice
     */
    public function wp_version_notice() {
        $message = sprintf(
            esc_html__('Customer Groups for WooCommerce requires WordPress version %s or higher.', 'wccg'),
            '5.8'
        );
        $this->display_error_notice($message);
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_notice() {
        $message = esc_html__('Customer Groups for WooCommerce requires WooCommerce to be installed and activated.', 'wccg');
        $this->display_error_notice($message);
    }

    /**
     * WooCommerce version notice
     */
    public function woocommerce_version_notice() {
        $message = sprintf(
            esc_html__('Customer Groups for WooCommerce requires WooCommerce version %s or higher.', 'wccg'),
            '5.0'
        );
        $this->display_error_notice($message);
    }

    /**
     * Display error notice
     *
     * @param string $message
     */
    private function display_error_notice($message) {
        ?>
        <div class="notice notice-error">
            <p><?php echo $message; ?></p>
        </div>
        <?php
    }
}

/**
 * Initialize the plugin
 *
 * @return WCCG_Customer_Groups
 */
function WCCG() {
    return WCCG_Customer_Groups::instance();
}

// Start the plugin
WCCG();
