<?php
/**
 * Public-facing functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class WCCG_Public {
    /**
     * Single instance of the class
     *
     * @var WCCG_Public
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
     * Request-level cache for adjusted prices
     *
     * @var array
     */
    private $price_cache = array();

    /**
     * Get class instance
     *
     * @return WCCG_Public
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
        // Price adjustment hooks
        add_action('woocommerce_before_calculate_totals', array($this, 'adjust_cart_prices'), 10, 1);
        add_filter('woocommerce_get_price_html', array($this, 'adjust_price_display'), 10, 2);

        // Cart price display hooks
        add_filter('woocommerce_cart_item_price', array($this, 'display_cart_item_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'display_cart_item_subtotal'), 10, 3);

        // Display hooks
        add_action('wp_body_open', array($this, 'display_sticky_banner'), 10);

        // Enqueue styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
    }

    /**
     * Enqueue public styles
     */
    public function enqueue_styles() {
        wp_enqueue_style(
            'wccg-public-styles',
            WCCG_URL . 'assets/css/public.css',
            array(),
            WCCG_VERSION
        );
    }

    /**
     * Adjust cart prices
     *
     * @param WC_Cart $cart
     */
    public function adjust_cart_prices($cart) {
        if (is_admin() || !is_user_logged_in()) {
            return;
        }

        // Use cart-specific flag to prevent duplicate processing in the same calculation cycle
        // This allows the hook to work correctly on AJAX requests (checkout page updates)
        if (!empty($cart->wccg_prices_adjusted)) {
            return;
        }

        $user_id = get_current_user_id();

        foreach ($cart->get_cart() as $cart_item) {
            $product = $cart_item['data'];
            $adjusted_price = $this->get_adjusted_price($product);

            if ($adjusted_price !== false) {
                $product->set_price($adjusted_price);
            }
        }

        // Mark cart as processed for this calculation cycle
        $cart->wccg_prices_adjusted = true;
    }

    /**
     * Adjust price display
     *
     * @param string $price_html
     * @param WC_Product $product
     * @return string
     */
    public function adjust_price_display($price_html, $product) {
        if (!is_user_logged_in()) {
            return $price_html;
        }

        $adjusted_price = $this->get_adjusted_price($product);

        if ($adjusted_price === false) {
            return $price_html;
        }

        // Get base price: sale price if on sale, otherwise regular price
        $sale_price = $product->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $product->get_regular_price();

        if ($adjusted_price < $original_price) {
            $group_name = $this->db->get_user_group_name(get_current_user_id());
            $label_html = $group_name ? sprintf(
                ' <span class="special-price-label">%s Pricing</span>',
                $this->utils->escape_output($group_name)
            ) : '';

            $price_html = sprintf(
                '<del>%s</del> <ins>%s</ins>%s',
                wc_price($original_price),
                wc_price($adjusted_price),
                $label_html
            );
        }

        return $price_html;
    }

    /**
     * Display cart item price with original crossed out
     *
     * @param string $price_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function display_cart_item_price($price_html, $cart_item, $cart_item_key) {
        if (!is_user_logged_in()) {
            return $price_html;
        }

        $product = $cart_item['data'];
        $adjusted_price = $this->get_adjusted_price($product);

        if ($adjusted_price === false) {
            return $price_html;
        }

        // Get base price: sale price if on sale, otherwise regular price
        $sale_price = $product->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $product->get_regular_price();

        if ($adjusted_price < $original_price) {
            return sprintf(
                '<del>%s</del> <ins>%s</ins>',
                wc_price($original_price),
                wc_price($adjusted_price)
            );
        }

        return $price_html;
    }

    /**
     * Display cart item subtotal with original crossed out
     *
     * @param string $subtotal_html
     * @param array $cart_item
     * @param string $cart_item_key
     * @return string
     */
    public function display_cart_item_subtotal($subtotal_html, $cart_item, $cart_item_key) {
        if (!is_user_logged_in()) {
            return $subtotal_html;
        }

        $product = $cart_item['data'];
        $adjusted_price = $this->get_adjusted_price($product);

        if ($adjusted_price === false) {
            return $subtotal_html;
        }

        // Get base price: sale price if on sale, otherwise regular price
        $sale_price = $product->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $product->get_regular_price();
        $quantity = $cart_item['quantity'];

        if ($adjusted_price < $original_price) {
            return sprintf(
                '<del>%s</del> <ins>%s</ins>',
                wc_price($original_price * $quantity),
                wc_price($adjusted_price * $quantity)
            );
        }

        return $subtotal_html;
    }

    /**
     * Get adjusted price for a product
     *
     * @param WC_Product $product
     * @return float|false
     */
    private function get_adjusted_price($product) {
        $user_id = get_current_user_id();

        if (!$user_id) {
            return false;
        }

        // Check request-level cache first
        $cache_key = $product->get_id() . '_' . $user_id;
        if (isset($this->price_cache[$cache_key])) {
            return $this->price_cache[$cache_key];
        }

        // Get the base price: use sale price if on sale, otherwise regular price
        // This respects WooCommerce sales while avoiding double-discount issues
        $sale_price = $product->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $product->get_regular_price();
        
        if (empty($original_price)) {
            $this->price_cache[$cache_key] = false;
            return false;
        }

        $pricing_rule = $this->db->get_pricing_rule_for_product($product->get_id(), $user_id);
        if (!$pricing_rule) {
            $this->price_cache[$cache_key] = false;
            return false;
        }

        $adjusted_price = $this->calculate_discounted_price($original_price, $pricing_rule);
        
        // Cache the result for this request
        $this->price_cache[$cache_key] = $adjusted_price;
        
        return $adjusted_price;
    }

    /**
     * Calculate discounted price
     *
     * @param float $original_price
     * @param object $pricing_rule
     * @return float
     */
    private function calculate_discounted_price($original_price, $pricing_rule) {
        if ($pricing_rule->discount_type === 'percentage') {
            $discount_amount = ($pricing_rule->discount_value / 100) * $original_price;
        } else {
            $discount_amount = $pricing_rule->discount_value;
        }

        $adjusted_price = $original_price - $discount_amount;

        if ($adjusted_price < 0) {
            $this->utils->log_error(
                'Negative price calculated',
                array(
                    'original_price' => $original_price,
                    'discount_type' => $pricing_rule->discount_type,
                    'discount_value' => $pricing_rule->discount_value
                )
            );
            return 0;
        }

        return $adjusted_price;
    }

    /**
     * Display sticky banner
     */
    public function display_sticky_banner() {
        if (!is_user_logged_in()) {
            return;
        }

        $user_id = get_current_user_id();
        $group_id = $this->db->get_user_group($user_id);

        if (!$group_id) {
            return;
        }

        $user_info = get_userdata($user_id);
        $first_name = $user_info->first_name;

        if (empty($first_name)) {
            $first_name = $user_info->display_name;
        }

        $group_name = $this->db->get_user_group_name($user_id);

        // Check if group has an active pricing rule
        global $wpdb;
        $has_rule = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}pricing_rules WHERE group_id = %d",
            $group_id
        ));

        if ($has_rule && $group_name) {
            echo '<div class="wccg-sticky-banner">' . 
                $this->utils->escape_output($first_name) . 
                ', you receive <strong>' . 
                $this->utils->escape_output($group_name) . 
                '</strong> pricing on eligible products!</div>';
        }
    }
}