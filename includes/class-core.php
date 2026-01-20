<?php
/**
 * Core plugin functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Core {
    /**
     * Single instance of the class
     *
     * @var WCCG_Core
     */
    private static $instance = null;

    /**
     * Database handler instance
     *
     * @var WCCG_Database
     */
    private $db;

    /**
     * Utilities instance
     *
     * @var WCCG_Utilities
     */
    private $utils;

    /**
     * Get class instance
     *
     * @return WCCG_Core
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
        $this->db = WCCG_Database::instance();
        $this->utils = WCCG_Utilities::instance();

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Cleanup hooks
        add_action('wccg_cleanup_cron', array($this, 'run_cleanup_tasks'));

        // Schedule expiration check hook (runs more frequently)
        add_action('wccg_check_expired_rules', array($this, 'deactivate_expired_rules'));

        // Add clean up schedule verification
        add_action('admin_init', array($this, 'verify_cleanup_schedule'));
        add_action('admin_init', array($this, 'verify_expiration_schedule'));
    }

    /**
     * Verify cleanup schedule
     */
    public function verify_cleanup_schedule() {
        if (!wp_next_scheduled('wccg_cleanup_cron')) {
            wp_schedule_event(
                strtotime('tomorrow 2am'), // Run at 2 AM
                'daily',
                'wccg_cleanup_cron'
            );
        }
    }

    /**
     * Verify expiration check schedule
     */
    public function verify_expiration_schedule() {
        if (!wp_next_scheduled('wccg_check_expired_rules')) {
            // Run every 5 minutes to check for expired rules
            wp_schedule_event(time(), 'wccg_five_minutes', 'wccg_check_expired_rules');
        }
    }

    /**
     * Deactivate expired pricing rules and clear caches
     *
     * @return array Results of the operation
     */
    public function deactivate_expired_rules() {
        global $wpdb;
        
        $results = array(
            'deactivated_count' => 0,
            'activated_count' => 0,
            'cache_cleared' => false
        );
        
        $table = $wpdb->prefix . 'pricing_rules';
        
        // Find rules that have passed their end_date but are still marked as active
        $expired_rules = $wpdb->get_col(
            "SELECT rule_id FROM {$table} 
            WHERE is_active = 1 
            AND end_date IS NOT NULL 
            AND end_date < UTC_TIMESTAMP()"
        );
        
        if (!empty($expired_rules)) {
            // Deactivate expired rules
            $placeholders = implode(',', array_fill(0, count($expired_rules), '%d'));
            $wpdb->query($wpdb->prepare(
                "UPDATE {$table} SET is_active = 0 WHERE rule_id IN ($placeholders)",
                $expired_rules
            ));
            
            $results['deactivated_count'] = count($expired_rules);
            
            // Clear WooCommerce caches when rules are deactivated
            $this->clear_woocommerce_price_caches();
            $results['cache_cleared'] = true;
            
            // Log if in debug mode
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $this->utils->log_error(
                    'Auto-deactivated expired pricing rules',
                    array('rule_ids' => $expired_rules),
                    'debug'
                );
            }
        }
        
        // Also check for rules that should now be active (start_date has arrived)
        // These are rules that have is_active = 1 but were waiting for their start_date
        // Note: We don't auto-activate rules that were manually deactivated
        // This section handles cache clearing for rules that just became active
        $newly_active_rules = $wpdb->get_col(
            "SELECT rule_id FROM {$table} 
            WHERE is_active = 1 
            AND start_date IS NOT NULL 
            AND start_date <= UTC_TIMESTAMP()
            AND start_date > DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 MINUTE)
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())"
        );
        
        if (!empty($newly_active_rules)) {
            $results['activated_count'] = count($newly_active_rules);
            
            // Clear caches for newly active rules too
            if (!$results['cache_cleared']) {
                $this->clear_woocommerce_price_caches();
                $results['cache_cleared'] = true;
            }
        }
        
        return $results;
    }

    /**
     * Clear WooCommerce price-related caches
     */
    private function clear_woocommerce_price_caches() {
        // Clear WooCommerce transients related to product prices
        global $wpdb;
        
        // Delete product price transients
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_wc_var_prices_%'
            OR option_name LIKE '_transient_timeout_wc_var_prices_%'
            OR option_name LIKE '_transient_wc_product_children_%'
            OR option_name LIKE '_transient_timeout_wc_product_children_%'"
        );
        
        // Clear WooCommerce product cache
        if (function_exists('wc_delete_product_transients')) {
            // Get all products with pricing rules
            $product_ids = $wpdb->get_col(
                "SELECT DISTINCT product_id FROM {$wpdb->prefix}rule_products"
            );
            
            foreach ($product_ids as $product_id) {
                wc_delete_product_transients($product_id);
            }
        }
        
        // Clear object cache if available
        if (function_exists('wp_cache_flush_group')) {
            wp_cache_flush_group('woocommerce');
        } elseif (function_exists('wp_cache_flush')) {
            // Fallback: flush all cache (less efficient but ensures price updates)
            wp_cache_flush();
        }
        
        // Trigger WooCommerce action for any additional cache clearing hooks
        do_action('woocommerce_delete_product_transients');
    }

    /**
     * Run cleanup tasks
     *
     * @return bool
     */
    public function run_cleanup_tasks() {
        try {
            $start_time = microtime(true);
            $results = array();

            // Cleanup orphaned data
            $results['orphaned_data'] = $this->db->cleanup_orphaned_data();

            // Cleanup old logs
            $results['old_logs'] = $this->db->cleanup_old_logs();

            // Cleanup orphaned group assignments
            $results['group_assignments'] = $this->db->cleanup_orphaned_group_assignments();

            // Calculate execution time
            $execution_time = microtime(true) - $start_time;

            // Log cleanup results only if there were issues or in debug mode
            if (in_array(false, $results, true) || (defined('WP_DEBUG') && WP_DEBUG)) {
                $this->utils->log_error(
                    'Cleanup tasks completed',
                    array(
                        'results' => $results,
                        'execution_time' => round($execution_time, 2) . 's'
                    ),
                    in_array(false, $results, true) ? 'error' : 'debug'
                );
            }

            return !in_array(false, $results, true);

        } catch (Exception $e) {
            $this->utils->log_error(
                'Cleanup tasks failed: ' . $e->getMessage(),
                array('trace' => $e->getTraceAsString()),
                'critical'
            );
            return false;
        }
    }

    /**
     * Get cleanup status
     *
     * @return array
     */
    public function get_cleanup_status() {
        $next_run = wp_next_scheduled('wccg_cleanup_cron');
        $last_run = get_option('wccg_last_cleanup', 0);

        return array(
            'is_scheduled' => (bool) $next_run,
            'next_run' => $next_run ? get_date_from_gmt(date('Y-m-d H:i:s', $next_run), 'Y-m-d H:i:s') : null,
            'last_run' => $last_run ? get_date_from_gmt(date('Y-m-d H:i:s', $last_run), 'Y-m-d H:i:s') : null,
            'log_count' => $this->db->get_log_count()
        );
    }

    /**
     * Detect pricing rule conflicts
     *
     * @return array
     */
    public function detect_pricing_rule_conflicts() {
        global $wpdb;
        $conflicts = array();

        // This query only looks for duplicate product-group combinations
        $query = "
        SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
        FROM {$wpdb->prefix}rule_products p
        JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
        JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
        GROUP BY p.product_id, pr.group_id
        HAVING rule_count > 1
        ";

        $duplicate_rules = $wpdb->get_results($query);

        foreach ($duplicate_rules as $rule) {
            $product = wc_get_product($rule->product_id);
            if ($product) {
                $conflicts[] = sprintf(
                    'Product "%s" has multiple rules for group: %s',
                    $product->get_name(),
                    $rule->group_name
                );
            }
        }

        // Do the same for categories
        $query = "
        SELECT rc.category_id, pr.group_id, COUNT(*) as rule_count, g.group_name
        FROM {$wpdb->prefix}rule_categories rc
        JOIN {$wpdb->prefix}pricing_rules pr ON rc.rule_id = pr.rule_id
        JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
        GROUP BY rc.category_id, pr.group_id
        HAVING rule_count > 1
        ";

        $duplicate_category_rules = $wpdb->get_results($query);

        foreach ($duplicate_category_rules as $rule) {
            $category = get_term($rule->category_id, 'product_cat');
            if ($category && !is_wp_error($category)) {
                $conflicts[] = sprintf(
                    'Category "%s" has multiple rules for group: %s',
                    $category->name,
                    $rule->group_name
                );
            }
        }

        return $conflicts;
    }

}
