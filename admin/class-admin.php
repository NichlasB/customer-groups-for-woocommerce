<?php
/**
 * Admin functionality handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Admin {
    /**
     * Single instance of the class
     *
     * @var WCCG_Admin
     */
    private static $instance = null;

    /**
     * Utility instance
     *
     * @var WCCG_Utilities
     */
    private $utils;

    /**
     * Database instance
     *
     * @var WCCG_Database
     */
    private $db;

    /**
     * Get class instance
     *
     * @return WCCG_Admin
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
        $this->utils = WCCG_Utilities::instance();
        $this->db = WCCG_Database::instance();
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Add menu items
        add_action('admin_menu', array($this, 'add_menu_items'));

        // Add admin assets
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // Add product list column hooks
        add_filter('manage_product_posts_columns', array($this, 'add_pricing_rule_column'));
        add_action('manage_product_posts_custom_column', array($this, 'display_pricing_rule_column'), 10, 2);

        // Add pricing rule info box
        add_action('woocommerce_admin_after_product_data_panels', array($this, 'add_pricing_rule_info_box'));
    }

    /**
     * Add admin menu items
     */
    public function add_menu_items() {
        $this->utils->verify_admin_access();

        add_menu_page(
            __('Customer Groups', 'wccg'),
            __('Customer Groups', 'wccg'),
            'manage_woocommerce',
            'wccg_customer_groups',
            array($this, 'display_customer_groups_page'),
            'dashicons-groups',
            56
        );

        add_submenu_page(
            'wccg_customer_groups',
            __('User Assignments', 'wccg'),
            __('User Assignments', 'wccg'),
            'manage_woocommerce',
            'wccg_user_assignments',
            array($this, 'display_user_assignments_page')
        );

        add_submenu_page(
            'wccg_customer_groups',
            __('Pricing Rules', 'wccg'),
            __('Pricing Rules', 'wccg'),
            'manage_woocommerce',
            'wccg_pricing_rules',
            array($this, 'display_pricing_rules_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook
     */
    public function enqueue_assets($hook) {
        if (strpos($hook, 'wccg_') === false && $hook !== 'product') {
            return;
        }

        wp_enqueue_style(
            'wccg-admin-styles',
            WCCG_URL . 'assets/css/admin.css',
            array(),
            WCCG_VERSION
        );

        wp_enqueue_script(
            'wccg-admin-script',
            WCCG_URL . 'assets/js/admin.js',
            array('jquery'),
            WCCG_VERSION,
            true
        );

        // Add localized script data
        wp_localize_script('wccg-admin-script', 'wccg_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wccg_admin_nonce'),
            'strings' => array(
                'rule_conflict' => __('Warning: This rule conflicts with existing rules', 'wccg'),
                'fixed_discount' => __('Fixed discounts take precedence over percentage discounts', 'wccg'),
                'category_override' => __('Product-specific rules override category rules', 'wccg')
            )
        ));
    }

    /**
     * Add pricing rule column to products list
     *
     * @param array $columns
     * @return array
     */
    public function add_pricing_rule_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;
            if ($key === 'price') {
                $new_columns['pricing_rules'] = __('Group Pricing', 'wccg');
            }
        }

        return $new_columns;
    }

    /**
     * Display pricing rule information in the custom column
     *
     * @param string $column
     * @param int $post_id
     */
    public function display_pricing_rule_column($column, $post_id) {
        if ($column !== 'pricing_rules') {
            return;
        }

        global $wpdb;

        // Get product-specific rules
        $product_rules = $wpdb->get_results($wpdb->prepare(
            "SELECT pr.*, g.group_name 
            FROM {$wpdb->prefix}pricing_rules pr
            JOIN {$wpdb->prefix}rule_products rp ON pr.rule_id = rp.rule_id
            JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
            WHERE rp.product_id = %d
            ORDER BY pr.created_at DESC",
            $post_id
        ));

        // Get category rules
        $category_ids = $this->db->get_all_product_categories($post_id);
        $category_rules = array();

        if (!empty($category_ids)) {
            $placeholders = implode(',', array_fill(0, count($category_ids), '%d'));
            $category_rules = $wpdb->get_results($wpdb->prepare(
                "SELECT pr.*, g.group_name, t.name as category_name
                FROM {$wpdb->prefix}pricing_rules pr
                JOIN {$wpdb->prefix}rule_categories rc ON pr.rule_id = rc.rule_id
                JOIN {$wpdb->prefix}customer_groups g ON pr.group_id = g.group_id
                JOIN {$wpdb->prefix}terms t ON rc.category_id = t.term_id
                WHERE rc.category_id IN ($placeholders)
                ORDER BY pr.created_at DESC",
                $category_ids
            ));
        }

        echo '<div class="wccg-rules-info">';

        if (!empty($product_rules)) {
            echo '<div class="product-specific-rules">';
            echo '<strong>' . __('Product Rules:', 'wccg') . '</strong>';
            foreach ($product_rules as $rule) {
                $this->display_rule_info($rule);
            }
            echo '</div>';
        }

        if (!empty($category_rules)) {
            $disabled = !empty($product_rules) ? ' disabled' : '';
            echo '<div class="category-rules' . $disabled . '">';
            echo '<strong>' . __('Category Rules:', 'wccg') . '</strong>';
            foreach ($category_rules as $rule) {
                $this->display_rule_info($rule, $rule->category_name);
            }
            echo '</div>';
        }

        if (empty($product_rules) && empty($category_rules)) {
            echo '<span class="no-rules">' . __('No pricing rules', 'wccg') . '</span>';
        }

        echo '</div>';
    }

    /**
     * Display individual rule information
     *
     * @param object $rule
     * @param string $category_name
     */
    private function display_rule_info($rule, $category_name = '') {
        $discount_text = $rule->discount_type === 'percentage' 
            ? $rule->discount_value . '%'
            : wc_price($rule->discount_value);

        $tooltip = sprintf(
            __('Discount: %s\nType: %s\nCreated: %s', 'wccg'),
            $discount_text,
            ucfirst($rule->discount_type),
            date_i18n(get_option('date_format'), strtotime($rule->created_at))
        );

        if ($category_name) {
            $tooltip .= sprintf(__('\nCategory: %s', 'wccg'), $category_name);
        }

        echo '<div class="rule-info" title="' . esc_attr($tooltip) . '">';
        echo '<span class="group-name">' . esc_html($rule->group_name) . '</span>: ';
        echo '<span class="discount">' . esc_html($discount_text) . '</span>';
        if ($rule->discount_type === 'fixed') {
            echo ' <span class="priority-indicator" title="' . 
                esc_attr__('Fixed discounts take precedence over percentage discounts', 'wccg') . 
                '">★</span>';
        }
        echo '</div>';
    }

    /**
     * Display customer groups page
     */
    public function display_customer_groups_page() {
        WCCG_Admin_Customer_Groups::instance()->display_page();
    }

    /**
     * Display user assignments page
     */
    public function display_user_assignments_page() {
        WCCG_Admin_User_Assignments::instance()->display_page();
    }

    /**
     * Display pricing rules page
     */
    public function display_pricing_rules_page() {
        WCCG_Admin_Pricing_Rules::instance()->display_page();
    }
}