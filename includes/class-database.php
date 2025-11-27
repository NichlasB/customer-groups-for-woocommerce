<?php
/**
 * Database operations handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Database {
    /**
     * Single instance of the class
     *
     * @var WCCG_Database
     */
    private static $instance = null;

    /**
     * Database tables
     *
     * @var array
     */
    private $tables;

    /**
     * Get class instance
     *
     * @return WCCG_Database
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
        global $wpdb;

        $this->tables = array(
            'error_log' => $wpdb->prefix . 'wccg_error_log',
            'groups' => $wpdb->prefix . 'customer_groups',
            'user_groups' => $wpdb->prefix . 'user_groups',
            'pricing_rules' => $wpdb->prefix . 'pricing_rules',
            'rule_products' => $wpdb->prefix . 'rule_products',
            'rule_categories' => $wpdb->prefix . 'rule_categories'
        );
    }

/**
 * Execute database transaction
 *
 * @param callable $callback
 * @return mixed
 */
public function transaction($callback) {
    global $wpdb;

    $wpdb->query('START TRANSACTION');

    try {
        $result = $callback();

        if ($result === false) {
            throw new Exception('Transaction failed');
        }

        if ($wpdb->last_error) {
            throw new Exception($wpdb->last_error);
        }

        $wpdb->query('COMMIT');
        return $result;

    } catch (Exception $e) {
        $wpdb->query('ROLLBACK');

        // Only log critical errors or when in debug mode
        if (defined('WP_DEBUG') && WP_DEBUG) {
            WCCG_Utilities::instance()->log_error(
                'Transaction failed: ' . $e->getMessage(),
                array(
                    'trace' => $e->getTraceAsString(),
                    'last_query' => $wpdb->last_query
                ),
                'critical'
            );
        }

        return false;
    }
}

    /**
     * Execute batch operation
     *
     * @param callable $callback
     * @param array $items
     * @param int $batch_size
     * @return bool
     */
    public function batch_operation($callback, $items, $batch_size = 1000) {
        return $this->transaction(function() use ($callback, $items, $batch_size) {
            foreach (array_chunk($items, $batch_size) as $batch) {
                $result = $callback($batch);

                if ($result === false) {
                    throw new Exception('Batch operation failed');
                }
            }
            return true;
        });
    }

    // USER GROUP FUNCTIONALITY

    /**
     * Get user's group
     *
     * @param int $user_id
     * @return int|null
     */
    public function get_user_group($user_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT group_id FROM {$this->tables['user_groups']} WHERE user_id = %d",
            $user_id
        ));
    }

    /**
     * Get user's group name
     *
     * @param int $user_id
     * @return string|null
     */
    public function get_user_group_name($user_id) {
        global $wpdb;

        return $wpdb->get_var($wpdb->prepare(
            "SELECT g.group_name 
            FROM {$this->tables['groups']} g 
            JOIN {$this->tables['user_groups']} ug ON g.group_id = ug.group_id 
            WHERE ug.user_id = %d",
            $user_id
        ));
    }

    /**
     * Get all users in a specific group
     *
     * @param int $group_id
     * @return array Array of user IDs
     */
    public function get_users_in_group($group_id) {
        global $wpdb;

        return $wpdb->get_col($wpdb->prepare(
            "SELECT user_id 
            FROM {$this->tables['user_groups']} 
            WHERE group_id = %d",
            $group_id
        ));
    }

    /**
     * Bulk assign users to group
     *
     * @param array $user_ids
     * @param int $group_id
     * @return bool
     */
    public function bulk_assign_user_groups($user_ids, $group_id) {
        if (empty($user_ids) || !$group_id) {
            return false;
        }

        return $this->batch_operation(
            function($batch_user_ids) use ($group_id) {
                global $wpdb;

                // Remove existing assignments
                $placeholders = implode(',', array_fill(0, count($batch_user_ids), '%d'));
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$this->tables['user_groups']} WHERE user_id IN ($placeholders)",
                    $batch_user_ids
                ));

                // Create new assignments
                $values = array();
                $place_holders = array();

                foreach ($batch_user_ids as $user_id) {
                    $values[] = $user_id;
                    $values[] = $group_id;
                    $place_holders[] = '(%d, %d)';
                }

                $query = $wpdb->prepare(
                    "INSERT INTO {$this->tables['user_groups']} (user_id, group_id) VALUES " . 
                    implode(',', $place_holders),
                    $values
                );

                return $wpdb->query($query);
            },
            $user_ids
        );
    }

        // PRICING RULE FUNCTIONALITY

    /**
     * Get pricing rule for product with hierarchy
     *
     * @param int $product_id
     * @param int $user_id
     * @return object|null
     */
    public function get_pricing_rule_for_product($product_id, $user_id) {
        $group_id = $this->get_user_group($user_id);
        
        // If user has no group, check for a default group setting
        if (!$group_id) {
            $default_group_id = get_option('wccg_default_group_id', 0);
            if ($default_group_id) {
                $group_id = $default_group_id;
            } else {
                return null;
            }
        }

        // Check direct product rules first (highest precedence)
        $product_rule = $this->get_product_specific_rule($product_id, $group_id);
        if ($product_rule) {
            return $product_rule;
        }

        // If no product rule, check category rules
        return $this->get_best_category_rule($product_id, $group_id);
    }

    /**
     * Get product-specific rule
     *
     * @param int $product_id
     * @param int $group_id
     * @return object|null
     */
    private function get_product_specific_rule($product_id, $group_id) {
        global $wpdb;
        
        // Build list of product IDs to check (variation + parent if applicable)
        $product_ids = array($product_id);
        
        // For variations, also check if there's a rule for the parent product
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $parent_id = $product->get_parent_id();
            if ($parent_id) {
                $product_ids[] = $parent_id;
            }
        }
        
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));
        $query_args = array_merge(array($group_id), $product_ids);
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT pr.* 
            FROM {$this->tables['pricing_rules']} pr
            JOIN {$this->tables['rule_products']} rp ON pr.rule_id = rp.rule_id
            WHERE pr.group_id = %d AND rp.product_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY FIELD(rp.product_id, " . implode(',', $product_ids) . ") 
            LIMIT 1",
            $query_args
        ));
    }

    /**
     * Get best category rule for product
     *
     * @param int $product_id
     * @param int $group_id
     * @return object|null
     */
    private function get_best_category_rule($product_id, $group_id) {
        // Get all category IDs including parent categories
        $category_ids = $this->get_all_product_categories($product_id);
        if (empty($category_ids)) {
            return null;
        }

        // Get all applicable category rules
        $category_rules = $this->get_category_rules($category_ids, $group_id);
        if (empty($category_rules)) {
            return null;
        }

        // Return the best rule based on hierarchy
        return $this->determine_best_rule($category_rules);
    }

    /**
     * Get all category IDs including parents
     *
     * @param int $product_id
     * @return array
     */
    private function get_all_product_categories($product_id) {
        $category_ids = array();
        
        // For variations, get the parent product's categories
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $product_id = $product->get_parent_id();
        }
        
        $terms = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'all'));

        if (!is_wp_error($terms) && !empty($terms)) {
            foreach ($terms as $term) {
                $category_ids[] = $term->term_id;
                // Get all parent category IDs
                $ancestors = get_ancestors($term->term_id, 'product_cat', 'taxonomy');
                if (!empty($ancestors)) {
                    $category_ids = array_merge($category_ids, $ancestors);
                }
            }
        }

        return array_unique($category_ids);
    }

    /**
     * Get all category rules
     *
     * @param array $category_ids
     * @param int $group_id
     * @return array
     */
    private function get_category_rules($category_ids, $group_id) {
        global $wpdb;

        $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
        $query = $wpdb->prepare(
            "SELECT pr.*, rc.category_id 
            FROM {$this->tables['pricing_rules']} pr
            JOIN {$this->tables['rule_categories']} rc ON pr.rule_id = rc.rule_id
            WHERE pr.group_id = %d AND rc.category_id IN ($placeholders) AND pr.is_active = 1
            AND (pr.start_date IS NULL OR pr.start_date <= UTC_TIMESTAMP())
            AND (pr.end_date IS NULL OR pr.end_date >= UTC_TIMESTAMP())
            ORDER BY pr.created_at DESC",
            array_merge(array($group_id), $category_ids)
        );

        return $wpdb->get_results($query);
    }

    /**
     * Determine the best rule from a set of rules
     *
     * @param array $rules
     * @return object
     */
    private function determine_best_rule($rules) {
        $best_rule = null;

        foreach ($rules as $rule) {
            // If no best rule yet, set this as the best
            if (!$best_rule) {
                $best_rule = $rule;
                continue;
            }

            // Compare current rule with best rule
            if ($this->compare_discount_rules($rule, $best_rule) > 0) {
                $best_rule = $rule;
            }
        }

        return $best_rule;
    }

    /**
     * Compare two discount rules to determine which offers the better discount
     *
     * @param object $rule1
     * @param object $rule2
     * @return int Returns 1 if rule1 is better, -1 if rule2 is better, 0 if equal
     */
    private function compare_discount_rules($rule1, $rule2) {
        // If one is fixed and one is percentage, fixed takes precedence
        if ($rule1->discount_type !== $rule2->discount_type) {
            return $rule1->discount_type === 'fixed' ? 1 : -1;
        }

        // If both are the same type, compare values
        if ($rule1->discount_value === $rule2->discount_value) {
            // If equal, most recent wins
            return strtotime($rule1->created_at) > strtotime($rule2->created_at) ? 1 : -1;
        }

        return $rule1->discount_value > $rule2->discount_value ? 1 : -1;
    }

    /**
     * Delete pricing rules for a group
     *
     * @param int $group_id
     * @return bool
     */
    public function delete_group_pricing_rules($group_id) {
        return $this->transaction(function() use ($group_id) {
            global $wpdb;

            // Get all rules for this group
            $rules = $wpdb->get_col($wpdb->prepare(
                "SELECT rule_id FROM {$this->tables['pricing_rules']} WHERE group_id = %d",
                $group_id
            ));

            foreach ($rules as $rule_id) {
                // Delete rule associations
                $wpdb->delete($this->tables['rule_products'], array('rule_id' => $rule_id), array('%d'));
                $wpdb->delete($this->tables['rule_categories'], array('rule_id' => $rule_id), array('%d'));
            }

            // Delete the rules themselves
            return $wpdb->delete($this->tables['pricing_rules'], array('group_id' => $group_id), array('%d'));
        });
    }

    // CLEANUP FUNCTIONALITY

/**
 * Cleanup orphaned data
 *
 * @return bool
 */
public function cleanup_orphaned_data() {
    global $wpdb;

    return $this->transaction(function() use ($wpdb) {
        $cleanup_operations = array(
            'user_assignments' => array(
                'table' => $this->tables['user_groups'],
                'query' => "DELETE ug FROM {$this->tables['user_groups']} ug
                LEFT JOIN {$wpdb->users} u ON ug.user_id = u.ID
                WHERE u.ID IS NULL"
            ),
            'product_rules' => array(
                'table' => $this->tables['rule_products'],
                'query' => "DELETE rp FROM {$this->tables['rule_products']} rp
                LEFT JOIN {$wpdb->posts} p ON rp.product_id = p.ID
                WHERE p.ID IS NULL"
            ),
            'category_rules' => array(
                'table' => $this->tables['rule_categories'],
                'query' => "DELETE rc FROM {$this->tables['rule_categories']} rc
                LEFT JOIN {$wpdb->term_taxonomy} tt ON rc.category_id = tt.term_id
                WHERE tt.term_id IS NULL"
            )
        );

        $cleanup_results = array();
        foreach ($cleanup_operations as $operation => $details) {
            // Verify table exists before cleanup
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$details['table']}'") === $details['table'];

            if ($table_exists) {
                $result = $wpdb->query($details['query']);
                if ($result === false) {
                    throw new Exception("Failed to cleanup {$operation}");
                }
                $cleanup_results[$operation] = $result;
            }
        }

        return true;
    });
}

/**
 * Cleanup old logs
 *
 * @return bool
 */
public function cleanup_old_logs() {
    global $wpdb;

    return $this->transaction(function() use ($wpdb) {
        $table_name = $this->tables['error_log'];

        // Verify table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

        if ($table_exists) {
            // Delete non-critical logs older than 30 days
            $wpdb->query(
                "DELETE FROM $table_name
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 30 DAY)
                AND severity != 'critical'"
            );

            // Delete critical logs older than 90 days
            $wpdb->query(
                "DELETE FROM $table_name
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL 90 DAY)
                AND severity = 'critical'"
            );

            return true;
        }

        return false;
    });
}

/**
 * Cleanup orphaned group assignments
 *
 * @return bool
 */
public function cleanup_orphaned_group_assignments() {
    global $wpdb;

    return $this->transaction(function() use ($wpdb) {
        $result = $wpdb->query("
            DELETE ug 
            FROM {$this->tables['user_groups']} ug 
            LEFT JOIN {$this->tables['groups']} g ON ug.group_id = g.group_id 
            WHERE g.group_id IS NULL
            ");

        return $result !== false;
    });
}

    // UPGRADE FUNCTIONALITY

    /**
     * Run database upgrades
     *
     * @param string $installed_version
     * @return bool
     */
    public function run_upgrades($installed_version) {
        global $wpdb;
        $success = true;

        // Add created_at column to pricing_rules table if upgrading from pre-1.1.0
        if (version_compare($installed_version, '1.1.0', '<')) {
            try {
                // Check if column exists first
                $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$this->tables['pricing_rules']} LIKE 'created_at'");

                if (empty($column_exists)) {
                    $wpdb->query("ALTER TABLE {$this->tables['pricing_rules']} 
                        ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        ADD INDEX created_at (created_at)");

                    // Verify the column was added
                    $column_check = $wpdb->get_results("SHOW COLUMNS FROM {$this->tables['pricing_rules']} LIKE 'created_at'");
                    if (empty($column_check)) {
                        throw new Exception('Failed to add created_at column');
                    }

                    // Set created_at for existing records
                    $wpdb->query("UPDATE {$this->tables['pricing_rules']} 
                        SET created_at = CURRENT_TIMESTAMP 
                        WHERE created_at IS NULL");
                }
            } catch (Exception $e) {
                WCCG_Utilities::instance()->log_error(
                    'Database upgrade failed: ' . $e->getMessage(),
                    array(
                        'version' => $installed_version,
                        'trace' => $e->getTraceAsString()
                    ),
                    'critical'
                );
                $success = false;
            }
        }

        return $success;
    }

/**
 * Check for database errors
 *
 * @param string $context Optional context for the error
 * @return bool
 */
private function check_for_errors($context = '') {
    global $wpdb;

    if ($wpdb->last_error) {
        $error_data = array(
            'last_query' => $wpdb->last_query,
            'context' => $context
        );

        // Only include backtrace for critical errors
        if (defined('WP_DEBUG') && WP_DEBUG) {
            $error_data['trace'] = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        }

        // Determine severity based on error type
        $severity = (stripos($wpdb->last_error, 'critical') !== false) ? 'critical' : 'error';

        WCCG_Utilities::instance()->log_error(
            'Database Error: ' . $wpdb->last_error,
            $error_data,
            $severity
        );
        return true;
    }

    return false;
}

}
