<?php
/**
 * Utility functions
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Utilities {
    /**
     * Single instance of the class
     *
     * @var WCCG_Utilities
     */
    private static $instance = null;

    /**
     * Get class instance
     *
     * @return WCCG_Utilities
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Verify nonce
     *
     * @param string $nonce_name
     * @return bool
     */
    public function verify_nonce($nonce_name) {
        if (!isset($_REQUEST['_wpnonce'])) {
            wp_die('Security check failed - nonce not set');
        }

        if (!wp_verify_nonce($_REQUEST['_wpnonce'], $nonce_name)) {
            wp_die('Security check failed - invalid nonce');
        }

        return true;
    }

    /**
     * Escape output
     *
     * @param mixed $data
     * @param string $type
     * @return string
     */
    public function escape_output($data, $type = 'text') {
        switch ($type) {
            case 'html':
            return wp_kses_post($data);
            case 'url':
            return esc_url($data);
            case 'attr':
            return esc_attr($data);
            case 'textarea':
            return esc_textarea($data);
            default:
            return esc_html($data);
        }
    }

    /**
     * Verify admin access
     */
    public function verify_admin_access() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'wccg'));
        }
    }

    /**
     * Validate pricing input
     *
     * @param string $discount_type
     * @param float $discount_value
     * @return array
     */
    public function validate_pricing_input($discount_type, $discount_value) {
        if (!in_array($discount_type, array('percentage', 'fixed'))) {
            return array(
                'valid' => false,
                'message' => 'Invalid discount type.'
            );
        }

        if (!is_numeric($discount_value)) {
            return array(
                'valid' => false,
                'message' => 'Discount value must be a number.'
            );
        }

        if ($discount_type === 'percentage') {
            if ($discount_value < 0 || $discount_value > 100) {
                return array(
                    'valid' => false,
                    'message' => 'Percentage discount must be between 0 and 100.'
                );
            }
        }

        if ($discount_type === 'fixed') {
            if ($discount_value < 0) {
                return array(
                    'valid' => false,
                    'message' => 'Fixed discount cannot be negative.'
                );
            }

            $max_fixed_discount = 10000;
            if ($discount_value > $max_fixed_discount) {
                return array(
                    'valid' => false,
                    'message' => sprintf('Fixed discount cannot exceed %s.', 
                        wc_price($max_fixed_discount))
                );
            }
        }

        return array('valid' => true);
    }

    /**
     * Sanitize input data
     *
     * @param mixed $data
     * @param string $type
     * @param array $args
     * @return mixed
     */
    public function sanitize_input($data, $type = 'text', $args = array()) {
        if (is_null($data)) {
            return '';
        }

        switch ($type) {
            case 'int':
            return intval($data);

            case 'float':
            return (float) filter_var($data, 
                FILTER_SANITIZE_NUMBER_FLOAT, 
                FILTER_FLAG_ALLOW_FRACTION);

            case 'price':
            $price = (float) filter_var($data, 
                FILTER_SANITIZE_NUMBER_FLOAT, 
                FILTER_FLAG_ALLOW_FRACTION);
            return round($price, wc_get_price_decimals());

            case 'email':
            return sanitize_email($data);

            case 'url':
            return esc_url_raw($data);

            case 'textarea':
            return sanitize_textarea_field($data);

            case 'array':
            if (!is_array($data)) {
                return array();
            }
            return array_map(array($this, 'sanitize_input'), $data);

            case 'group_id':
            $group_id = intval($data);
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}customer_groups 
                WHERE group_id = %d",
                $group_id
            ));
            return $exists ? $group_id : 0;

            case 'discount_type':
            $allowed_types = array('percentage', 'fixed');
            $sanitized = sanitize_text_field($data);
            return in_array($sanitized, $allowed_types) ? $sanitized : '';

            default:
            return sanitize_text_field($data);
        }
    }

    /**
     * Check rate limit
     *
     * @param int $user_id
     * @param string $action
     * @return bool
     */
    public function check_rate_limit($user_id, $action = 'price_calc') {
        if (is_super_admin()) {
            return true;
        }

        $transient_key = 'wccg_rate_limit_' . $action . '_' . $user_id;
        $limit_data = get_transient($transient_key);

        switch ($action) {
            case 'price_calc':
            $max_requests = 100;
            $time_window = MINUTE_IN_SECONDS;
            break;
            case 'group_change':
            $max_requests = 10;
            $time_window = MINUTE_IN_SECONDS * 5;
            break;
            default:
            $max_requests = 50;
            $time_window = MINUTE_IN_SECONDS;
        }

        if (false === $limit_data) {
            $limit_data = array(
                'count' => 1,
                'first_request' => time()
            );
            set_transient($transient_key, $limit_data, $time_window);
            return true;
        }

        if ((time() - $limit_data['first_request']) > $time_window) {
            $limit_data = array(
                'count' => 1,
                'first_request' => time()
            );
            set_transient($transient_key, $limit_data, $time_window);
            return true;
        }

        if ($limit_data['count'] >= $max_requests) {
            $this->log_error(
                'Rate limit exceeded',
                array(
                    'user_id' => $user_id,
                    'action' => $action,
                    'limit_data' => $limit_data
                )
            );
            return false;
        }

        $limit_data['count']++;
        set_transient($transient_key, $limit_data, $time_window);
        return true;
    }

/**
 * Log error message
 *
 * @param string $message Error message
 * @param array $data Additional data
 * @param string $severity Error severity (debug, info, warning, error, critical)
 * @return bool
 */
public function log_error($message, $data = array(), $severity = 'error') {
    // Define severity levels and their threshold
    $severity_levels = array(
        'debug' => 0,
        'info' => 1,
        'warning' => 2,
        'error' => 3,
        'critical' => 4
    );

    // Only proceed if it's an error or critical severity
    if (!isset($severity_levels[$severity]) || $severity_levels[$severity] < $severity_levels['error']) {
        return false;
    }

    $current_user_id = get_current_user_id();
    $timestamp = current_time('mysql');

    // Only log to debug.log for critical errors or when WP_DEBUG_LOG is true
    if ($severity === 'critical' || WP_DEBUG_LOG) {
        $log_entry = sprintf(
            "[%s] [%s] [User: %d] %s",
            $timestamp,
            strtoupper($severity),
            $current_user_id,
            $message
        );

        if (!empty($data)) {
            // Ensure data is JSON-encodable
            $json_data = wp_json_encode($data);
            if ($json_data !== false) {
                $log_entry .= " | Data: " . $json_data;
            }
        }

        error_log($log_entry);
    }

    // Store in database only for error and critical severities
    try {
        global $wpdb;

        // Check if table exists
        $table_name = $wpdb->prefix . 'wccg_error_log';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            // Ensure data is JSON-encodable
            $json_data = !empty($data) ? wp_json_encode($data) : null;
            if (json_last_error() !== JSON_ERROR_NONE) {
                $json_data = wp_json_encode(['error' => 'Data not JSON encodable']);
            }

            $result = $wpdb->insert(
                $table_name,
                array(
                    'timestamp' => $timestamp,
                    'user_id' => $current_user_id,
                    'message' => $message,
                    'data' => $json_data,
                    'severity' => $severity
                ),
                array('%s', '%d', '%s', '%s', '%s')
            );

            return $result !== false;
        }

        return false;

    } catch (Exception $e) {
        error_log('WCCG Error Logger Failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Clean up old logs
 *
 * @return bool
 */
public function cleanup_logs() {
    global $wpdb;

    try {
        $table_name = $wpdb->prefix . 'wccg_error_log';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            // Keep critical errors for 90 days, others for 30 days
            $wpdb->query("DELETE FROM $table_name 
                WHERE (severity != 'critical' AND timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY))
                OR (severity = 'critical' AND timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY))");

            return true;
        }
        return false;
    } catch (Exception $e) {
        error_log('WCCG Log Cleanup Failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get current log count
 *
 * @return int
 */
public function get_log_count() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'wccg_error_log';

    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

    if ($table_exists) {
        return (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
    }

    return 0;
}

}