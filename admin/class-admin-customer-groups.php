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

        $result = $this->db->transaction(function() use ($group_id) {
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Customer Groups', 'wccg'); ?></h1>

            <?php settings_errors('wccg_customer_groups'); ?>

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
                    <?php foreach ($groups as $group) : ?>
                        <tr>
                            <td><?php echo esc_html($group->group_id); ?></td>
                            <td><?php echo esc_html($group->group_name); ?></td>
                            <td><?php echo esc_html($group->group_description); ?></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <?php wp_nonce_field('wccg_customer_groups_action', 'wccg_customer_groups_nonce'); ?>
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?php echo esc_attr($group->group_id); ?>">
                                    <?php submit_button(__('Delete', 'wccg'), 'delete', '', false); ?>
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
