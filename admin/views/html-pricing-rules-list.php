<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div style="margin-bottom: 15px; display: flex; gap: 10px; align-items: center;">
    <?php if (!empty($pricing_rules)) : ?>
        <button type="button" id="wccg-enable-all-rules" class="button button-secondary">
            <?php esc_html_e('Enable All Rules', 'wccg'); ?>
        </button>
        <button type="button" id="wccg-disable-all-rules" class="button button-secondary">
            <?php esc_html_e('Disable All Rules', 'wccg'); ?>
        </button>
        <span style="margin-left: 10px; border-left: 1px solid #ccc; padding-left: 20px;"></span>
        <button type="button" id="wccg-delete-all-rules" class="button button-secondary" style="color: #a00;">
            <?php esc_html_e('Delete All Pricing Rules', 'wccg'); ?>
        </button>
    <?php endif; ?>
</div>

<table class="wp-list-table widefat fixed striped wccg-pricing-rules-table">
    <thead>
        <tr>
            <th class="wccg-drag-handle-header" style="width: 30px;"></th>
            <th><?php esc_html_e('Status', 'wccg'); ?></th>
            <th><?php esc_html_e('Group Name', 'wccg'); ?></th>
            <th><?php esc_html_e('Discount Type', 'wccg'); ?></th>
            <th><?php esc_html_e('Discount Value', 'wccg'); ?></th>
            <th><?php esc_html_e('Assigned Products', 'wccg'); ?></th>
            <th><?php esc_html_e('Assigned Categories', 'wccg'); ?></th>
            <th><?php esc_html_e('Created', 'wccg'); ?></th>
            <th><?php esc_html_e('Actions', 'wccg'); ?></th>
        </tr>
    </thead>
    <tbody id="wccg-sortable-rules">
        <?php foreach ($pricing_rules as $rule) : ?>
            <?php
            // Get group name
            $group_name = $this->get_group_name($rule->group_id);

            // Get assigned products
            $product_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT product_id FROM {$wpdb->prefix}rule_products WHERE rule_id = %d",
                $rule->rule_id
            ));
            $product_names = array();
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if ($product) {
                    $product_names[] = $product->get_name();
                }
            }

            // Get assigned categories
            $category_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT category_id FROM {$wpdb->prefix}rule_categories WHERE rule_id = %d",
                $rule->rule_id
            ));
            $category_names = array();
            foreach ($category_ids as $category_id) {
                $category = get_term($category_id, 'product_cat');
                if ($category && !is_wp_error($category)) {
                    $category_names[] = $category->name;
                }
            }

            // Format discount value
            $discount_value = $rule->discount_type === 'percentage' 
            ? $rule->discount_value . '%'
            : get_woocommerce_currency_symbol() . $rule->discount_value;

            // Get is_active status (default to 1 for backward compatibility)
            $is_active = isset($rule->is_active) ? (int)$rule->is_active : 1;

            ?>
            <tr data-rule-id="<?php echo esc_attr($rule->rule_id); ?>" class="wccg-sortable-row">
                <td class="wccg-drag-handle">
                    <span class="dashicons dashicons-menu" title="<?php esc_attr_e('Drag to reorder', 'wccg'); ?>"></span>
                </td>
                <td>
                    <label class="wccg-toggle-switch">
                        <input type="checkbox" 
                            class="wccg-rule-toggle" 
                            data-rule-id="<?php echo esc_attr($rule->rule_id); ?>"
                            <?php checked($is_active, 1); ?>>
                        <span class="wccg-toggle-slider"></span>
                    </label>
                    <span class="wccg-status-text">
                        <?php echo $is_active ? esc_html__('Active', 'wccg') : esc_html__('Inactive', 'wccg'); ?>
                    </span>
                </td>
                <td><?php echo esc_html($group_name); ?></td>
                <td>
                    <?php 
                    echo esc_html(ucfirst($rule->discount_type));
                    if ($rule->discount_type === 'fixed') {
                        echo ' <span class="dashicons dashicons-star-filled" title="' . 
                        esc_attr__('Fixed discounts take precedence', 'wccg') . 
                        '"></span>';
                    }
                    ?>
                </td>
                <td><?php echo esc_html($discount_value); ?></td>
                <td>
                    <?php 
                    if (!empty($product_names)) {
                        echo '<span class="rule-type-indicator product">' . 
                        esc_html__('Product Rule', 'wccg') . '</span><br>';
                        echo esc_html(implode(', ', $product_names));
                    }
                    ?>
                </td>
                <td>
                    <?php 
                    if (!empty($category_names)) {
                        echo '<span class="rule-type-indicator category">' . 
                        esc_html__('Category Rule', 'wccg') . '</span><br>';
                        echo esc_html(implode(', ', $category_names));
                    }
                    ?>
                </td>
                <td>
                    <?php echo esc_html(
                        date_i18n(
                            get_option('date_format') . ' ' . get_option('time_format'),
                            strtotime($rule->created_at)
                        )
                    ); ?>
                </td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('wccg_pricing_rules_action', 'wccg_pricing_rules_nonce'); ?>
                        <input type="hidden" name="action" value="delete_rule">
                        <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->rule_id); ?>">
                        <button type="submit" 
                        class="button button-link-delete" 
                        onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this pricing rule?', 'wccg'); ?>');">
                        <?php esc_html_e('Delete', 'wccg'); ?>
                    </button>
                </form>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($pricing_rules)) : ?>
        <tr>
            <td colspan="9" class="no-items">
                <?php esc_html_e('No pricing rules found.', 'wccg'); ?>
            </td>
        </tr>
    <?php endif; ?>
</tbody>
</table>