<?php
/**
 * AJAX endpoint handlers for the Pricing Rules admin screen.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and handles all wp_ajax_wccg_* endpoints used by the Pricing Rules admin UI.
 *
 * All endpoints require the manage_woocommerce capability and verify the
 * wccg_pricing_rules_ajax nonce passed as $_POST['nonce'].
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */
class WCCG_Admin_Pricing_Rules_Ajax {
	/**
	 * Singleton instance.
	 *
	 * @var WCCG_Admin_Pricing_Rules_Ajax|null
	 */
	private static $instance = null;

	/**
	 * Database facade.
	 *
	 * @var WCCG_Database
	 */
	private $db;

	/**
	 * Shared utility helper.
	 *
	 * @var WCCG_Utilities
	 */
	private $utils;

	/**
	 * Pricing rule write service.
	 *
	 * @var WCCG_Pricing_Rule_Write_Service
	 */
	private $writer;

	/**
	 * Return the singleton instance of this class.
	 *
	 * @since  1.0.0
	 * @return WCCG_Admin_Pricing_Rules_Ajax
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Initialize the pricing rules AJAX dependencies.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	private function __construct() {
		$this->db     = WCCG_Database::instance();
		$this->utils  = WCCG_Utilities::instance();
		$this->writer = WCCG_Pricing_Rule_Write_Service::instance();
	}

	/**
	 * Register all AJAX action hooks.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_hooks() {
		add_action( 'wp_ajax_wccg_toggle_pricing_rule', array( $this, 'ajax_toggle_pricing_rule' ) );
		add_action( 'wp_ajax_wccg_delete_all_pricing_rules', array( $this, 'ajax_delete_all_pricing_rules' ) );
		add_action( 'wp_ajax_wccg_bulk_toggle_pricing_rules', array( $this, 'ajax_bulk_toggle_pricing_rules' ) );
		add_action( 'wp_ajax_wccg_reorder_pricing_rules', array( $this, 'ajax_reorder_pricing_rules' ) );
		add_action( 'wp_ajax_wccg_update_rule_schedule', array( $this, 'ajax_update_rule_schedule' ) );
		add_action( 'wp_ajax_wccg_update_pricing_rule', array( $this, 'ajax_update_pricing_rule' ) );
		add_action( 'wp_ajax_wccg_get_rule_data', array( $this, 'ajax_get_rule_data' ) );
		add_action( 'wp_ajax_wccg_search_products', array( $this, 'ajax_search_products' ) );
		add_action( 'wp_ajax_wccg_search_categories', array( $this, 'ajax_search_categories' ) );
	}

	/**
	 * Toggle one pricing rule between active and inactive states.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_toggle_pricing_rule() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$rule_id    = isset( $_POST['rule_id'] ) ? intval( wp_unslash( $_POST['rule_id'] ) ) : 0;
		$new_status = isset( $_POST['new_status'] ) ? intval( wp_unslash( $_POST['new_status'] ) ) : null;
		if ( ! $rule_id || ( $new_status !== 0 && $new_status !== 1 ) ) {
			$this->send_error( 'invalid_request', __( 'Could not update this rule. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		global $wpdb;
		$table = $this->db->get_table_name( 'pricing_rules' );
		if ( ! $this->db->pricing_rule_exists( $rule_id ) ) {
			$this->send_error( 'rule_not_found', __( 'This pricing rule no longer exists. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		$result = $this->writer->update_pricing_rule_status( $rule_id, $new_status );
		if ( $result === false ) {
			$this->log_database_error(
				'toggle_pricing_rule',
				array(
					'rule_id'    => $rule_id,
					'new_status' => $new_status,
				)
			);
			$this->send_error( 'save_failed', __( 'Could not update the rule status. Please try again.', 'alynt-customer-groups' ) );
		}

		$current_status = $this->db->get_pricing_rule_status( $rule_id );
		if ( (int) $current_status !== $new_status ) {
			$this->utils->log_error(
				'Pricing rule status verification failed.',
				array(
					'rule_id'         => $rule_id,
					'expected_status' => $new_status,
					'actual_status'   => (int) $current_status,
				)
			);
			$this->send_error( 'verification_failed', __( 'Could not confirm the new rule status. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		wp_send_json_success(
			array(
				'message'   => __( 'Pricing rule status updated.', 'alynt-customer-groups' ),
				'is_active' => (int) $current_status,
			)
		);
	}

	/**
	 * Delete all pricing rules and their related assignments.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_delete_all_pricing_rules() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$result = $this->writer->delete_all_pricing_rules();

		if ( $result !== false ) {
			wp_send_json_success( array( 'message' => __( 'All pricing rules deleted successfully.', 'alynt-customer-groups' ) ) );
		}

		$this->log_database_error( 'delete_all_pricing_rules' );
		$this->send_error( 'delete_failed', __( 'Could not delete the pricing rules. Please try again.', 'alynt-customer-groups' ) );
	}

	/**
	 * Enable or disable all pricing rules in bulk.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_bulk_toggle_pricing_rules() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$status = isset( $_POST['status'] ) ? intval( wp_unslash( $_POST['status'] ) ) : 1;
		if ( $status !== 0 && $status !== 1 ) {
			$this->send_error( 'invalid_status', __( 'Could not update the pricing rules. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		global $wpdb;
		$table  = $this->db->get_table_name( 'pricing_rules' );
		$result = $this->writer->bulk_update_pricing_rule_status( $status );
		if ( $result !== false ) {
			wp_send_json_success(
				array(
					'message'   => $status ? __( 'All pricing rules enabled successfully.', 'alynt-customer-groups' ) : __( 'All pricing rules disabled successfully.', 'alynt-customer-groups' ),
					'is_active' => $status,
				)
			);
		}

		$this->log_database_error( 'bulk_toggle_pricing_rules', array( 'status' => $status ) );
		$this->send_error( 'bulk_toggle_failed', __( 'Could not update the pricing rules. Please try again.', 'alynt-customer-groups' ) );
	}

	/**
	 * Persist a new sort order for the submitted pricing rules.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_reorder_pricing_rules() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$order = isset( $_POST['order'] ) ? array_map( 'intval', wp_unslash( (array) $_POST['order'] ) ) : array();
		if ( empty( $order ) ) {
			$this->send_error( 'missing_order', __( 'Could not save the new rule order. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		$result = $this->writer->reorder_pricing_rules( $order );
		if ( false === $result ) {
			$this->log_database_error( 'reorder_pricing_rules', array( 'order' => $order ) );
			$this->send_error( 'reorder_failed', __( 'Could not save the new rule order. Please try again.', 'alynt-customer-groups' ) );
		}

		wp_send_json_success( array( 'message' => __( 'Pricing rule order updated successfully.', 'alynt-customer-groups' ) ) );
	}

	/**
	 * Update the schedule dates for one pricing rule.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_update_rule_schedule() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? intval( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( ! $rule_id ) {
			$this->send_error( 'invalid_rule_id', __( 'Could not update this schedule. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		global $wpdb;
		$table = $this->db->get_table_name( 'pricing_rules' );
		if ( ! $this->db->pricing_rule_exists( $rule_id ) ) {
			$this->send_error( 'rule_not_found', __( 'This pricing rule no longer exists. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		$start_date = ! empty( $_POST['start_date'] ) ? $this->writer->convert_to_utc( sanitize_text_field( wp_unslash( $_POST['start_date'] ) ) ) : null;
		$end_date   = ! empty( $_POST['end_date'] ) ? $this->writer->convert_to_utc( sanitize_text_field( wp_unslash( $_POST['end_date'] ) ) ) : null;
		if ( is_wp_error( $start_date ) || is_wp_error( $end_date ) ) {
			$error = is_wp_error( $start_date ) ? $start_date : $end_date;
			$this->send_error( 'invalid_schedule', $error->get_error_message() );
		}

		if ( $start_date && $end_date && $end_date <= $start_date ) {
			$this->send_error( 'invalid_schedule', __( 'End date must be after start date.', 'alynt-customer-groups' ) );
		}

		$result = $this->writer->update_pricing_rule_schedule( $rule_id, $start_date, $end_date );
		if ( $result === false ) {
			$this->log_database_error( 'update_rule_schedule', array( 'rule_id' => $rule_id ) );
			$this->send_error( 'schedule_save_failed', __( 'Could not save the schedule. Please try again.', 'alynt-customer-groups' ) );
		}

		$schedule_data = WCCG_Admin_Pricing_Rules_View_Helper::build_schedule_data(
			(object) array(
				'start_date' => $start_date,
				'end_date'   => $end_date,
				'is_active'  => 1,
			)
		);
		wp_send_json_success(
			array(
				'message'               => __( 'Schedule updated successfully.', 'alynt-customer-groups' ),
				'schedule_status'       => $schedule_data['status'],
				'schedule_badge_html'   => $schedule_data['badge_html'],
				'schedule_display_html' => $schedule_data['display_html'],
				'schedule_html'         => WCCG_Admin_Pricing_Rules_View_Helper::build_schedule_cell_html(
					(object) array(
						'start_date' => $start_date,
						'end_date'   => $end_date,
						'is_active'  => (int) $this->db->get_pricing_rule_status( $rule_id ),
					)
				),
			)
		);
	}

	/**
	 * Load one pricing rule and its related product and category IDs.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_get_rule_data() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? intval( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( ! $rule_id ) {
			$this->send_error( 'invalid_rule_id', __( 'Could not load this pricing rule. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		global $wpdb;
		$rule = $this->db->get_pricing_rule( $rule_id );
		if ( ! $rule ) {
			$this->send_error( 'rule_not_found', __( 'This pricing rule no longer exists. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		$product_ids  = $this->db->get_pricing_rule_product_ids( $rule_id );
		$category_ids = $this->db->get_pricing_rule_category_ids( $rule_id );
		wp_send_json_success(
			array(
				'rule'             => $rule,
				'product_ids'      => array_map( 'intval', $product_ids ),
				'category_ids'     => array_map( 'intval', $category_ids ),
				'product_options'  => WCCG_Admin_Pricing_Rules_View_Helper::get_product_options_by_ids( $product_ids ),
				'category_options' => WCCG_Admin_Pricing_Rules_View_Helper::get_category_options_by_ids( $category_ids ),
			)
		);
	}

	/**
	 * Search products for remote Select2 fields on the pricing rules screen.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_search_products() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$term        = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$product_ids = get_posts(
			array(
				'post_type'              => 'product',
				'post_status'            => array( 'publish', 'private' ),
				's'                      => $term,
				'posts_per_page'         => 20,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		wp_send_json_success(
			array(
				'results' => WCCG_Admin_Pricing_Rules_View_Helper::get_product_options_by_ids( $product_ids ),
			)
		);
	}

	/**
	 * Search categories for remote Select2 fields on the pricing rules screen.
	 *
	 * @since  1.1.0
	 * @return void
	 */
	public function ajax_search_categories() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$term       = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'name__like' => $term,
				'number'     => 20,
				'orderby'    => 'name',
				'order'      => 'ASC',
				'fields'     => 'ids',
			)
		);

		if ( is_wp_error( $categories ) ) {
			$this->send_error( 'search_failed', __( 'Could not load categories. Please try again.', 'alynt-customer-groups' ) );
		}

		wp_send_json_success(
			array(
				'results' => WCCG_Admin_Pricing_Rules_View_Helper::get_category_options_by_ids( $categories ),
			)
		);
	}

	/**
	 * Update an existing pricing rule from an AJAX request.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function ajax_update_pricing_rule() {
		if ( ! check_ajax_referer( 'wccg_pricing_rules_ajax', 'nonce', false ) ) {
			$this->send_error( 'invalid_nonce', __( 'Your session has expired. Reload the page and try again.', 'alynt-customer-groups' ), 403 );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			$this->send_error( 'forbidden', __( 'You do not have permission to manage pricing rules.', 'alynt-customer-groups' ), 403 );
		}

		$rule_id = isset( $_POST['rule_id'] ) ? intval( wp_unslash( $_POST['rule_id'] ) ) : 0;
		if ( ! $rule_id ) {
			$this->send_error( 'invalid_rule_id', __( 'Could not update this pricing rule. Refresh the page and try again.', 'alynt-customer-groups' ) );
		}

		$group_id_raw       = isset( $_POST['group_id'] ) ? absint( wp_unslash( $_POST['group_id'] ) ) : 0;
		$discount_type_raw  = isset( $_POST['discount_type'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_type'] ) ) : '';
		$discount_value_raw = isset( $_POST['discount_value'] ) ? sanitize_text_field( wp_unslash( $_POST['discount_value'] ) ) : '';
		$group_id           = $this->utils->sanitize_input( $group_id_raw, 'group_id' );
		$discount_type      = $this->utils->sanitize_input( $discount_type_raw, 'discount_type' );
		$discount_value     = $this->utils->sanitize_input( $discount_value_raw, 'price' );
		if ( $group_id === 0 ) {
			$this->send_error( 'invalid_group', __( 'Select a customer group and try again.', 'alynt-customer-groups' ) );
		}

		$validation_result = $this->utils->validate_pricing_input( $discount_type, $discount_value );
		if ( ! $validation_result['valid'] ) {
			$this->send_error( 'validation_failed', $validation_result['message'] );
		}

		$product_ids  = isset( $_POST['product_ids'] ) && is_array( $_POST['product_ids'] ) ? array_filter( array_map( 'intval', wp_unslash( $_POST['product_ids'] ) ) ) : array();
		$category_ids = isset( $_POST['category_ids'] ) && is_array( $_POST['category_ids'] ) ? array_filter( array_map( 'intval', wp_unslash( $_POST['category_ids'] ) ) ) : array();
		$result       = $this->writer->update_pricing_rule( $rule_id, $group_id, $discount_type, $discount_value, $product_ids, $category_ids );
		if ( ! $result ) {
			$this->utils->log_error(
				'Pricing rule update failed.',
				array(
					'rule_id'        => $rule_id,
					'group_id'       => $group_id,
					'discount_type'  => $discount_type,
					'product_count'  => count( $product_ids ),
					'category_count' => count( $category_ids ),
				)
			);
			$this->send_error( 'update_failed', __( 'Could not update the pricing rule. Please try again.', 'alynt-customer-groups' ) );
		}

		$group_name       = WCCG_Admin_Pricing_Rules_View_Helper::get_group_name_by_id( $group_id );
		$product_options  = WCCG_Admin_Pricing_Rules_View_Helper::get_product_options_by_ids( $product_ids );
		$category_options = WCCG_Admin_Pricing_Rules_View_Helper::get_category_options_by_ids( $category_ids );
		$product_names    = array_map(
			static function ( $product_option ) {
				return preg_replace( '/\s+\([^)]*\)$/', '', $product_option['text'] );
			},
			$product_options
		);
		$category_names   = array_map(
			static function ( $category_option ) {
				return ltrim( $category_option['text'], '- ' );
			},
			$category_options
		);

		wp_send_json_success(
			array(
				'message'           => __( 'Pricing rule updated successfully.', 'alynt-customer-groups' ),
				'group_name'        => $group_name,
				'discount_type'     => ucfirst( $discount_type ),
				'discount_type_raw' => $discount_type,
				'discount_value'    => WCCG_Admin_Pricing_Rules_View_Helper::format_discount_value( $discount_type, $discount_value ),
				'product_names'     => $product_names,
				'category_names'    => $category_names,
				'product_ids'       => $product_ids,
				'category_ids'      => $category_ids,
			)
		);
	}

	/**
	 * Send a standardized JSON error response.
	 *
	 * @since  1.0.0
	 * @param  string $code        Machine-readable error code.
	 * @param  string $message     Human-readable error message.
	 * @param  int    $status_code HTTP status code.
	 * @return void
	 */
	private function send_error( $code, $message, $status_code = 400 ) {
		wp_send_json_error(
			array(
				'code'    => $code,
				'message' => $message,
			),
			$status_code
		);
	}

	/**
	 * Log the most recent database error for the current AJAX operation.
	 *
	 * @since  1.0.0
	 * @param  string $context Short identifier for the failing operation.
	 * @param  array  $data    Additional structured context to log.
	 * @return void
	 */
	private function log_database_error( $context, $data = array() ) {
		global $wpdb;

		$this->utils->log_error(
			'Pricing rules AJAX database error.',
			array_merge(
				array(
					'context'        => $context,
					'database_error' => $wpdb->last_error,
				),
				$data
			)
		);
	}
}
