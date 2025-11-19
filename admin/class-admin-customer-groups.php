<?php
/**
 * Customer Groups admin page handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Admin_Customer_Groups {
    /**
     * Single instance of the class
     *
     * @var WCCG_Admin_Customer_Groups
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
     * @return WCCG_Admin_Customer_Groups
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
    }

    /**
     * Display the customer groups page
     */
    public function display_page() {
        $this->utils->verify_admin_access();

        // Handle form submissions
        $this->handle_form_submission();

        // Get all groups
        $groups = $this->get_groups();

        // Display the page
        $this->render_page($groups);
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Verify nonce
        if (!isset($_POST['wccg_customer_groups_nonce']) || 
            !wp_verify_nonce($_POST['wccg_customer_groups_nonce'], 'wccg_customer_groups_action')) {
            wp_die('Security check failed');
    }

    $action = $this->utils->sanitize_input($_POST['action']);

    switch ($action) {
        case 'add_group':
        $this->handle_add_group();
        break;
        case 'delete_group':
        $this->handle_delete_group();
        break;
        case 'set_default_group':
        $this->handle_set_default_group();
        break;
    }
}

    /**
     * Handle adding a new group
     */
    private function handle_add_group() {
        $group_name = $this->utils->sanitize_input($_POST['group_name']);
        $group_description = $this->utils->sanitize_input($_POST['group_description'], 'textarea');

        if (empty($group_name)) {
            $this->add_admin_notice('error', 'Group name is required.');
            return;
        }

        $result = $this->db->transaction(function() use ($group_name, $group_description) {
            global $wpdb;
            return $wpdb->insert(
                $wpdb->prefix . 'customer_groups',
                array(
                    'group_name' => $group_name,
                    'group_description' => $group_description,
                ),
                array('%s', '%s')
            );
        });

        if ($result) {
            $this->add_admin_notice('success', 'Customer group added successfully.');
        } else {
            $this->add_admin_notice('error', 'Error adding customer group.');
        }
    }

    /**
     * Handle deleting a group
     */
    private function handle_delete_group() {
        $group_id = $this->utils->sanitize_input($_POST['group_id'], 'group_id');

        if (!$group_id) {
            $this->add_admin_notice('error', 'Invalid group ID.');
            return;
        }

        // Check if this is the default group
        $default_group_id = get_option('wccg_default_group_id', 0);
        if ($default_group_id == $group_id) {
            $this->add_admin_notice('error', 'Cannot delete the default group for ungrouped customers. Please set a different default group first.');
            return;
        }

        $result = $this->db->transaction(function() use ($group_id) {
            global $wpdb;
            
            // Delete pricing rules first
            $this->db->delete_group_pricing_rules($group_id);

            // Delete user assignments
            $wpdb->delete(
                $wpdb->prefix . 'user_groups',
                array('group_id' => $group_id),
                array('%d')
            );

            // Delete the group
            return $wpdb->delete(
                $wpdb->prefix . 'customer_groups',
                array('group_id' => $group_id),
                array('%d')
            );
        });

        if ($result) {
            $this->add_admin_notice('success', 'Customer group and associated data deleted successfully.');
        } else {
            $this->add_admin_notice('error', 'Error deleting customer group.');
        }
    }

    /**
     * Handle setting default group for ungrouped customers
     */
    private function handle_set_default_group() {
        $group_id = $this->utils->sanitize_input($_POST['default_group_id'], 'int');
        $custom_title = isset($_POST['custom_title']) ? $this->utils->sanitize_input($_POST['custom_title']) : '';

        // Allow setting to 0 (no default group)
        if ($group_id < 0) {
            $this->add_admin_notice('error', 'Invalid group ID.');
            return;
        }

        // If setting a specific group, verify it exists
        if ($group_id > 0) {
            global $wpdb;
            $group_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT group_id FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                $group_id
            ));

            if (!$group_exists) {
                $this->add_admin_notice('error', 'Selected group does not exist.');
                return;
            }
        }

        // Update the options
        update_option('wccg_default_group_id', $group_id);
        update_option('wccg_default_group_custom_title', $custom_title);
        
        if ($group_id > 0) {
            $this->add_admin_notice('success', 'Default group for ungrouped customers updated successfully.');
        } else {
            $this->add_admin_notice('success', 'Default group disabled. Ungrouped customers will see regular prices.');
        }
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
     * Add admin notice
     *
     * @param string $type
     * @param string $message
     */
    private function add_admin_notice($type, $message) {
        add_settings_error(
            'wccg_customer_groups',
            'wccg_notice',
            $message,
            $type
        );
    }

    /**
     * Render the page
     *
     * @param array $groups
     */
    private function render_page($groups) {
        $default_group_id = get_option('wccg_default_group_id', 0);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Customer Groups', 'wccg'); ?></h1>

            <?php settings_errors('wccg_customer_groups'); ?>

            <!-- Default Group for Ungrouped Customers -->
            <div class="wccg-default-group-section" style="background: #f0f0f1; padding: 15px; margin: 20px 0; border-left: 4px solid #2271b1;">
                <h2 style="margin-top: 0;"><?php esc_html_e('Default Group for Ungrouped Customers', 'wccg'); ?></h2>
                <p><?php esc_html_e('Select a group to apply pricing rules to customers who are not assigned to any group. This is useful for retail customers or promotional pricing.', 'wccg'); ?></p>
                
                <form method="post" style="margin-top: 15px;">
                    <?php wp_nonce_field('wccg_customer_groups_action', 'wccg_customer_groups_nonce'); ?>
                    <input type="hidden" name="action" value="set_default_group">
                    
                    <table class="form-table" style="margin-top: 0;">
                        <tr>
                            <th scope="row" style="padding-top: 0;">
                                <label for="default_group_id"><?php esc_html_e('Default Group', 'wccg'); ?></label>
                            </th>
                            <td style="padding-top: 0;">
                                <select name="default_group_id" id="default_group_id" class="regular-text">
                                    <option value="0" <?php selected($default_group_id, 0); ?>>
                                        <?php esc_html_e('None (Ungrouped customers see regular prices)', 'wccg'); ?>
                                    </option>
                                    <?php foreach ($groups as $group): ?>
                                        <option value="<?php echo esc_attr($group->group_id); ?>" <?php selected($default_group_id, $group->group_id); ?>>
                                            <?php echo esc_html($group->group_name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php if ($default_group_id > 0): ?>
                                    <p class="description">
                                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                                        <?php 
                                        $default_group_name = '';
                                        foreach ($groups as $group) {
                                            if ($group->group_id == $default_group_id) {
                                                $default_group_name = $group->group_name;
                                                break;
                                            }
                                        }
                                        printf(
                                            esc_html__('Currently set to: %s', 'wccg'),
                                            '<strong>' . esc_html($default_group_name) . '</strong>'
                                        );
                                        ?>
                                    </p>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="custom_title"><?php esc_html_e('Custom Title', 'wccg'); ?></label>
                            </th>
                            <td>
                                <input type="text" 
                                    name="custom_title" 
                                    id="custom_title" 
                                    class="regular-text" 
                                    value="<?php echo esc_attr(get_option('wccg_default_group_custom_title', '')); ?>"
                                    placeholder="<?php esc_attr_e('e.g., Thanksgiving, Holiday Sale, VIP', 'wccg'); ?>">
                                <p class="description">
                                    <?php esc_html_e('Custom title shown in the site banner and cart/checkout labels (e.g., "Enjoy [Title] pricing" and "[Title] Pricing Applied"). Leave empty to use the group name.', 'wccg'); ?>
                                </p>
                            </td>
                        </tr>
                    </table>
                    
                    <?php submit_button(__('Update Default Group', 'wccg'), 'primary', '', false); ?>
                </form>
            </div>

            <h2><?php esc_html_e('Add New Group', 'wccg'); ?></h2>
            <form method="post">
                <?php wp_nonce_field('wccg_customer_groups_action', 'wccg_customer_groups_nonce'); ?>
                <input type="hidden" name="action" value="add_group">

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="group_name"><?php esc_html_e('Group Name', 'wccg'); ?></label>
                        </th>
                        <td>
                            <input name="group_name" 
                            type="text" 
                            id="group_name" 
                            class="regular-text" 
                            required>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="group_description"><?php esc_html_e('Description', 'wccg'); ?></label>
                        </th>
                        <td>
                            <textarea name="group_description" 
                            id="group_description" 
                            class="regular-text"></textarea>
                        </td>
                    </tr>
                </table>

                <?php submit_button(__('Add New Group', 'wccg')); ?>
            </form>

            <h2><?php esc_html_e('Existing Groups', 'wccg'); ?></h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Group ID', 'wccg'); ?></th>
                        <th><?php esc_html_e('Group Name', 'wccg'); ?></th>
                        <th><?php esc_html_e('Description', 'wccg'); ?></th>
                        <th><?php esc_html_e('Actions', 'wccg'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group) : 
                        $is_default = ($default_group_id == $group->group_id);
                    ?>
                        <tr<?php if ($is_default) echo ' style="background-color: #f0f6fc;"'; ?>>
                            <td><?php echo esc_html($group->group_id); ?></td>
                            <td>
                                <?php echo esc_html($group->group_name); ?>
                                <?php if ($is_default): ?>
                                    <span class="dashicons dashicons-star-filled" style="color: #2271b1; font-size: 16px; vertical-align: middle;" title="<?php esc_attr_e('Default group for ungrouped customers', 'wccg'); ?>"></span>
                                    <span style="color: #2271b1; font-size: 11px; font-weight: bold;"><?php esc_html_e('DEFAULT', 'wccg'); ?></span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($group->group_description); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('wccg_customer_groups_action', 'wccg_customer_groups_nonce'); ?>
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr($group->group_id); ?>">
                                    <?php 
                                    if ($is_default) {
                                        submit_button(__('Delete', 'wccg'), 'delete', '', false, array('disabled' => 'disabled', 'title' => __('Cannot delete default group', 'wccg')));
                                    } else {
                                        submit_button(__('Delete', 'wccg'), 'delete', '', false);
                                    }
                                    ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }
}
