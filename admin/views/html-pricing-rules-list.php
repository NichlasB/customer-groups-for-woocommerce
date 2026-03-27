<?php
/**
 * Pricing rules table template.
 *
 * @package Alynt_Customer_Groups
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php require WCCG_PATH . 'admin/views/html-pricing-rules-modal.php'; ?>
<?php require WCCG_PATH . 'admin/views/html-pricing-rules-schedule-modal.php'; ?>
<?php require WCCG_PATH . 'admin/views/html-pricing-rules-toolbar.php'; ?>
<div class="wccg-pricing-rules-top-scroll" aria-hidden="true">
	<div class="wccg-pricing-rules-top-scroll-inner"></div>
</div>
<div class="wccg-pricing-rules-table-wrapper">
	<table class="wp-list-table widefat fixed striped wccg-pricing-rules-table" aria-label="<?php esc_attr_e( 'Pricing Rules', 'alynt-customer-groups' ); ?>">
		<thead>
			<tr>
				<th class="wccg-drag-handle-header" scope="col" style="width: 30px;"><span class="screen-reader-text"><?php esc_html_e( 'Reorder', 'alynt-customer-groups' ); ?></span></th>
				<th scope="col"><?php esc_html_e( 'Status', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Group Name', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Discount Type', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Discount Value', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Assigned Products', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Assigned Categories', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Schedule', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Created', 'alynt-customer-groups' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Actions', 'alynt-customer-groups' ); ?></th>
			</tr>
		</thead>
		<tbody<?php echo ! empty( $rule_order_enabled ) ? ' id="wccg-sortable-rules"' : ''; ?>>
			<?php foreach ( $pricing_rules_view as $rule ) : ?>
				<tr data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" class="wccg-sortable-row">
					<td class="wccg-drag-handle">
						<?php if ( ! empty( $rule_order_enabled ) ) : ?>
							<span class="dashicons dashicons-menu" aria-hidden="true" title="<?php esc_attr_e( 'Drag to reorder', 'alynt-customer-groups' ); ?>"></span>
							<button type="button" class="wccg-reorder-btn wccg-reorder-up screen-reader-text" data-direction="up" data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Move rule for %s up', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							"><?php esc_html_e( 'Move up', 'alynt-customer-groups' ); ?></button>
							<button type="button" class="wccg-reorder-btn wccg-reorder-down screen-reader-text" data-direction="down" data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Move rule for %s down', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							"><?php esc_html_e( 'Move down', 'alynt-customer-groups' ); ?></button>
						<?php else : ?>
							<span class="dashicons dashicons-lock" aria-hidden="true" title="<?php esc_attr_e( 'Reordering is disabled while pagination is active.', 'alynt-customer-groups' ); ?>"></span>
							<button type="button" class="screen-reader-text" disabled aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Reordering is unavailable for %s while pagination is active', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							"><?php esc_html_e( 'Reordering unavailable', 'alynt-customer-groups' ); ?></button>
						<?php endif; ?>
					</td>
					<td>
						<label class="wccg-toggle-switch">
							<input type="checkbox" class="wccg-rule-toggle" data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Enable rule for %s', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							" <?php checked( $rule['is_active'], 1 ); ?>>
							<span class="wccg-toggle-slider"></span>
						</label>
						<span class="wccg-status-text"><?php echo $rule['is_active'] ? esc_html__( 'Active', 'alynt-customer-groups' ) : esc_html__( 'Inactive', 'alynt-customer-groups' ); ?></span>
					</td>
					<td><?php echo esc_html( $rule['group_name'] ); ?></td>
					<td>
						<?php echo esc_html( ucfirst( $rule['discount_type'] ) ); ?>
						<?php if ( 'fixed' === $rule['discount_type'] ) : ?>
							<span class="dashicons dashicons-star-filled" aria-hidden="true" title="<?php esc_attr_e( 'Fixed discounts take precedence', 'alynt-customer-groups' ); ?>"></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $rule['discount_value_display'] ); ?></td>
					<td>
						<?php if ( ! empty( $rule['product_names'] ) ) : ?>
							<span class="rule-type-indicator product"><?php esc_html_e( 'Product Rule', 'alynt-customer-groups' ); ?></span><br>
							<?php echo esc_html( implode( ', ', $rule['product_names'] ) ); ?>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( ! empty( $rule['category_names'] ) ) : ?>
							<span class="rule-type-indicator category"><?php esc_html_e( 'Category Rule', 'alynt-customer-groups' ); ?></span><br>
							<?php echo esc_html( implode( ', ', $rule['category_names'] ) ); ?>
						<?php endif; ?>
					</td>
					<td class="wccg-schedule-cell <?php echo ! $rule['is_active'] ? 'wccg-schedule-inactive' : ''; ?>"><?php echo wp_kses_post( $rule['schedule_html'] ); ?></td>
					<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $rule['created_at'] ) ) ); ?></td>
					<td>
						<div class="wccg-actions-wrapper">
							<button type="button" class="wccg-edit-rule-btn" data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" title="<?php esc_attr_e( 'Edit Rule', 'alynt-customer-groups' ); ?>" aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Edit rule for %s', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							">
								<span class="dashicons dashicons-edit" aria-hidden="true"></span>
								<span class="button-text"><?php esc_html_e( 'Edit', 'alynt-customer-groups' ); ?></span>
							</button>
							<button type="button" class="wccg-edit-schedule-btn" data-rule-id="<?php echo esc_attr( $rule['rule_id'] ); ?>" data-is-active="<?php echo esc_attr( $rule['is_active'] ); ?>" data-start-date="<?php echo esc_attr( $rule['start_local'] ); ?>" data-end-date="<?php echo esc_attr( $rule['end_local'] ); ?>" title="<?php echo esc_attr( $rule['is_active'] ? __( 'Edit Schedule', 'alynt-customer-groups' ) : __( 'Note: Rule is currently inactive. Enable the toggle for schedule to take effect.', 'alynt-customer-groups' ) ); ?>" aria-label="
							<?php
							/* translators: %s: customer group name. */
							printf( esc_attr__( 'Edit schedule for %s', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
							?>
							">
								<span class="dashicons dashicons-calendar-alt" aria-hidden="true"></span>
								<span class="button-text"><?php esc_html_e( 'Schedule', 'alynt-customer-groups' ); ?></span>
							</button>
							<form method="post" class="wccg-delete-rule-form">
								<?php wp_nonce_field( 'wccg_pricing_rules_action', 'wccg_pricing_rules_nonce' ); ?>
								<input type="hidden" name="action" value="delete_rule">
								<input type="hidden" name="rule_id" value="<?php echo esc_attr( $rule['rule_id'] ); ?>">
								<button type="submit" class="button-link-delete" title="<?php esc_attr_e( 'Delete Rule', 'alynt-customer-groups' ); ?>" aria-label="
								<?php
								/* translators: %s: customer group name. */
								printf( esc_attr__( 'Delete rule for %s', 'alynt-customer-groups' ), esc_attr( $rule['group_name'] ) );
								?>
								" onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to delete this pricing rule?', 'alynt-customer-groups' ); ?>');">
									<span class="dashicons dashicons-trash" aria-hidden="true"></span>
									<span class="button-text"><?php esc_html_e( 'Delete', 'alynt-customer-groups' ); ?></span>
								</button>
							</form>
						</div>
					</td>
				</tr>
			<?php endforeach; ?>
			<?php if ( empty( $pricing_rules_view ) ) : ?>
				<tr>
					<td colspan="10" class="no-items"><?php esc_html_e( 'No pricing rules found.', 'alynt-customer-groups' ); ?></td>
				</tr>
			<?php endif; ?>
		</tbody>
	</table>
</div>
<?php if ( ! empty( $pagination ) && $pagination['total_pages'] > 1 ) : ?>
	<?php
	$pagination_links = paginate_links(
		array(
			'base'      => add_query_arg(
				array(
					'page'     => 'wccg_pricing_rules',
					'per_page' => $pagination['per_page'],
					'paged'    => '%#%',
				),
				admin_url( 'admin.php' )
			),
			'format'    => '',
			'current'   => $pagination['current_page'],
			'total'     => $pagination['total_pages'],
			'type'      => 'array',
			'prev_text' => __( '&laquo;', 'alynt-customer-groups' ),
			'next_text' => __( '&raquo;', 'alynt-customer-groups' ),
		)
	);
	?>
	<div class="tablenav bottom">
		<div class="tablenav-pages">
			<span class="displaying-num">
				<?php
				printf(
					/* translators: 1: first visible item, 2: last visible item, 3: total items. */
					esc_html__( '%1$d-%2$d of %3$d rules', 'alynt-customer-groups' ),
					(int) $pagination['from_item'],
					(int) $pagination['to_item'],
					(int) $pagination['total_items']
				);
				?>
			</span>
			<?php if ( ! empty( $pagination_links ) ) : ?>
				<span class="pagination-links">
					<?php foreach ( $pagination_links as $pagination_link ) : ?>
						<?php echo wp_kses_post( $pagination_link ); ?>
					<?php endforeach; ?>
				</span>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>
