=== Customer Groups for WooCommerce ===
Contributors: CueFox
Tags: woocommerce, customer groups, pricing, discounts, wholesale
Requires at least: 5.8
Tested up to: 6.3
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html
WC requires at least: 5.0
WC tested up to: 8.0

Create customer groups and set up custom pricing rules for different customer segments in your WooCommerce store.

== Description ==

Customer Groups for WooCommerce allows you to create custom customer groups and set up specific pricing rules for different customer segments. Perfect for implementing wholesale pricing, VIP customer discounts, or any other customer-specific pricing strategy.

= Key Features =

* Create unlimited customer groups
* Assign users to specific groups
* Set percentage or fixed amount discounts
* Apply discounts to specific products or categories
* Bulk assign users to groups
* Export customer group data to CSV
* Real-time price adjustments
* Visual price display with original and discounted prices
* Group-specific pricing notifications

= Perfect For =

* Wholesale pricing
* Member discounts
* VIP customer rewards
* Industry-specific pricing
* Volume-based discounts
* Customer loyalty programs

= Professional Features =

* Rate limiting for performance protection
* Error logging and monitoring
* Database transaction safety
* Bulk operations support
* Clean uninstallation
* Developer-friendly codebase

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/customer-groups-for-woocommerce`, or install the plugin through the WordPress plugins screen
2. Activate the plugin through the 'Plugins' screen in WordPress
3. Navigate to WooCommerce → Customer Groups to start creating groups and setting up pricing rules

= Requirements =

* WordPress 5.8 or higher
* WooCommerce 5.0 or higher
* PHP 7.4 or higher

== Usage ==

= Creating Customer Groups =

1. Go to WooCommerce → Customer Groups
2. Click "Add New Group"
3. Enter group name and description
4. Click "Add New Group" to save

= Assigning Users to Groups =

1. Go to WooCommerce → Customer Groups → User Assignments
2. Select users using checkboxes
3. Choose a group from the dropdown
4. Click "Assign Selected Users"

= Setting Up Pricing Rules =

1. Go to WooCommerce → Customer Groups → Pricing Rules
2. Select a customer group
3. Choose discount type (percentage or fixed amount)
4. Enter discount value
5. Select applicable products and/or categories
6. Click "Save Pricing Rule"

== Frequently Asked Questions ==

= Can I assign a user to multiple groups? =

No, currently each user can only belong to one group at a time. This prevents pricing conflicts and ensures consistent pricing behavior.

= How do the discounts work? =

You can set either percentage discounts (e.g., 10% off) or fixed amount discounts (e.g., $5 off). These discounts can be applied to specific products or entire categories.

= Can I export customer group data? =

Yes, you can export user assignments to CSV format from the User Assignments page.

= Is it compatible with other WooCommerce plugins? =

The plugin is built following WooCommerce best practices and should be compatible with most WooCommerce extensions. However, always test compatibility with your specific setup.

= What happens when I uninstall the plugin? =

The plugin will cleanly remove all its data, including:
* All customer groups
* User assignments
* Pricing rules
* Plugin settings
* Database tables

= How does it handle performance? =

The plugin includes:
* Rate limiting for price calculations
* Efficient database operations
* Bulk processing capabilities
* Caching considerations
* Performance logging

== Changelog ==

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 1.0.0 =
Initial release of Customer Groups for WooCommerce.

== Development ==

= Translation =

To create a new translation:

1. Copy `languages/customer-groups-for-woocommerce.pot` to `languages/customer-groups-for-woocommerce-XX_XX.po` (replace XX_XX with your locale)
2. Translate the strings in the PO file
3. Generate the MO file using a tool like Poedit
4. Place both PO and MO files in the languages directory

== Credits ==

Created by CueFox