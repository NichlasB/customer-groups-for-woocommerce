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
     * Flag to prevent duplicate banner display
     *
     * @var bool
     */
    private $banner_displayed = false;

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
        
        // Variable product price hooks
        add_filter('woocommerce_variable_price_html', array($this, 'adjust_variable_price_html'), 10, 2);
        add_filter('woocommerce_available_variation', array($this, 'adjust_variation_data'), 10, 3);
        add_filter('woocommerce_variation_price_html', array($this, 'adjust_variation_price_html'), 10, 2);

        // Cart price display hooks
        add_filter('woocommerce_cart_item_price', array($this, 'display_cart_item_price'), 10, 3);
        add_filter('woocommerce_cart_item_subtotal', array($this, 'display_cart_item_subtotal'), 10, 3);

        // Display hooks - use wp_body_open if available, otherwise wp_footer
        add_action('wp_body_open', array($this, 'display_sticky_banner'), 10);
        // Fallback for themes that don't support wp_body_open
        add_action('wp_footer', array($this, 'display_sticky_banner_fallback'), 5);

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
        if (is_admin()) {
            return;
        }

        // Use cart-specific flag to prevent duplicate processing in the same calculation cycle
        // This allows the hook to work correctly on AJAX requests (checkout page updates)
        if (!empty($cart->wccg_prices_adjusted)) {
            return;
        }

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
        // Handle variable products differently
        if ($product->is_type('variable')) {
            return $this->adjust_variable_price_display($price_html, $product);
        }
        
        $adjusted_price = $this->get_adjusted_price($product);

        if ($adjusted_price === false) {
            return $price_html;
        }

        // Get base price: sale price if on sale, otherwise regular price
        $sale_price = $product->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $product->get_regular_price();

        if ($adjusted_price < $original_price) {
            $user_id = get_current_user_id();
            $group_name = $user_id ? $this->db->get_user_group_name($user_id) : null;
            
            // If no group name found, check if using default group
            if (!$group_name && get_option('wccg_default_group_id', 0)) {
                global $wpdb;
                $group_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                    get_option('wccg_default_group_id', 0)
                ));
            }
            
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
     * Adjust price display for variable products
     *
     * @param string $price_html
     * @param WC_Product_Variable $product
     * @return string
     */
    private function adjust_variable_price_display($price_html, $product) {
        $user_id = get_current_user_id();
        $effective_user_id = $user_id ? $user_id : 0;
        
        // Check if user qualifies for group pricing (either assigned group or default group)
        $group_id = $user_id ? $this->db->get_user_group($user_id) : null;
        if (!$group_id) {
            $group_id = get_option('wccg_default_group_id', 0);
        }
        
        if (!$group_id) {
            return $price_html;
        }
        
        // Get variation prices
        $variation_prices = $product->get_variation_prices(true);
        
        if (empty($variation_prices['price'])) {
            return $price_html;
        }
        
        $min_price = min($variation_prices['price']);
        $max_price = max($variation_prices['price']);
        
        // Get pricing rule using parent product ID (for category rules)
        $pricing_rule = $this->db->get_pricing_rule_for_product($product->get_id(), $effective_user_id);
        
        if (!$pricing_rule) {
            return $price_html;
        }
        
        // Calculate discounted prices
        $discounted_min = $this->calculate_discounted_price($min_price, $pricing_rule);
        $discounted_max = $this->calculate_discounted_price($max_price, $pricing_rule);
        
        // Only show discount if there's an actual reduction
        if ($discounted_min >= $min_price && $discounted_max >= $max_price) {
            return $price_html;
        }
        
        // Get group name for label
        $group_name = $user_id ? $this->db->get_user_group_name($user_id) : null;
        if (!$group_name && get_option('wccg_default_group_id', 0)) {
            // Check for custom title first
            $custom_title = get_option('wccg_default_group_custom_title', '');
            if (!empty($custom_title)) {
                $group_name = $custom_title;
            } else {
                global $wpdb;
                $group_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                    get_option('wccg_default_group_id', 0)
                ));
            }
        }
        
        $label_html = $group_name ? sprintf(
            ' <span class="special-price-label">%s Pricing</span>',
            $this->utils->escape_output($group_name)
        ) : '';
        
        // Format the price display
        if ($min_price === $max_price) {
            // Single price (all variations same price)
            $price_html = sprintf(
                '<del>%s</del> <ins>%s</ins>%s',
                wc_price($min_price),
                wc_price($discounted_min),
                $label_html
            );
        } else {
            // Price range
            $price_html = sprintf(
                '<del>%s – %s</del> <ins>%s – %s</ins>%s',
                wc_price($min_price),
                wc_price($max_price),
                wc_price($discounted_min),
                wc_price($discounted_max),
                $label_html
            );
        }
        
        return $price_html;
    }

    /**
     * Adjust variable product price HTML (specific filter for variable products)
     *
     * @param string $price_html
     * @param WC_Product_Variable $product
     * @return string
     */
    public function adjust_variable_price_html($price_html, $product) {
        return $this->adjust_variable_price_display($price_html, $product);
    }

    /**
     * Adjust variation data for JavaScript (when variation is selected)
     *
     * @param array $variation_data
     * @param WC_Product $product
     * @param WC_Product_Variation $variation
     * @return array
     */
    public function adjust_variation_data($variation_data, $product, $variation) {
        $user_id = get_current_user_id();
        $effective_user_id = $user_id ? $user_id : 0;
        
        // Get pricing rule for this variation (checks variation first, then parent)
        $pricing_rule = $this->db->get_pricing_rule_for_product($variation->get_id(), $effective_user_id);
        
        if (!$pricing_rule) {
            return $variation_data;
        }
        
        // Get the original price
        $original_price = $variation_data['display_price'];
        
        // Calculate discounted price
        $discounted_price = $this->calculate_discounted_price($original_price, $pricing_rule);
        
        // Only modify if there's an actual discount
        if ($discounted_price >= $original_price) {
            return $variation_data;
        }
        
        // Get group name for label
        $group_name = $user_id ? $this->db->get_user_group_name($user_id) : null;
        if (!$group_name && get_option('wccg_default_group_id', 0)) {
            $custom_title = get_option('wccg_default_group_custom_title', '');
            if (!empty($custom_title)) {
                $group_name = $custom_title;
            } else {
                global $wpdb;
                $group_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                    get_option('wccg_default_group_id', 0)
                ));
            }
        }
        
        $label_html = $group_name ? sprintf(
            ' <span class="special-price-label">%s Pricing</span>',
            $this->utils->escape_output($group_name)
        ) : '';
        
        // Update variation data with discounted prices
        $variation_data['display_price'] = $discounted_price;
        $variation_data['display_regular_price'] = $original_price;
        
        // Update the price HTML shown when variation is selected
        $variation_data['price_html'] = sprintf(
            '<del>%s</del> <ins>%s</ins>%s',
            wc_price($original_price),
            wc_price($discounted_price),
            $label_html
        );
        
        return $variation_data;
    }

    /**
     * Adjust individual variation price HTML
     *
     * @param string $price_html
     * @param WC_Product_Variation $variation
     * @return string
     */
    public function adjust_variation_price_html($price_html, $variation) {
        $adjusted_price = $this->get_adjusted_price($variation);
        
        if ($adjusted_price === false) {
            return $price_html;
        }
        
        // Get base price
        $sale_price = $variation->get_sale_price();
        $original_price = !empty($sale_price) && $sale_price > 0 ? $sale_price : $variation->get_regular_price();
        
        if (empty($original_price) || $adjusted_price >= $original_price) {
            return $price_html;
        }
        
        // Get group name for label
        $user_id = get_current_user_id();
        $group_name = $user_id ? $this->db->get_user_group_name($user_id) : null;
        if (!$group_name && get_option('wccg_default_group_id', 0)) {
            $custom_title = get_option('wccg_default_group_custom_title', '');
            if (!empty($custom_title)) {
                $group_name = $custom_title;
            } else {
                global $wpdb;
                $group_name = $wpdb->get_var($wpdb->prepare(
                    "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                    get_option('wccg_default_group_id', 0)
                ));
            }
        }
        
        $label_html = $group_name ? sprintf(
            ' <span class="special-price-label">%s Pricing</span>',
            $this->utils->escape_output($group_name)
        ) : '';
        
        return sprintf(
            '<del>%s</del> <ins>%s</ins>%s',
            wc_price($original_price),
            wc_price($adjusted_price),
            $label_html
        );
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
        
        // For guests, use special user ID (0) to check default group pricing
        $effective_user_id = $user_id ? $user_id : 0;

        // Check request-level cache first
        $cache_key = $product->get_id() . '_' . $effective_user_id;
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

        $pricing_rule = $this->db->get_pricing_rule_for_product($product->get_id(), $effective_user_id);
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
     * Get pricing group display title for a user
     * Public method that can be used by other plugins
     *
     * @param int|null $user_id User ID (null for current user or guest)
     * @return string|null Display title or null if no pricing applies
     */
    public function get_pricing_group_display_title($user_id = null) {
        global $wpdb;
        
        // If user_id is null, use current user (0 for guests)
        if ($user_id === null) {
            $user_id = get_current_user_id();
        }
        
        // Check for explicit group assignment
        if ($user_id > 0) {
            $group_name = $this->db->get_user_group_name($user_id);
            if ($group_name) {
                return $group_name;
            }
        }
        
        // Check for default group eligibility
        $default_group_id = get_option('wccg_default_group_id', 0);
        if (!$default_group_id) {
            return null;
        }
        
        // Check if default group has active pricing rules
        $has_rule = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}pricing_rules 
            WHERE group_id = %d AND is_active = 1
            AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())",
            $default_group_id
        ));
        
        if (!$has_rule) {
            return null;
        }
        
        // Get display title (custom title or group name)
        $custom_title = get_option('wccg_default_group_custom_title', '');
        if (empty($custom_title)) {
            $custom_title = $wpdb->get_var($wpdb->prepare(
                "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                $default_group_id
            ));
        }
        
        return $custom_title ?: null;
    }

    /**
     * Display sticky banner
     */
    public function display_sticky_banner() {
        // Mark that banner has been displayed to prevent duplicates
        if (did_action('wp_body_open')) {
            $this->banner_displayed = true;
        }
        
        $this->render_sticky_banner();
    }

    /**
     * Fallback method to display sticky banner if wp_body_open wasn't called
     */
    public function display_sticky_banner_fallback() {
        // Only display if banner hasn't been shown yet
        if (empty($this->banner_displayed)) {
            $this->render_sticky_banner();
        }
    }

    /**
     * Render the actual sticky banner HTML
     */
    private function render_sticky_banner() {
        global $wpdb;
        $user_id = get_current_user_id();
        $group_id = $user_id ? $this->db->get_user_group($user_id) : null;

        // Handle users with explicit group assignment
        if ($group_id) {
            $user_info = get_userdata($user_id);
            $first_name = $user_info->first_name;

            if (empty($first_name)) {
                $first_name = $user_info->display_name;
            }

            $group_name = $this->db->get_user_group_name($user_id);

            // Check if group has an active pricing rule
            $has_rule = $wpdb->get_var($wpdb->prepare(
                "SELECT 1 FROM {$wpdb->prefix}pricing_rules 
                WHERE group_id = %d AND is_active = 1
                AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
                AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())",
                $group_id
            ));

            if ($has_rule && $group_name) {
                echo '<div class="wccg-sticky-banner">' . 
                    $this->utils->escape_output($first_name) . 
                    ', you receive <strong>' . 
                    $this->utils->escape_output($group_name) . 
                    '</strong> pricing on eligible products!</div>';
            }
            return;
        }

        // Handle ungrouped users and guests with default group
        $default_group_id = get_option('wccg_default_group_id', 0);
        if (!$default_group_id) {
            return;
        }

        // Check if default group has active pricing rules
        $has_rule = $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM {$wpdb->prefix}pricing_rules 
            WHERE group_id = %d AND is_active = 1
            AND (start_date IS NULL OR start_date <= UTC_TIMESTAMP())
            AND (end_date IS NULL OR end_date >= UTC_TIMESTAMP())",
            $default_group_id
        ));

        if (!$has_rule) {
            return;
        }

        // Get display title (custom title or group name)
        $custom_title = get_option('wccg_default_group_custom_title', '');
        if (empty($custom_title)) {
            $custom_title = $wpdb->get_var($wpdb->prepare(
                "SELECT group_name FROM {$wpdb->prefix}customer_groups WHERE group_id = %d",
                $default_group_id
            ));
        }

        if ($custom_title) {
            echo '<div class="wccg-sticky-banner">Enjoy <strong>' . 
                $this->utils->escape_output($custom_title) . 
                '</strong> pricing on eligible products!</div>';
        }
    }
}