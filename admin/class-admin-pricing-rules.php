<?php
/**
 * Pricing Rules admin page handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Admin_Pricing_Rules {
    /**
     * Single instance of the class
     *
     * @var WCCG_Admin_Pricing_Rules
     */
    private static $instance = null;

    /**
     * Database instance
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
     * @return WCCG_Admin_Pricing_Rules
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
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_wccg_toggle_pricing_rule', array($this, 'ajax_toggle_pricing_rule'));
        add_action('wp_ajax_wccg_delete_all_pricing_rules', array($this, 'ajax_delete_all_pricing_rules'));
        add_action('wp_ajax_wccg_bulk_toggle_pricing_rules', array($this, 'ajax_bulk_toggle_pricing_rules'));
        add_action('wp_ajax_wccg_reorder_pricing_rules', array($this, 'ajax_reorder_pricing_rules'));
    }

    /**
     * Enqueue scripts and styles for the pricing rules page
     *
     * @param string $hook Current admin page
     */
    public function enqueue_scripts($hook) {
        if ('customer-groups_page_wccg_pricing_rules' !== $hook) {
            return;
        }

        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('dashicons');
        
        // Enqueue jQuery UI Sortable
        wp_enqueue_script('jquery-ui-sortable');

        // Enqueue pricing rules JavaScript
        wp_enqueue_script(
            'wccg-pricing-rules',
            WCCG_URL . 'assets/js/pricing-rules.js',
            array('jquery', 'jquery-ui-sortable'),
            WCCG_VERSION,
            true
        );

        wp_add_inline_style('woocommerce_admin_styles', '
            .woocommerce select:not(.select2-hidden-accessible) {
                display: block !important;
                visibility: visible !important;
            }
            .select2-container {
                display: none !important;
            }
            ');

        // Localize script for AJAX
        wp_localize_script('wccg-pricing-rules', 'wccg_pricing_rules', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wccg_pricing_rules_ajax')
        ));
    }

    /**
     * Display the pricing rules page
     */
    public function display_page() {
        $this->utils->verify_admin_access();

        // Handle form submissions
        $this->handle_form_submission();

        // Get data for display
        $groups = $this->get_groups();
        $pricing_rules = $this->get_pricing_rules();

        // Display the page
        $this->render_page($groups, $pricing_rules);
    }

    /**
     * Display pricing rules info box
     */
    private function display_info_box() {
        $conflicts = $this->get_rule_conflicts();
        ?>
        <div class="wccg-info-box">
            <h3><?php esc_html_e('Pricing Rules Hierarchy', 'wccg'); ?></h3>

            <div class="wccg-info-grid">
                <div class="wccg-info-column">
                    <h4><?php esc_html_e('Rule Precedence', 'wccg'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Product-specific rules override category rules', 'wccg'); ?></li>
                        <li><?php esc_html_e('Fixed discounts take precedence over percentage discounts', 'wccg'); ?></li>
                        <li><?php esc_html_e('Higher discount values take precedence over lower ones', 'wccg'); ?></li>
                        <li><?php esc_html_e('For equal discounts, the most recently created rule wins', 'wccg'); ?></li>
                    </ul>
                </div>

                <div class="wccg-info-column">
                    <h4><?php esc_html_e('Category Rules', 'wccg'); ?></h4>
                    <ul>
                        <li><?php esc_html_e('Apply to all products in the category', 'wccg'); ?></li>
                        <li><?php esc_html_e('Include parent category rules', 'wccg'); ?></li>
                        <li><?php esc_html_e('Best discount automatically applies', 'wccg'); ?></li>
                    </ul>
                </div>

                <?php if (!empty($conflicts)): ?>
                    <div class="wccg-info-column wccg-conflicts">
                        <h4><?php esc_html_e('Current Conflicts', 'wccg'); ?></h4>
                        <ul>
                            <?php foreach ($conflicts as $conflict): ?>
                                <li><?php echo esc_html($conflict); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
            </div>

            <?php if (!empty($conflicts)): ?>
                <p class="wccg-conflict-notice">
                    <span class="dashicons dashicons-warning"></span>
                    <?php esc_html_e('Conflicts don\'t break anything - the hierarchy rules above determine which discount applies.', 'wccg'); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        if (!isset($_POST['wccg_pricing_rules_nonce']) || 
            !wp_verify_nonce($_POST['wccg_pricing_rules_nonce'], 'wccg_pricing_rules_action')) {
            wp_die('Security check failed');
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'save_rule':
            $this->handle_save_rule();
            break;
            case 'delete_rule':
            $this->handle_delete_rule();
            break;
        }
    }
}

    /**
     * Handle saving pricing rule
     */
    private function handle_save_rule() {
        // Validate inputs
        $group_id = $this->utils->sanitize_input($_POST['group_id'], 'group_id');
        $discount_type = $this->utils->sanitize_input($_POST['discount_type'], 'discount_type');
        $discount_value = $this->utils->sanitize_input($_POST['discount_value'], 'price');

        if ($group_id === 0) {
            $this->add_admin_notice('error', 'Invalid customer group selected.');
            return;
        }

        // Validate discount type and value
        $validation_result = $this->utils->validate_pricing_input($discount_type, $discount_value);
        if (!$validation_result['valid']) {
            $this->add_admin_notice('error', $validation_result['message']);
            return;
        }

        // Sanitize product and category IDs
        $product_ids = isset($_POST['product_ids']) 
        ? array_map(array($this->utils, 'sanitize_input'), (array)$_POST['product_ids'], array_fill(0, count($_POST['product_ids']), 'int'))
        : array();

        $category_ids = isset($_POST['category_ids'])
        ? array_map(array($this->utils, 'sanitize_input'), (array)$_POST['category_ids'], array_fill(0, count($_POST['category_ids']), 'int'))
        : array();

        // Process database operations
        $result = $this->save_pricing_rule($group_id, $discount_type, $discount_value, $product_ids, $category_ids);

        if ($result) {
            $this->add_admin_notice('success', 'Pricing rule saved successfully.');
        } else {
            $this->add_admin_notice('error', 'Error occurred while saving pricing rule.');
        }
    }

    /**
     * Handle deleting a pricing rule
     */
    private function handle_delete_rule() {
        $rule_id = $this->utils->sanitize_input($_POST['rule_id'], 'int');
        if (!$rule_id) {
            $this->add_admin_notice('error', 'Invalid rule ID.');
            return;
        }

        $result = $this->db->transaction(function() use ($rule_id) {
            global $wpdb;

            // Delete rule associations first
            $wpdb->delete($wpdb->prefix . 'rule_products', array('rule_id' => $rule_id), array('%d'));
            $wpdb->delete($wpdb->prefix . 'rule_categories', array('rule_id' => $rule_id), array('%d'));

            // Delete the rule itself
            return $wpdb->delete($wpdb->prefix . 'pricing_rules', array('rule_id' => $rule_id), array('%d'));
        });

        if ($result !== false) {
            $this->add_admin_notice('success', 'Pricing rule deleted successfully.');
        } else {
            $this->add_admin_notice('error', 'Error deleting pricing rule.');
        }
    }

    /**
     * AJAX handler for toggling pricing rule status
     */
    public function ajax_toggle_pricing_rule() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $rule_id = isset($_POST['rule_id']) ? intval($_POST['rule_id']) : 0;
        if (!$rule_id) {
            wp_send_json_error(array('message' => 'Invalid rule ID'));
        }

        // Get the desired new status from the request
        $new_status = isset($_POST['new_status']) ? intval($_POST['new_status']) : null;
        
        if ($new_status === null || ($new_status !== 0 && $new_status !== 1)) {
            wp_send_json_error(array('message' => 'Invalid status value'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pricing_rules';

        // Verify rule exists
        $rule_exists = $wpdb->get_var($wpdb->prepare(
            "SELECT rule_id FROM {$table} WHERE rule_id = %d",
            $rule_id
        ));

        if (!$rule_exists) {
            wp_send_json_error(array('message' => 'Rule not found'));
        }

        // Update to the requested status
        $result = $wpdb->update(
            $table,
            array('is_active' => $new_status),
            array('rule_id' => $rule_id),
            array('%d'),
            array('%d')
        );

        // Check for database error
        if ($result === false) {
            wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
            return;
        }

        // Verify the update by reading back the value
        $current_status = $wpdb->get_var($wpdb->prepare(
            "SELECT is_active FROM {$table} WHERE rule_id = %d",
            $rule_id
        ));

        // Confirm the status matches what was requested
        if ((int)$current_status === $new_status) {
            wp_send_json_success(array(
                'message' => 'Rule status updated',
                'is_active' => (int)$current_status
            ));
        } else {
            wp_send_json_error(array(
                'message' => 'Status update verification failed. Expected: ' . $new_status . ', Got: ' . $current_status
            ));
        }
    }

    /**
     * AJAX handler for deleting all pricing rules
     */
    public function ajax_delete_all_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $result = $this->db->transaction(function() {
            global $wpdb;

            // Get all rule IDs
            $rule_ids = $wpdb->get_col("SELECT rule_id FROM {$wpdb->prefix}pricing_rules");

            if (empty($rule_ids)) {
                return 0;
            }

            // Delete all rule associations
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rule_products");
            $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}rule_categories");

            // Delete all rules
            return $wpdb->query("TRUNCATE TABLE {$wpdb->prefix}pricing_rules");
        });

        if ($result !== false) {
            wp_send_json_success(array('message' => 'All pricing rules deleted successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to delete pricing rules'));
        }
    }

    /**
     * AJAX handler for bulk toggling pricing rules
     */
    public function ajax_bulk_toggle_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $status = isset($_POST['status']) ? intval($_POST['status']) : 1;
        
        global $wpdb;
        $table = $wpdb->prefix . 'pricing_rules';

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET is_active = %d",
            $status
        ));

        if ($result !== false) {
            $action = $status ? 'enabled' : 'disabled';
            wp_send_json_success(array(
                'message' => sprintf('All pricing rules %s successfully', $action),
                'is_active' => $status
            ));
        } else {
            wp_send_json_error(array('message' => 'Failed to update pricing rules'));
        }
    }

    /**
     * AJAX handler for reordering pricing rules
     */
    public function ajax_reorder_pricing_rules() {
        check_ajax_referer('wccg_pricing_rules_ajax', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }

        $order = isset($_POST['order']) ? array_map('intval', $_POST['order']) : array();
        
        if (empty($order)) {
            wp_send_json_error(array('message' => 'No order data provided'));
        }

        global $wpdb;
        $table = $wpdb->prefix . 'pricing_rules';

        // Update sort_order for each rule
        $success = true;
        $sort_order = 1;
        
        foreach ($order as $rule_id) {
            $result = $wpdb->update(
                $table,
                array('sort_order' => $sort_order),
                array('rule_id' => $rule_id),
                array('%d'),
                array('%d')
            );
            
            if ($result === false) {
                $success = false;
                break;
            }
            
            $sort_order++;
        }

        if ($success) {
            wp_send_json_success(array('message' => 'Rule order updated successfully'));
        } else {
            wp_send_json_error(array('message' => 'Failed to update rule order'));
        }
    }

    /**
     * Get current rule conflicts
     *
     * @return array
     */
    private function get_rule_conflicts() {
        global $wpdb;
        $conflicts = array();

        // Product rule conflicts
        $product_conflicts = $wpdb->get_results("
            SELECT p.product_id, pr.group_id, COUNT(*) as rule_count, g.group_name
            FROM {$wpdb->prefix}rule_products p
            JOIN {$wpdb->prefix}pricing_rules pr ON p.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            GROUP BY p.product_id, pr.group_id
            HAVING rule_count > 1
            ");

        foreach ($product_conflicts as $conflict) {
            $product = wc_get_product($conflict->product_id);
            if ($product) {
                $conflicts[] = sprintf(
                    __('Product "%s" has multiple rules for group "%s"', 'wccg'),
                    $product->get_name(),
                    $conflict->group_name
                );
            }
        }

        // Category rule conflicts
        $category_conflicts = $wpdb->get_results("
            SELECT rc.category_id, pr.group_id, COUNT(DISTINCT pr.rule_id) as rule_count, g.group_name, t.name as category_name
            FROM {$wpdb->prefix}rule_categories rc
            JOIN {$wpdb->prefix}pricing_rules pr ON rc.rule_id = pr.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            JOIN {$wpdb->prefix}terms t ON rc.category_id = t.term_id
            GROUP BY rc.category_id, pr.group_id
            HAVING rule_count > 1
            ");

        foreach ($category_conflicts as $conflict) {
            $conflicts[] = sprintf(
                __('Category "%s" has multiple rules for group "%s"', 'wccg'),
                $conflict->category_name,
                $conflict->group_name
            );
        }

        return $conflicts;
    }

    /**
     * Save pricing rule
     *
     * @param int $group_id
     * @param string $discount_type
     * @param float $discount_value
     * @param array $product_ids
     * @param array $category_ids
     * @return bool
     */
    private function save_pricing_rule($group_id, $discount_type, $discount_value, $product_ids, $category_ids) {
    error_log('WCCG Debug: Attempting to save pricing rule');
    error_log('WCCG Debug: Input data: ' . print_r([
        'group_id' => $group_id,
        'discount_type' => $discount_type,
        'discount_value' => $discount_value,
        'product_ids' => $product_ids,
        'category_ids' => $category_ids
    ], true));

    return $this->db->transaction(function() use ($group_id, $discount_type, $discount_value, $product_ids, $category_ids) {
        global $wpdb;

        // Check if sort_order column exists
        $column_exists = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_SCHEMA = %s 
            AND TABLE_NAME = %s 
            AND COLUMN_NAME = 'sort_order'",
            DB_NAME,
            $wpdb->prefix . 'pricing_rules'
        ));

        $insert_data = array(
            'group_id' => $group_id,
            'discount_type' => $discount_type,
            'discount_value' => $discount_value,
            'is_active' => 1
        );
        
        $insert_format = array('%d', '%s', '%f', '%d');

        // Add sort_order if column exists
        if (!empty($column_exists)) {
            $max_sort_order = $wpdb->get_var("SELECT MAX(sort_order) FROM {$wpdb->prefix}pricing_rules");
            $new_sort_order = $max_sort_order ? $max_sort_order + 1 : 1;
            $insert_data['sort_order'] = $new_sort_order;
            $insert_format[] = '%d';
        }

        // Insert new rule
        $result = $wpdb->insert(
            $wpdb->prefix . 'pricing_rules',
            $insert_data,
            $insert_format
        );

        error_log('WCCG Debug: Rule insert result: ' . print_r($result, true));
        error_log('WCCG Debug: Last SQL Query: ' . $wpdb->last_query);
        error_log('WCCG Debug: SQL Error: ' . $wpdb->last_error);

        if ($result === false) {
            throw new Exception('Failed to insert pricing rule');
        }

        $rule_id = $wpdb->insert_id;
        error_log('WCCG Debug: New rule ID: ' . $rule_id);

        // Insert product associations
        foreach ($product_ids as $product_id) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'rule_products',
                array(
                    'rule_id' => $rule_id,
                    'product_id' => $product_id,
                ),
                array('%d', '%d')
            );

            if ($result === false) {
                error_log('WCCG Debug: Failed to insert product association: ' . $wpdb->last_error);
                throw new Exception('Failed to insert product association');
            }
        }

        // Insert category associations
        foreach ($category_ids as $category_id) {
            $result = $wpdb->insert(
                $wpdb->prefix . 'rule_categories',
                array(
                    'rule_id' => $rule_id,
                    'category_id' => $category_id,
                ),
                array('%d', '%d')
            );

            if ($result === false) {
                error_log('WCCG Debug: Failed to insert category association: ' . $wpdb->last_error);
                throw new Exception('Failed to insert category association');
            }
        }

        return true;
    });
}

    /**
     * Get all customer groups
     *
     * @return array
     */
    private function get_groups() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}customer_groups ORDER BY group_name ASC"
        );
    }

/**
 * Get all pricing rules
 *
 * @return array
 */
private function get_pricing_rules() {
    global $wpdb;

    // Debug output
    error_log('WCCG Debug: Fetching pricing rules');

    // Check if sort_order column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s 
        AND TABLE_NAME = %s 
        AND COLUMN_NAME = 'sort_order'",
        DB_NAME,
        $wpdb->prefix . 'pricing_rules'
    ));

    // Use sort_order if it exists, otherwise fallback to rule_id
    $order_by = !empty($column_exists) ? 'pr.sort_order ASC, pr.rule_id ASC' : 'pr.rule_id ASC';

    // Query with sort_order ordering (ascending for drag-drop)
    $rules = $wpdb->get_results(
        "SELECT pr.*, 
            GROUP_CONCAT(DISTINCT rp.product_id) as product_ids,
            GROUP_CONCAT(DISTINCT rc.category_id) as category_ids
        FROM {$wpdb->prefix}pricing_rules pr
        LEFT JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
        LEFT JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
        GROUP BY pr.rule_id
        ORDER BY {$order_by}",
        OBJECT_K
    );

    // Debug output
    error_log('WCCG Debug: SQL Query: ' . $wpdb->last_query);
    error_log('WCCG Debug: Found rules: ' . print_r($rules, true));
    error_log('WCCG Debug: SQL Error: ' . $wpdb->last_error);

    return $rules;
}

    /**
     * Add admin notice
     *
     * @param string $type
     * @param string $message
     */
    private function add_admin_notice($type, $message) {
        add_settings_error(
            'wccg_pricing_rules',
            'wccg_notice',
            $message,
            $type
        );
    }

    /**
     * Get group name by ID
     *
     * @param int $group_id
     * @return string
     */
    private function get_group_name($group_id) {
        global $wpdb;
        $name = $wpdb->get_var($wpdb->prepare(
            "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
            $group_id
        ));
        return $name ? $name : __('Unknown Group', 'wccg');
    }

    /**
     * Render the page
     *
     * @param array $groups
     * @param array $pricing_rules
     */
    private function render_page($groups, $pricing_rules) {
        global $wpdb;
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Pricing Rules', 'wccg'); ?></h1>

            <?php 
            // Display the info box
            $this->display_info_box();

            // Display any admin notices
            settings_errors('wccg_pricing_rules'); 
            ?>

            <form method="post">
                <?php wp_nonce_field('wccg_pricing_rules_action', 'wccg_pricing_rules_nonce'); ?>
                <input type="hidden" name="action" value="save_rule">

                <!-- Rest of your existing form HTML remains the same -->
                <?php include(WCCG_PATH . 'admin/views/html-pricing-rules-form.php'); ?>
            </form>

            <h2><?php esc_html_e('Existing Pricing Rules', 'wccg'); ?></h2>
            <?php include(WCCG_PATH . 'admin/views/html-pricing-rules-list.php'); ?>
        </div>
        <?php
    }
}