<?php
/**
 * User Assignments admin page handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Admin_User_Assignments {
    /**
     * Single instance of the class
     *
     * @var WCCG_Admin_User_Assignments
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
     * Page parameters
     *
     * @var array
     */
    private $params;

    /**
     * Get class instance
     *
     * @return WCCG_Admin_User_Assignments
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
     * Display the user assignments page
     */
    public function display_page() {
        $this->utils->verify_admin_access();

        // Set up page parameters
        $this->setup_page_params();

        // Handle form submissions
        $this->handle_form_submission();

        // Get data for display
        $users = $this->get_users();
        $total_users = $this->get_total_users();
        $groups = $this->get_groups();
        $user_groups = $this->get_user_groups();

        // Display the page
        $this->render_page($users, $total_users, $groups, $user_groups);
    }

    /**
     * Set up page parameters
     */
    private function setup_page_params() {
        $this->params = array(
            'search' => $this->utils->sanitize_input($_GET['search'] ?? ''),
            'users_per_page' => $this->utils->sanitize_input($_GET['per_page'] ?? 100, 'int'),
            'current_page' => $this->utils->sanitize_input($_GET['paged'] ?? 1, 'int'),
            'orderby' => $this->utils->sanitize_input($_GET['orderby'] ?? 'ID'),
            'order' => $this->utils->sanitize_input($_GET['order'] ?? 'ASC'),
            'group_filter' => $this->utils->sanitize_input($_GET['group_filter'] ?? 0, 'group_id'),
            'date_from' => $this->utils->sanitize_input($_GET['date_from'] ?? ''),
            'date_to' => $this->utils->sanitize_input($_GET['date_to'] ?? '')
        );

        // Validate parameters
        $this->validate_params();
    }

    /**
     * Validate page parameters
     */
    private function validate_params() {
        // Validate users per page
        $allowed_per_page = array(100, 200, 500, 1000);
        if (!in_array($this->params['users_per_page'], $allowed_per_page)) {
            $this->params['users_per_page'] = 100;
        }

        // Validate orderby
        $allowed_orderby = array('ID', 'user_login', 'first_name', 'last_name', 'user_email', 'user_registered');
        if (!in_array($this->params['orderby'], $allowed_orderby)) {
            $this->params['orderby'] = 'ID';
        }

        // Validate order
        $this->params['order'] = strtoupper($this->params['order']);
        if (!in_array($this->params['order'], array('ASC', 'DESC'))) {
            $this->params['order'] = 'ASC';
        }
    }

    /**
     * Handle form submissions
     */
    private function handle_form_submission() {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            return;
        }

        // Verify nonce
        if (!isset($_POST['wccg_user_assignments_nonce']) || 
            !wp_verify_nonce($_POST['wccg_user_assignments_nonce'], 'wccg_user_assignments_action')) {
            wp_die('Security check failed');
    }

    if (isset($_POST['export_csv'])) {
        $this->handle_csv_export();
        return;
    }

    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'assign_users':
            $this->handle_user_assignments();
            break;
            case 'unassign_users':  // Add this new case
            $this->handle_user_unassignments();
            break;
        }
    }
}

    /**
     * Handle CSV export
     */
    /**
 * Handle CSV export
 */
    /**
 * Handle CSV export
 */
    private function handle_csv_export() {
        if (!isset($_POST['user_ids']) || empty($_POST['user_ids'])) {
            $this->add_admin_notice('error', 'Please select users to export.');
            return;
        }

        $user_ids = array_map('intval', $_POST['user_ids']);

    // Add size limit check
    $max_export_users = 1000; // Adjust this number as needed
    if (count($user_ids) > $max_export_users) {
        $this->add_admin_notice(
            'error', 
            sprintf('Maximum of %d users can be exported at once.', $max_export_users)
        );
        return;
    }

    // Clean any output that might have been sent already
    if (ob_get_level()) {
        ob_end_clean();
    }

    // Prevent WordPress from processing further output
    remove_all_actions('shutdown');

    try {
        // Set headers for CSV download
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=customer-groups-export-' . date('Y-m-d') . '.csv');
        header('Pragma: no-cache');
        header('Expires: 0');

        // Create output stream
        $output = fopen('php://output', 'w');
        if ($output === false) {
            throw new Exception('Failed to open output stream');
        }

        // Add UTF-8 BOM for proper Excel encoding
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

        // Add headers to CSV
        fputcsv($output, array(
            'User ID',
            'Username',
            'Email',
            'First Name',
            'Last Name',
            'Customer Group',
            'Registration Date'
        ));

        // Add user data
        foreach ($user_ids as $user_id) {
            $user = get_userdata($user_id);
            if ($user) {
                $group_name = $this->db->get_user_group_name($user_id) ?: 'Unassigned';

                fputcsv($output, array(
                    $user->ID,
                    $user->user_login,
                    $user->user_email,
                    $user->first_name,
                    $user->last_name,
                    $group_name,
                    $user->user_registered
                ));
            }
        }

        fclose($output);
        exit();

    } catch (Exception $e) {
        if ($output) {
            fclose($output);
        }
        $this->utils->log_error(
            'CSV Export Error: ' . $e->getMessage(),
            array(
                'user_ids' => $user_ids,
                'trace' => $e->getTraceAsString()
            )
        );
        wp_die('Error generating CSV file. Please try again.');
    }
}

/**
* Handle user assignments
*/
private function handle_user_assignments() {
    if (!isset($_POST['user_ids']) || empty($_POST['user_ids']) || !isset($_POST['group_id'])) {
        $this->add_admin_notice('error', 'Please select users and a group.');
        return;
    }

        // Add rate limiting check
    if (!$this->utils->check_rate_limit(get_current_user_id(), 'group_change')) {
        $this->add_admin_notice(
            'error', 
            'Too many group assignments attempted. Please wait a few minutes and try again.'
        );
        return;
    }

    $user_ids = array_map('intval', $_POST['user_ids']);
    $group_id = $this->utils->sanitize_input($_POST['group_id'], 'group_id');

        // Validate inputs
    if (empty($user_ids) || empty($group_id)) {
        $this->add_admin_notice('error', 'Invalid user IDs or group ID provided.');
        return;
    }

        // Check batch size limit
    $max_users_per_batch = 100;
    if (count($user_ids) > $max_users_per_batch) {
        $this->add_admin_notice(
            'error', 
            sprintf('Maximum of %d users can be assigned at once.', $max_users_per_batch)
        );
        return;
    }

        // Perform the bulk assignment
    $result = $this->db->bulk_assign_user_groups($user_ids, $group_id);

    if ($result) {
        $this->add_admin_notice('success', 'Users assigned to group successfully.');
    } else {
        $this->add_admin_notice('error', 'Error occurred while assigning users to group.');
    }
}

/**
 * Handle unassigning users
 */
private function handle_user_unassignments() {
    if (!isset($_POST['user_ids']) || empty($_POST['user_ids'])) {
        $this->add_admin_notice('error', 'Please select users to unassign.');
        return;
    }

    // Add rate limiting check
    if (!$this->utils->check_rate_limit(get_current_user_id(), 'group_change')) {
        $this->add_admin_notice(
            'error', 
            'Too many group changes attempted. Please wait a few minutes and try again.'
        );
        return;
    }

    $user_ids = array_map('intval', $_POST['user_ids']);

    // Validate inputs
    if (empty($user_ids)) {
        $this->add_admin_notice('error', 'Invalid user IDs provided.');
        return;
    }

    // Check batch size limit
    $max_users_per_batch = 100;
    if (count($user_ids) > $max_users_per_batch) {
        $this->add_admin_notice(
            'error', 
            sprintf('Maximum of %d users can be unassigned at once.', $max_users_per_batch)
        );
        return;
    }

    // Perform the unassignment
    $result = $this->db->transaction(function() use ($user_ids) {
        global $wpdb;

        // Delete the user group assignments
        $placeholders = implode(',', array_fill(0, count($user_ids), '%d'));
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}user_groups WHERE user_id IN ($placeholders)",
            $user_ids
        ));
    });

    if ($result !== false) {
        $this->add_admin_notice('success', 'Users unassigned successfully.');
    } else {
        $this->add_admin_notice('error', 'Error occurred while unassigning users.');
    }
}

    /**
     * Get users based on current filters
     *
     * @return array
     */
    private function get_users() {
        $args = array(
            'fields' => array('ID', 'user_login', 'user_email', 'user_registered'),
            'number' => $this->params['users_per_page'],
            'paged' => $this->params['current_page'],
            'orderby' => $this->params['orderby'],
            'order' => $this->params['order']
        );

        // Add date range filters
        if (!empty($this->params['date_from']) || !empty($this->params['date_to'])) {
            $date_query = array();

            if (!empty($this->params['date_from'])) {
                $date_query['after'] = $this->params['date_from'];
            }

            if (!empty($this->params['date_to'])) {
                $date_query['before'] = $this->params['date_to'];
            }

            $date_query['inclusive'] = true;
            $args['date_query'] = array($date_query);
        }

        // Add search if present
        if (!empty($this->params['search'])) {
            $args['search'] = '*' . $this->params['search'] . '*';
            $args['search_columns'] = array('user_login', 'user_email', 'display_name');
        }

        // Add group filter
        if ($this->params['group_filter'] > 0) {
            $users_in_group = $this->db->get_users_in_group($this->params['group_filter']);
            $args['include'] = !empty($users_in_group) ? $users_in_group : array(0);
        }

        $users = get_users($args);

        // Add first and last names
        foreach ($users as $user) {
            $user->first_name = get_user_meta($user->ID, 'first_name', true);
            $user->last_name = get_user_meta($user->ID, 'last_name', true);
        }

        return $users;
    }

    /**
     * Get total users count
     *
     * @return int
     */
    private function get_total_users() {
        $args = array(
            'fields' => 'ID',
            'number' => -1
        );

        if ($this->params['group_filter'] > 0) {
            $users_in_group = $this->db->get_users_in_group($this->params['group_filter']);
            $args['include'] = !empty($users_in_group) ? $users_in_group : array(0);
        }

        return count(get_users($args));
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
     * Get user group assignments
     *
     * @return array
     */
    private function get_user_groups() {
        global $wpdb;
        return $wpdb->get_results(
            "SELECT * FROM {$wpdb->prefix}user_groups",
            OBJECT_K
        );
    }

    /**
     * Get sorting URL
     *
     * @param string $column
     * @return string
     */
    private function get_sorting_url($column) {
        $new_order = ($this->params['orderby'] === $column && 
         $this->params['order'] === 'ASC') ? 'DESC' : 'ASC';

        return add_query_arg(array(
            'orderby' => $column,
            'order' => $new_order,
            'search' => $this->params['search'],
            'per_page' => $this->params['users_per_page'],
            'paged' => 1,
            'group_filter' => $this->params['group_filter'],
            'date_from' => $this->params['date_from'],
            'date_to' => $this->params['date_to']
        ));
    }

    /**
     * Get sort indicator
     *
     * @param string $column
     * @return string
     */
    private function get_sort_indicator($column) {
        if ($this->params['orderby'] === $column) {
            return ($this->params['order'] === 'ASC') ? ' ↑' : ' ↓';
        }
        return '';
    }

    /**
     * Add admin notice
     *
     * @param string $type
     * @param string $message
     */
    private function add_admin_notice($type, $message) {
        add_settings_error(
            'wccg_user_assignments',
            'wccg_notice',
            $message,
            $type
        );
    }

    /**
     * Get user group name
     *
     * @param int $user_id
     * @param array $user_groups
     * @param array $groups
     * @return string
     */
    private function get_user_group_name($user_id, $user_groups, $groups) {
        if (!isset($user_groups[$user_id])) {
            return 'Unassigned';
        }

        $group_id = $user_groups[$user_id]->group_id;
        foreach ($groups as $group) {
            if ($group->group_id === $group_id) {
                return esc_html($group->group_name);
            }
        }

        // If we get here, the group doesn't exist anymore
        // Remove the orphaned assignment
        $this->db->cleanup_orphaned_group_assignments();
        return 'Unassigned';
    }

    /**
     * Render the page
     *
     * @param array $users
     * @param int $total_users
     * @param array $groups
     * @param array $user_groups
     */
    private function render_page($users, $total_users, $groups, $user_groups) {
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('User Assignments', 'wccg'); ?></h1>

            <?php settings_errors('wccg_user_assignments'); ?>

            <!-- Search and Filter Form -->
            <form method="get">
                <input type="hidden" name="page" value="wccg_user_assignments">

                <div class="tablenav top">
                    <div class="alignleft actions">
                        <input type="text" 
                        id="user-search" 
                        name="search" 
                        value="<?php echo esc_attr($this->params['search']); ?>" 
                        placeholder="<?php esc_attr_e('Search users...', 'wccg'); ?>">

                        <input type="date" 
                        id="date-from" 
                        name="date_from" 
                        value="<?php echo esc_attr($this->params['date_from']); ?>">

                        <input type="date" 
                        id="date-to" 
                        name="date_to" 
                        value="<?php echo esc_attr($this->params['date_to']); ?>">

                        <select name="per_page" id="per_page">
                            <?php foreach (array(100, 200, 500, 1000) as $option) : ?>
                                <option value="<?php echo esc_attr($option); ?>" 
                                    <?php selected($this->params['users_per_page'] === $option); ?>>
                                    <?php echo esc_html($option . ' per page'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <select name="group_filter" id="group-filter">
                            <option value="0"><?php esc_html_e('All Groups', 'wccg'); ?></option>
                            <?php foreach ($groups as $group) : ?>
                                <option value="<?php echo esc_attr($group->group_id); ?>" 
                                    <?php selected($this->params['group_filter'], $group->group_id); ?>>
                                    <?php echo esc_html($group->group_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <input type="submit" class="button" value="<?php esc_attr_e('Apply', 'wccg'); ?>">
                    </div>

                    <?php
                    echo paginate_links(array(
                        'base' => add_query_arg('paged', '%#%'),
                        'format' => '',
                        'prev_text' => __('&laquo;'),
                        'next_text' => __('&raquo;'),
                        'total' => ceil($total_users / $this->params['users_per_page']),
                        'current' => $this->params['current_page'],
                        'add_args' => array(
                            'search' => $this->params['search'],
                            'per_page' => $this->params['users_per_page'],
                            'orderby' => $this->params['orderby'],
                            'order' => $this->params['order'],
                            'group_filter' => $this->params['group_filter'],
                            'date_from' => $this->params['date_from'],
                            'date_to' => $this->params['date_to']
                        )
                    ));
                    ?>
                </div>
            </form>

            <!-- User Assignment Form -->
            <form method="post">
                <?php wp_nonce_field('wccg_user_assignments_action', 'wccg_user_assignments_nonce'); ?>
                <input type="hidden" name="action" value="assign_users">

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 50px;">#</th>
                            <th><input type="checkbox" id="select-all-users"></th>
                            <th>
                                <a href="<?php echo esc_url($this->get_sorting_url('display_name')); ?>">
                                    <?php 
                                    esc_html_e('Name', 'wccg');
                                    echo $this->get_sort_indicator('display_name');
                                    ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url($this->get_sorting_url('user_email')); ?>">
                                    <?php 
                                    esc_html_e('Email', 'wccg');
                                    echo $this->get_sort_indicator('user_email');
                                    ?>
                                </a>
                            </th>
                            <th>
                                <a href="<?php echo esc_url($this->get_sorting_url('user_registered')); ?>">
                                    <?php 
                                    esc_html_e('Registered', 'wccg');
                                    echo $this->get_sort_indicator('user_registered');
                                    ?>
                                </a>
                            </th>
                            <th><?php esc_html_e('Current Group', 'wccg'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $counter = ($this->params['current_page'] - 1) * $this->params['users_per_page'] + 1;
                        foreach ($users as $user) : 
                            ?>
                            <tr>
                                <td style="text-align: center;"><?php echo esc_html($counter++); ?></td>
                                <td>
                                    <input type="checkbox" 
                                    name="user_ids[]" 
                                    value="<?php echo esc_attr($user->ID); ?>">
                                </td>
                                <td>
                                    <?php
                                    $display_name = empty($user->first_name) && empty($user->last_name)
                                    ? esc_html($user->user_login)
                                    : esc_html(trim($user->first_name . ' ' . $user->last_name));

                                    $edit_link = admin_url('admin.php?page=customer-manager-edit&id=' . $user->ID);
                                    echo '<a href="' . esc_url($edit_link) . '">' . $display_name . '</a>';
                                    ?>
                                </td>
                                <td><?php echo esc_html($user->user_email); ?></td>
                                <td><?php echo esc_html(date('Y-m-d', strtotime($user->user_registered))); ?></td>
                                <td>
                                    <?php echo $this->get_user_group_name($user->ID, $user_groups, $groups); ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="tablenav bottom">
                    <div class="alignleft actions bulkactions">
                        <h2><?php esc_html_e('Assign to Group', 'wccg'); ?></h2>
                        <select name="group_id" required>
                            <?php foreach ($groups as $group) : ?>
                                <option value="<?php echo esc_attr($group->group_id); ?>">
                                    <?php echo esc_html($group->group_name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php submit_button(__('Assign Selected Users', 'wccg'), 'primary', 'submit', false); ?>
                        <button type="submit" 
                        name="action" 
                        value="unassign_users" 
                        class="button" 
                        style="margin-left: 10px;">
                        <?php esc_html_e('Unassign Selected Users', 'wccg'); ?>
                    </button>
                    <button type="submit" 
                    name="export_csv" 
                    class="button" 
                    style="margin-left: 10px;">
                    <?php esc_html_e('Export Selected Users to CSV', 'wccg'); ?>
                </button>
            </div>
        </div>
    </form>
</div>
<?php
}
}