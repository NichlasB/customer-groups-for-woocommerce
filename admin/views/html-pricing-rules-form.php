<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wccg-pricing-rules-form">
    <h2><?php esc_html_e('Select Customer Group', 'wccg'); ?></h2>
    <select name="group_id" required>
        <?php foreach ($groups as $group) : ?>
            <?php if ($group->group_name !== 'Regular Customers') : ?>
                <option value="<?php echo esc_attr($group->group_id); ?>">
                    <?php echo esc_html($group->group_name); ?>
                </option>
            <?php endif; ?>
        <?php endforeach; ?>
    </select>

    <h2><?php esc_html_e('Discount Settings', 'wccg'); ?></h2>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="discount_type"><?php esc_html_e('Discount Type', 'wccg'); ?></label>
            </th>
            <td>
                <select name="discount_type" id="discount_type" required>
                    <option value="fixed"><?php esc_html_e('Fixed Amount Discount', 'wccg'); ?></option>
                    <option value="percentage"><?php esc_html_e('Percentage Discount', 'wccg'); ?></option>
                </select>
                <p class="description">
                    <?php esc_html_e('Fixed amount discounts take precedence over percentage discounts.', 'wccg'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="discount_value"><?php esc_html_e('Discount Value', 'wccg'); ?></label>
            </th>
            <td>
                <input name="discount_value" 
                    type="number" 
                    step="0.01" 
                    id="discount_value" 
                    required>
                <p class="description discount-type-hint fixed">
                    <?php esc_html_e('Enter the fixed discount amount in your store\'s currency.', 'wccg'); ?>
                </p>
                <p class="description discount-type-hint percentage" style="display:none;">
                    <?php esc_html_e('Enter a percentage between 0 and 100.', 'wccg'); ?>
                </p>
            </td>
        </tr>
    </table>

    <h2><?php esc_html_e('Schedule (Optional)', 'wccg'); ?></h2>
    <table class="form-table">
        <tr>
            <th scope="row">
                <label for="start_date"><?php esc_html_e('Start Date & Time', 'wccg'); ?></label>
            </th>
            <td>
                <input name="start_date" 
                    type="datetime-local" 
                    id="start_date">
                <p class="description">
                    <?php esc_html_e('When should this pricing rule become active? Leave blank for immediate activation.', 'wccg'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <th scope="row">
                <label for="end_date"><?php esc_html_e('End Date & Time', 'wccg'); ?></label>
            </th>
            <td>
                <input name="end_date" 
                    type="datetime-local" 
                    id="end_date">
                <p class="description">
                    <?php esc_html_e('When should this pricing rule expire? Leave blank for no expiration.', 'wccg'); ?>
                </p>
            </td>
        </tr>
        <tr>
            <td colspan="2">
                <p class="description">
                    <strong><?php esc_html_e('Note:', 'wccg'); ?></strong>
                    <?php 
                    $timezone_string = wp_timezone_string();
                    printf(
                        esc_html__('Times are based on your site timezone: %s. Leave both fields blank for the rule to be always active.', 'wccg'),
                        '<code>' . esc_html($timezone_string) . '</code>'
                    );
                    ?>
                </p>
            </td>
        </tr>
    </table>

    <h2><?php esc_html_e('Select Products', 'wccg'); ?></h2>
    <div class="wccg-selection-section">
        <p class="description">
            <?php esc_html_e('Product-specific rules override category rules. Hold down Ctrl (Windows) or Command (Mac) to select multiple items.', 'wccg'); ?>
        </p>
        <select name="product_ids[]" 
            multiple 
            class="wccg-native-select"
            size="10">
            <?php 
            $products = wc_get_products(array(
                'limit' => -1,
                'orderby' => 'title',
                'order' => 'ASC'
            ));
            foreach ($products as $product) : 
                ?>
                <option value="<?php echo esc_attr($product->get_id()); ?>">
                    <?php echo esc_html($product->get_name()); ?>
                    (<?php echo esc_html(get_woocommerce_currency_symbol() . $product->get_regular_price()); ?>)
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <h2><?php esc_html_e('Select Categories', 'wccg'); ?></h2>
    <div class="wccg-selection-section">
        <p class="description">
            <?php esc_html_e('Category rules apply to all products in selected categories, including child categories.', 'wccg'); ?>
        </p>
        <select name="category_ids[]" 
            multiple 
            class="wccg-native-select"
            size="10">
            <?php 
            $categories = get_terms(array(
                'taxonomy' => 'product_cat',
                'hide_empty' => false,
                'orderby' => 'name',
                'order' => 'ASC'
            ));
            foreach ($categories as $category) : 
                $depth = count(get_ancestors($category->term_id, 'product_cat', 'taxonomy'));
                $padding = str_repeat('— ', $depth);
                ?>
                <option value="<?php echo esc_attr($category->term_id); ?>">
                    <?php echo esc_html($padding . $category->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php submit_button(__('Save Pricing Rule', 'wccg')); ?>
</div>