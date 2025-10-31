<?php
/**
 * Plugin activation handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Activator {
/**
 * Activate the plugin
 */
public static function activate() {
    try {
        self::create_tables();
        self::add_created_at_column();
        self::add_is_active_column();
        self::add_sort_order_column();
        self::migrate_existing_rules();
        self::schedule_tasks();
        self::set_version();
    } catch (Exception $e) {
        error_log('WCCG Critical: Activation failed - ' . $e->getMessage());
    }
}

/**
 * Create plugin tables
 */
private static function create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    // Table names
    $error_log_table = $wpdb->prefix . 'wccg_error_log';
    $groups_table = $wpdb->prefix . 'customer_groups';
    $user_groups_table = $wpdb->prefix . 'user_groups';
    $pricing_rules_table = $wpdb->prefix . 'pricing_rules';
    $rule_products_table = $wpdb->prefix . 'rule_products';
    $rule_categories_table = $wpdb->prefix . 'rule_categories';

    // Required WordPress upgrade file
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    // Define critical tables (ones that are essential for plugin operation)
    $critical_tables = array(
        $groups_table,
        $user_groups_table,
        $pricing_rules_table,
        $rule_products_table,
        $rule_categories_table
    );

    // Create tables with verification
    $tables_sql = array(
        $error_log_table => "CREATE TABLE IF NOT EXISTS $error_log_table (
            log_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            timestamp DATETIME NOT NULL,
            user_id BIGINT UNSIGNED NOT NULL,
            message TEXT NOT NULL,
            data TEXT,
            severity VARCHAR(20) NOT NULL,
            PRIMARY KEY (log_id),
            KEY timestamp (timestamp),
            KEY severity (severity)
        ) $charset_collate;",

        $groups_table => "CREATE TABLE IF NOT EXISTS $groups_table (
            group_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_name VARCHAR(255) NOT NULL,
            group_description TEXT,
            PRIMARY KEY (group_id)
        ) $charset_collate;",

        $user_groups_table => "CREATE TABLE IF NOT EXISTS $user_groups_table (
            user_id BIGINT(20) UNSIGNED NOT NULL,
            group_id INT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id),
            KEY group_id (group_id)
        ) $charset_collate;",

        $pricing_rules_table => "CREATE TABLE IF NOT EXISTS $pricing_rules_table (
            rule_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            group_id INT UNSIGNED NOT NULL,
            discount_type ENUM('percentage','fixed') NOT NULL,
            discount_value DECIMAL(10,2) NOT NULL,
            is_active TINYINT(1) DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            sort_order INT UNSIGNED DEFAULT 0,
            PRIMARY KEY (rule_id),
            KEY group_id (group_id),
            KEY created_at (created_at),
            KEY is_active (is_active),
            KEY sort_order (sort_order)
        ) $charset_collate;",

        $rule_products_table => "CREATE TABLE IF NOT EXISTS $rule_products_table (
            rule_id INT UNSIGNED NOT NULL,
            product_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (rule_id, product_id)
        ) $charset_collate;",

        $rule_categories_table => "CREATE TABLE IF NOT EXISTS $rule_categories_table (
            rule_id INT UNSIGNED NOT NULL,
            category_id BIGINT(20) UNSIGNED NOT NULL,
            PRIMARY KEY (rule_id, category_id)
        ) $charset_collate;"
    );

    // Create and verify each table
    $failed_tables = array();
    foreach ($tables_sql as $table => $sql) {
        dbDelta($sql);

        // Verify table creation
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;

        if (!$table_exists && in_array($table, $critical_tables)) {
            $failed_tables[] = $table;
        }
    }

    // Log only critical table creation failures
    if (!empty($failed_tables)) {
        error_log('WCCG Critical: Failed to create critical tables: ' . implode(', ', $failed_tables));
        throw new Exception('Failed to create critical database tables');
    }
}

/**
 * Add created_at column if it doesn't exist
 */
private static function add_created_at_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pricing_rules';
    $column_name = 'created_at';

    // Check if column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s 
        AND TABLE_NAME = %s 
        AND COLUMN_NAME = %s",
        DB_NAME,
        $table_name,
        $column_name
    ));

    if (empty($column_exists)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table_name} 
            ADD COLUMN {$column_name} TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            ADD INDEX created_at (created_at)"
        );

        if ($result === false) {
            error_log('WCCG Critical: Failed to add created_at column - ' . $wpdb->last_error);
            throw new Exception('Failed to add created_at column');
        }

        // Set created_at for existing records
        $wpdb->query(
            "UPDATE {$table_name} 
            SET created_at = CURRENT_TIMESTAMP 
            WHERE created_at IS NULL"
        );
    }
}

/**
 * Add is_active column if it doesn't exist
 */
private static function add_is_active_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pricing_rules';
    $column_name = 'is_active';

    // Check if column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s 
        AND TABLE_NAME = %s 
        AND COLUMN_NAME = %s",
        DB_NAME,
        $table_name,
        $column_name
    ));

    if (empty($column_exists)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table_name} 
            ADD COLUMN {$column_name} TINYINT(1) DEFAULT 1,
            ADD INDEX is_active (is_active)"
        );

        if ($result === false) {
            error_log('WCCG Critical: Failed to add is_active column - ' . $wpdb->last_error);
            throw new Exception('Failed to add is_active column');
        }

        // Set is_active for existing records (all active by default)
        $wpdb->query(
            "UPDATE {$table_name} 
            SET is_active = 1 
            WHERE is_active IS NULL"
        );
    }
}

/**
 * Add sort_order column if it doesn't exist
 */
private static function add_sort_order_column() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pricing_rules';
    $column_name = 'sort_order';

    // Check if column exists
    $column_exists = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM INFORMATION_SCHEMA.COLUMNS 
        WHERE TABLE_SCHEMA = %s 
        AND TABLE_NAME = %s 
        AND COLUMN_NAME = %s",
        DB_NAME,
        $table_name,
        $column_name
    ));

    if (empty($column_exists)) {
        $result = $wpdb->query(
            "ALTER TABLE {$table_name} 
            ADD COLUMN {$column_name} INT UNSIGNED DEFAULT 0,
            ADD INDEX sort_order (sort_order)"
        );

        if ($result === false) {
            error_log('WCCG Critical: Failed to add sort_order column - ' . $wpdb->last_error);
            throw new Exception('Failed to add sort_order column');
        }

        // Set sort_order for existing records based on rule_id
        $wpdb->query(
            "UPDATE {$table_name} 
            SET sort_order = rule_id 
            WHERE sort_order = 0"
        );
    }
}

/**
 * Migrate existing rules
 */
private static function migrate_existing_rules() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'pricing_rules';

    // Update any NULL created_at values
    $result = $wpdb->query(
        "UPDATE {$table_name} 
        SET created_at = CURRENT_TIMESTAMP 
        WHERE created_at IS NULL"
    );

    if ($result === false) {
        error_log('WCCG Critical: Failed to migrate rules - ' . $wpdb->last_error);
        throw new Exception('Failed to migrate existing rules');
    }
}

    /**
     * Schedule cron tasks
     */
    private static function schedule_tasks() {
        if (!wp_next_scheduled('wccg_cleanup_cron')) {
            wp_schedule_event(time(), 'daily', 'wccg_cleanup_cron');
        }
    }

    /**
     * Set plugin version
     */
    private static function set_version() {
        update_option('wccg_version', WCCG_VERSION);
        update_option('wccg_installation_date', current_time('mysql'));
    }
}