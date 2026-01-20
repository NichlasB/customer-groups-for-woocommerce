<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<!-- Edit Rule Modal -->
<div id="wccg-edit-rule-modal" class="wccg-modal" style="display: none;">
    <div class="wccg-modal-overlay"></div>
    <div class="wccg-modal-container">
        <div class="wccg-modal-header">
            <h2><?php esc_html_e('Edit Pricing Rule', 'wccg'); ?></h2>
            <button type="button" class="wccg-modal-close" aria-label="<?php esc_attr_e('Close', 'wccg'); ?>">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="wccg-modal-body">
            <input type="hidden" id="wccg-edit-rule-id" value="">
            
            <div class="wccg-modal-grid">
                <!-- Group Selection -->
                <div class="wccg-modal-field">
                    <label for="wccg-edit-group"><?php esc_html_e('Customer Group', 'wccg'); ?></label>
                    <select id="wccg-edit-group">
                        <?php foreach ($groups as $group) : ?>
                            <?php if ($group->group_name !== 'Regular Customers') : ?>
                                <option value="<?php echo esc_attr($group->group_id); ?>">
                                    <?php echo esc_html($group->group_name); ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Discount Type -->
                <div class="wccg-modal-field">
                    <label for="wccg-edit-discount-type"><?php esc_html_e('Discount Type', 'wccg'); ?></label>
                    <select id="wccg-edit-discount-type">
                        <option value="fixed"><?php esc_html_e('Fixed Amount Discount', 'wccg'); ?></option>
                        <option value="percentage"><?php esc_html_e('Percentage Discount', 'wccg'); ?></option>
                    </select>
                    <p class="description"><?php esc_html_e('Fixed amount discounts take precedence over percentage discounts.', 'wccg'); ?></p>
                </div>

                <!-- Discount Value -->
                <div class="wccg-modal-field">
                    <label for="wccg-edit-discount-value"><?php esc_html_e('Discount Value', 'wccg'); ?></label>
                    <input type="number" id="wccg-edit-discount-value" step="0.01" min="0">
                    <p class="description wccg-edit-discount-hint"><?php esc_html_e('Enter the fixed discount amount.', 'wccg'); ?></p>
                </div>
            </div>

            <div class="wccg-modal-selects">
                <!-- Products Selection -->
                <div class="wccg-modal-field">
                    <label for="wccg-edit-products"><?php esc_html_e('Assigned Products', 'wccg'); ?></label>
                    <p class="description"><?php esc_html_e('Hold Ctrl (Windows) or Cmd (Mac) to select multiple. Product rules override category rules.', 'wccg'); ?></p>
                    <select id="wccg-edit-products" multiple size="8">
                        <?php foreach ($all_products as $product) : ?>
                            <option value="<?php echo esc_attr($product->get_id()); ?>">
                                <?php echo esc_html($product->get_name()); ?>
                                (<?php echo esc_html(get_woocommerce_currency_symbol() . $product->get_regular_price()); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Categories Selection -->
                <div class="wccg-modal-field">
                    <label for="wccg-edit-categories"><?php esc_html_e('Assigned Categories', 'wccg'); ?></label>
                    <p class="description"><?php esc_html_e('Hold Ctrl (Windows) or Cmd (Mac) to select multiple. Applies to all products in selected categories.', 'wccg'); ?></p>
                    <select id="wccg-edit-categories" multiple size="8">
                        <?php foreach ($all_categories as $category) : 
                            $depth = count(get_ancestors($category->term_id, 'product_cat', 'taxonomy'));
                            $padding = str_repeat('— ', $depth);
                        ?>
                            <option value="<?php echo esc_attr($category->term_id); ?>">
                                <?php echo esc_html($padding . $category->name); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="wccg-modal-footer">
            <span class="wccg-modal-message"></span>
            <button type="button" class="button wccg-modal-cancel"><?php esc_html_e('Cancel', 'wccg'); ?></button>
            <button type="button" class="button button-primary wccg-modal-save"><?php esc_html_e('Save Changes', 'wccg'); ?></button>
        </div>
    </div>
</div>

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

<div class="wccg-pricing-rules-table-wrapper">
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
            <th><?php esc_html_e('Schedule', 'wccg'); ?></th>
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

            // Determine schedule status
            $schedule_status = 'active';
            $schedule_badge = '';
            $schedule_display = '';
            
            $now = new DateTime('now', new DateTimeZone('UTC'));
            $has_schedule = !empty($rule->start_date) || !empty($rule->end_date);
            
            if ($has_schedule) {
                $start_dt = !empty($rule->start_date) ? new DateTime($rule->start_date, new DateTimeZone('UTC')) : null;
                $end_dt = !empty($rule->end_date) ? new DateTime($rule->end_date, new DateTimeZone('UTC')) : null;
                
                // Check if scheduled for future
                if ($start_dt && $start_dt > $now) {
                    $schedule_status = 'scheduled';
                    $schedule_badge = '<span class="wccg-status-badge wccg-status-scheduled">' . 
                        esc_html__('Scheduled', 'wccg') . '</span>';
                }
                // Check if expired
                elseif ($end_dt && $end_dt < $now) {
                    $schedule_status = 'expired';
                    $schedule_badge = '<span class="wccg-status-badge wccg-status-expired">' . 
                        esc_html__('Expired', 'wccg') . '</span>';
                }
                // Active and within schedule
                else {
                    $schedule_status = 'active';
                    $schedule_badge = '<span class="wccg-status-badge wccg-status-active">' . 
                        esc_html__('Active', 'wccg') . '</span>';
                }
                
                // Format dates for display (convert from UTC to site timezone)
                $date_format = get_option('date_format') . ' ' . get_option('time_format');
                $schedule_parts = array();
                
                if ($start_dt) {
                    $start_display = get_date_from_gmt($rule->start_date, $date_format);
                    $schedule_parts[] = '<strong>' . esc_html__('Start:', 'wccg') . '</strong> ' . esc_html($start_display);
                }
                
                if ($end_dt) {
                    $end_display = get_date_from_gmt($rule->end_date, $date_format);
                    $schedule_parts[] = '<strong>' . esc_html__('End:', 'wccg') . '</strong> ' . esc_html($end_display);
                }
                
                $schedule_display = implode('<br>', $schedule_parts);
            } else {
                $schedule_badge = '<span class="wccg-status-badge wccg-status-active">' . 
                    esc_html__('Always Active', 'wccg') . '</span>';
            }

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
                <td class="wccg-schedule-cell <?php echo !$is_active ? 'wccg-schedule-inactive' : ''; ?>">
                    <?php echo $schedule_badge; ?>
                    <?php if ($schedule_display) : ?>
                        <div class="wccg-schedule-dates">
                            <?php echo $schedule_display; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!$is_active && $has_schedule) : ?>
                        <div class="wccg-schedule-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <?php esc_html_e('Rule is inactive', 'wccg'); ?>
                        </div>
                    <?php endif; ?>
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
                    <div class="wccg-actions-wrapper">
                        <button type="button" 
                            class="wccg-edit-rule-btn" 
                            data-rule-id="<?php echo esc_attr($rule->rule_id); ?>"
                            title="<?php esc_attr_e('Edit Rule', 'wccg'); ?>">
                            <span class="dashicons dashicons-edit"></span>
                            <span class="button-text"><?php esc_html_e('Edit', 'wccg'); ?></span>
                        </button>
                        <button type="button" 
                            class="wccg-edit-schedule-btn" 
                            data-rule-id="<?php echo esc_attr($rule->rule_id); ?>"
                            data-is-active="<?php echo esc_attr($is_active); ?>"
                            data-start-date="<?php echo esc_attr($rule->start_date ?? ''); ?>"
                            data-end-date="<?php echo esc_attr($rule->end_date ?? ''); ?>"
                            title="<?php 
                                if (!$is_active) {
                                    esc_attr_e('Note: Rule is currently inactive. Enable the toggle for schedule to take effect.', 'wccg');
                                } else {
                                    esc_attr_e('Edit Schedule', 'wccg');
                                }
                            ?>">
                            <span class="dashicons dashicons-calendar-alt"></span>
                            <span class="button-text"><?php esc_html_e('Schedule', 'wccg'); ?></span>
                        </button>
                        <form method="post" class="wccg-delete-rule-form">
                            <?php wp_nonce_field('wccg_pricing_rules_action', 'wccg_pricing_rules_nonce'); ?>
                            <input type="hidden" name="action" value="delete_rule">
                            <input type="hidden" name="rule_id" value="<?php echo esc_attr($rule->rule_id); ?>">
                            <button type="submit" 
                                class="button-link-delete" 
                                title="<?php esc_attr_e('Delete Rule', 'wccg'); ?>"
                                onclick="return confirm('<?php esc_attr_e('Are you sure you want to delete this pricing rule?', 'wccg'); ?>');">
                                <span class="dashicons dashicons-trash"></span>
                                <span class="button-text"><?php esc_html_e('Delete', 'wccg'); ?></span>
                            </button>
                        </form>
                    </div>
                </td>
        </tr>
        <!-- Inline Edit Schedule Form (Hidden by default) -->
        <tr class="wccg-schedule-edit-row" id="edit-schedule-<?php echo esc_attr($rule->rule_id); ?>" style="display: none;">
            <td colspan="10">
                <div class="wccg-schedule-edit-form">
                    <h4><?php esc_html_e('Edit Schedule', 'wccg'); ?></h4>
                    <?php if (!$is_active) : ?>
                        <div class="wccg-inactive-rule-warning">
                            <span class="dashicons dashicons-warning"></span>
                            <strong><?php esc_html_e('Warning:', 'wccg'); ?></strong>
                            <?php esc_html_e('This rule is currently inactive. The schedule will not take effect until you enable the rule using the toggle switch.', 'wccg'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="wccg-edit-schedule-fields">
                        <div class="wccg-edit-field">
                            <label for="edit-start-date-<?php echo esc_attr($rule->rule_id); ?>">
                                <?php esc_html_e('Start Date & Time:', 'wccg'); ?>
                            </label>
                            <input type="datetime-local" 
                                id="edit-start-date-<?php echo esc_attr($rule->rule_id); ?>"
                                class="wccg-edit-start-date"
                                value="<?php 
                                    if (!empty($rule->start_date)) {
                                        // Convert from UTC to site timezone for display
                                        $start_local = get_date_from_gmt($rule->start_date, 'Y-m-d\TH:i');
                                        echo esc_attr($start_local);
                                    }
                                ?>">
                        </div>
                        <div class="wccg-edit-field">
                            <label for="edit-end-date-<?php echo esc_attr($rule->rule_id); ?>">
                                <?php esc_html_e('End Date & Time:', 'wccg'); ?>
                            </label>
                            <input type="datetime-local" 
                                id="edit-end-date-<?php echo esc_attr($rule->rule_id); ?>"
                                class="wccg-edit-end-date"
                                value="<?php 
                                    if (!empty($rule->end_date)) {
                                        // Convert from UTC to site timezone for display
                                        $end_local = get_date_from_gmt($rule->end_date, 'Y-m-d\TH:i');
                                        echo esc_attr($end_local);
                                    }
                                ?>">
                        </div>
                    </div>
                    <div class="wccg-edit-schedule-actions">
                        <button type="button" class="button button-primary wccg-save-schedule-btn" data-rule-id="<?php echo esc_attr($rule->rule_id); ?>">
                            <?php esc_html_e('Save Schedule', 'wccg'); ?>
                        </button>
                        <button type="button" class="button wccg-cancel-schedule-btn" data-rule-id="<?php echo esc_attr($rule->rule_id); ?>">
                            <?php esc_html_e('Cancel', 'wccg'); ?>
                        </button>
                        <span class="wccg-schedule-edit-message"></span>
                    </div>
                    <p class="description">
                        <?php 
                        printf(
                            esc_html__('Leave fields blank to remove schedule restrictions. Times are in %s timezone.', 'wccg'),
                            '<code>' . esc_html(wp_timezone_string()) . '</code>'
                        );
                        ?>
                    </p>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
    <?php if (empty($pricing_rules)) : ?>
        <tr>
            <td colspan="10" class="no-items">
                <?php esc_html_e('No pricing rules found.', 'wccg'); ?>
            </td>
        </tr>
    <?php endif; ?>
</tbody>
</table>
</div>