<?php
/**
 * View helpers for the Pricing Rules admin page.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static utility methods for building schedule badge HTML and the full rules view array
 * consumed by the Pricing Rules list template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_View_Helper {

	/**
	 * Format a discount value for display in the Pricing Rules table.
	 *
	 * @since  2.0.1
	 * @param  string $discount_type  Either 'fixed' or 'percentage'.
	 * @param  mixed  $discount_value Raw discount value.
	 * @return string
	 */
	public static function format_discount_value( $discount_type, $discount_value ) {
		if ( 'percentage' === $discount_type ) {
			return $discount_value . '%';
		}

		return get_woocommerce_currency_symbol() . number_format_i18n( (float) $discount_value, wc_get_price_decimals() );
	}

	/**
	 * Fetch the name of a customer group by its ID.
	 *
	 * @since  1.0.0
	 * @param  int $group_id Customer group ID.
	 * @return string Group name, or 'Unknown Group' if the ID does not exist.
	 */
	public static function get_group_name_by_id( $group_id ) {
		$group_names = self::get_group_names_by_ids( array( $group_id ) );
		$name        = isset( $group_names[ $group_id ] ) ? $group_names[ $group_id ] : '';

		return $name ? $name : __( 'Unknown Group', 'alynt-customer-groups' );
	}

	/**
	 * Build schedule status and display HTML for a single pricing rule.
	 *
	 * @since  1.1.0
	 * @param  object $rule stdClass with start_date, end_date, and is_active properties.
	 * @return array {
	 *     @type string $status       One of 'active', 'scheduled', or 'expired'.
	 *     @type string $badge_html   Rendered HTML for the status badge span.
	 *     @type string $display_html Rendered HTML showing start/end dates in local time.
	 *     @type bool   $has_schedule Whether the rule has at least one date constraint.
	 * }
	 */
	public static function build_schedule_data( $rule ) {
		$status       = 'active';
		$badge_html   = '';
		$display_html = '';
		$now          = new DateTime( 'now', new DateTimeZone( 'UTC' ) );
		$has_schedule = ! empty( $rule->start_date ) || ! empty( $rule->end_date );

		if ( $has_schedule ) {
			$start_dt = ! empty( $rule->start_date ) ? new DateTime( $rule->start_date, new DateTimeZone( 'UTC' ) ) : null;
			$end_dt   = ! empty( $rule->end_date ) ? new DateTime( $rule->end_date, new DateTimeZone( 'UTC' ) ) : null;

			if ( $start_dt && $start_dt > $now ) {
				$status     = 'scheduled';
				$badge_html = '<span class="wccg-status-badge wccg-status-scheduled">' . esc_html__( 'Scheduled', 'alynt-customer-groups' ) . '</span>';
			} elseif ( $end_dt && $end_dt < $now ) {
				$status     = 'expired';
				$badge_html = '<span class="wccg-status-badge wccg-status-expired">' . esc_html__( 'Expired', 'alynt-customer-groups' ) . '</span>';
			} else {
				$badge_html = '<span class="wccg-status-badge wccg-status-active">' . esc_html__( 'Active', 'alynt-customer-groups' ) . '</span>';
			}

			$date_format    = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
			$schedule_parts = array();
			if ( $start_dt ) {
				$schedule_parts[] = '<strong>' . esc_html__( 'Start:', 'alynt-customer-groups' ) . '</strong> ' . esc_html( get_date_from_gmt( $rule->start_date, $date_format ) );
			}
			if ( $end_dt ) {
				$schedule_parts[] = '<strong>' . esc_html__( 'End:', 'alynt-customer-groups' ) . '</strong> ' . esc_html( get_date_from_gmt( $rule->end_date, $date_format ) );
			}
			if ( ! empty( $schedule_parts ) ) {
				$display_html = '<div class="wccg-schedule-dates">' . implode( '<br>', $schedule_parts ) . '</div>';
			}
		} else {
			$badge_html = '<span class="wccg-status-badge wccg-status-active">' . esc_html__( 'Always Active', 'alynt-customer-groups' ) . '</span>';
		}

		return array(
			'status'       => $status,
			'badge_html'   => $badge_html,
			'display_html' => $display_html,
			'has_schedule' => $has_schedule,
		);
	}

	/**
	 * Build the complete schedule cell HTML for one pricing rule row.
	 *
	 * @since  2.0.1
	 * @param  object $rule stdClass with start_date, end_date, and is_active properties.
	 * @return string
	 */
	public static function build_schedule_cell_html( $rule ) {
		$schedule_data = self::build_schedule_data( $rule );
		$html          = $schedule_data['badge_html'] . $schedule_data['display_html'];

		if ( empty( $rule->is_active ) && $schedule_data['has_schedule'] ) {
			$html .= '<div class="wccg-schedule-warning"><span class="dashicons dashicons-warning" aria-hidden="true"></span>' . esc_html__( 'Rule is inactive', 'alynt-customer-groups' ) . '</div>';
		}

		return $html;
	}

	/**
	 * Transform raw pricing rule database rows into a structured view array for the list template.
	 *
	 * @since  1.0.0
	 * @param  object[] $pricing_rules Keyed array of stdClass rule rows from WCCG_Admin_Pricing_Rules_Page.
	 * @param  array    $group_names   Optional preloaded map of group IDs to group names.
	 * @return array[] Array of associative arrays, each containing display-ready rule data.
	 */
	public static function build_pricing_rules_view( $pricing_rules, $group_names = array() ) {
		$rules_view       = array();
		$all_group_ids    = array();
		$all_product_ids  = array();
		$all_category_ids = array();

		foreach ( $pricing_rules as $rule ) {
			$all_group_ids[] = (int) $rule->group_id;
			if ( ! empty( $rule->product_ids ) ) {
				$all_product_ids = array_merge( $all_product_ids, array_map( 'intval', explode( ',', $rule->product_ids ) ) );
			}
			if ( ! empty( $rule->category_ids ) ) {
				$all_category_ids = array_merge( $all_category_ids, array_map( 'intval', explode( ',', $rule->category_ids ) ) );
			}
		}

		$group_names    = ! empty( $group_names ) ? $group_names : self::get_group_names_by_ids( $all_group_ids );
		$product_names  = self::get_product_names_by_ids( $all_product_ids );
		$category_names = self::get_category_names_by_ids( $all_category_ids );

		foreach ( $pricing_rules as $rule ) {
			$product_ids     = ! empty( $rule->product_ids ) ? array_filter( array_map( 'intval', explode( ',', $rule->product_ids ) ) ) : array();
			$category_ids    = ! empty( $rule->category_ids ) ? array_filter( array_map( 'intval', explode( ',', $rule->category_ids ) ) ) : array();
			$rule_products   = array();
			$rule_categories = array();

			foreach ( $product_ids as $product_id ) {
				if ( isset( $product_names[ $product_id ] ) ) {
					$rule_products[] = $product_names[ $product_id ];
				}
			}

			foreach ( $category_ids as $category_id ) {
				if ( isset( $category_names[ $category_id ] ) ) {
					$rule_categories[] = $category_names[ $category_id ];
				}
			}

			$is_active     = isset( $rule->is_active ) ? (int) $rule->is_active : 1;
			$schedule_rule = (object) array(
				'start_date' => $rule->start_date,
				'end_date'   => $rule->end_date,
				'is_active'  => $is_active,
			);
			$schedule_data = self::build_schedule_data( $schedule_rule );

			$rules_view[] = array(
				'rule_id'                => (int) $rule->rule_id,
				'group_name'             => isset( $group_names[ $rule->group_id ] ) ? $group_names[ $rule->group_id ] : __( 'Unknown Group', 'alynt-customer-groups' ),
				'discount_type'          => $rule->discount_type,
				'discount_value_display' => self::format_discount_value( $rule->discount_type, $rule->discount_value ),
				'product_names'          => $rule_products,
				'category_names'         => $rule_categories,
				'is_active'              => $is_active,
				'created_at'             => $rule->created_at,
				'start_date'             => $rule->start_date,
				'end_date'               => $rule->end_date,
				'schedule'               => $schedule_data,
				'schedule_html'          => self::build_schedule_cell_html( $schedule_rule ),
				'start_local'            => ! empty( $rule->start_date ) ? get_date_from_gmt( $rule->start_date, 'Y-m-d\TH:i' ) : '',
				'end_local'              => ! empty( $rule->end_date ) ? get_date_from_gmt( $rule->end_date, 'Y-m-d\TH:i' ) : '',
			);
		}

		return $rules_view;
	}

	/**
	 * Fetch customer group names keyed by group ID.
	 *
	 * @since  1.0.0
	 * @param  int[] $group_ids Customer group IDs.
	 * @return array<int,string> Group names keyed by group ID.
	 */
	public static function get_group_names_by_ids( $group_ids ) {
		global $wpdb;

		$group_ids = array_values( array_unique( array_filter( array_map( 'absint', $group_ids ) ) ) );
		if ( empty( $group_ids ) ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT group_id, group_name FROM {$wpdb->prefix}customer_groups WHERE group_id IN (" . implode( ',', array_fill( 0, count( $group_ids ), '%d' ) ) . ')',
				...$group_ids
			)
		);

		$group_names = array();
		foreach ( $results as $group ) {
			$group_names[ (int) $group->group_id ] = $group->group_name;
		}

		return $group_names;
	}

	/**
	 * Build select2-compatible product option arrays for the supplied product IDs.
	 *
	 * @since  1.0.0
	 * @param  int[] $product_ids Product IDs.
	 * @return array[] Product option arrays with id and text keys.
	 */
	public static function get_product_options_by_ids( $product_ids ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'include' => $product_ids,
				'limit'   => count( $product_ids ),
				'orderby' => 'include',
			)
		);

		$options = array();
		$currency_symbol = html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, get_bloginfo( 'charset' ) );
		foreach ( $products as $product ) {
			$options[] = array(
				'id'   => (int) $product->get_id(),
				'text' => sprintf(
					'%1$s (%2$s)',
					$product->get_name(),
					$currency_symbol . $product->get_regular_price()
				),
			);
		}

		return $options;
	}

	/**
	 * Build select2-compatible category option arrays for the supplied category IDs.
	 *
	 * @since  1.0.0
	 * @param  int[] $category_ids Product category term IDs.
	 * @return array[] Category option arrays with id and text keys.
	 */
	public static function get_category_options_by_ids( $category_ids ) {
		$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );
		if ( empty( $category_ids ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $category_ids,
				'orderby'    => 'include',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$options = array();
		foreach ( $terms as $term ) {
			$depth     = count( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) );
			$options[] = array(
				'id'   => (int) $term->term_id,
				'text' => str_repeat( '- ', $depth ) . $term->name,
			);
		}

		return $options;
	}

	/**
	 * Fetch product names keyed by product ID.
	 *
	 * @since  1.0.0
	 * @param  int[] $product_ids Product IDs.
	 * @return array<int,string> Product names keyed by product ID.
	 */
	private static function get_product_names_by_ids( $product_ids ) {
		$product_ids = array_values( array_unique( array_filter( array_map( 'absint', $product_ids ) ) ) );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$products      = wc_get_products(
			array(
				'include' => $product_ids,
				'limit'   => count( $product_ids ),
				'orderby' => 'include',
			)
		);
		$product_names = array();

		foreach ( $products as $product ) {
			$product_names[ (int) $product->get_id() ] = $product->get_name();
		}

		return $product_names;
	}

	/**
	 * Fetch category names keyed by category ID.
	 *
	 * @since  1.0.0
	 * @param  int[] $category_ids Product category term IDs.
	 * @return array<int,string> Category names keyed by category ID.
	 */
	private static function get_category_names_by_ids( $category_ids ) {
		$category_ids = array_values( array_unique( array_filter( array_map( 'absint', $category_ids ) ) ) );
		if ( empty( $category_ids ) ) {
			return array();
		}

		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'include'    => $category_ids,
				'orderby'    => 'include',
			)
		);

		if ( is_wp_error( $terms ) ) {
			return array();
		}

		$category_names = array();
		foreach ( $terms as $term ) {
			$category_names[ (int) $term->term_id ] = $term->name;
		}

		return $category_names;
	}
}
