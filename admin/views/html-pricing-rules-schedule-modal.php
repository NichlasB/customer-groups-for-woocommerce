<?php
/**
 * Pricing rule schedule modal template.
 *
 * @package Alynt_Customer_Groups
 * @since   2.0.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div id="wccg-edit-schedule-modal" class="wccg-modal" style="display: none;" role="dialog" aria-modal="true" aria-labelledby="wccg-schedule-modal-title">
	<div class="wccg-modal-overlay wccg-schedule-modal-overlay"></div>
	<div class="wccg-modal-container wccg-modal-container--schedule" tabindex="-1">
		<div class="wccg-modal-header">
			<h2 id="wccg-schedule-modal-title"><?php esc_html_e( 'Edit Schedule', 'alynt-customer-groups' ); ?></h2>
			<button type="button" class="wccg-schedule-modal-close" aria-label="<?php esc_attr_e( 'Close', 'alynt-customer-groups' ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>
		<div class="wccg-modal-body">
			<input type="hidden" id="wccg-edit-schedule-rule-id" value="">
			<div class="wccg-inactive-rule-warning" id="wccg-schedule-modal-warning" style="display: none;">
				<span class="dashicons dashicons-warning" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Warning:', 'alynt-customer-groups' ); ?></strong>
				<?php esc_html_e( 'This rule is currently inactive. The schedule will not take effect until you enable the rule using the toggle switch.', 'alynt-customer-groups' ); ?>
			</div>
			<div class="wccg-modal-grid wccg-modal-grid--schedule">
				<div class="wccg-edit-field">
					<label for="wccg-edit-schedule-start"><?php esc_html_e( 'Start Date & Time', 'alynt-customer-groups' ); ?></label>
					<input type="datetime-local" id="wccg-edit-schedule-start">
				</div>
				<div class="wccg-edit-field">
					<label for="wccg-edit-schedule-end"><?php esc_html_e( 'End Date & Time', 'alynt-customer-groups' ); ?></label>
					<input type="datetime-local" id="wccg-edit-schedule-end">
				</div>
			</div>
			<p class="description">
				<?php
				/* translators: %s: WordPress timezone string wrapped in code tags. */
				printf( esc_html__( 'Leave fields blank to remove schedule restrictions. Times are in %s timezone.', 'alynt-customer-groups' ), '<code>' . esc_html( wp_timezone_string() ) . '</code>' );
				?>
			</p>
		</div>
		<div class="wccg-modal-footer">
			<span class="wccg-save-status wccg-schedule-modal-message" role="status" aria-live="polite" aria-atomic="true"></span>
			<button type="button" class="button wccg-schedule-modal-cancel"><?php esc_html_e( 'Cancel', 'alynt-customer-groups' ); ?></button>
			<button type="button" class="button button-primary wccg-schedule-modal-save"><?php esc_html_e( 'Save Schedule', 'alynt-customer-groups' ); ?></button>
		</div>
	</div>
</div>
