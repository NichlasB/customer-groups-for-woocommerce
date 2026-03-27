export function initPricingRuleSchedule($) {
    const strings = (window.wccg_pricing_rules && window.wccg_pricing_rules.strings) || {};
    const $modal = $('#wccg-edit-schedule-modal');
    let $lastFocused = null;

    const getFocusableElements = () => $modal.find('button, input, select, textarea, [href], [tabindex]:not([tabindex="-1"])').filter(':visible:not([disabled])');

    const setStatusMessage = ($message, type, text) => {
        $message.attr('role', type === 'error' ? 'alert' : 'status');
        const color = type === 'error' ? '#dc3232' : (type === 'success' ? '#46b450' : '#666');
        $message.empty().append($('<span>').css('color', color).text(text));
    };

    const closeModal = () => {
        $modal.fadeOut(200, () => {
            if ($lastFocused && $lastFocused.length) {
                $lastFocused.trigger('focus');
            }
        });
        $('body').removeClass('wccg-modal-open');
        $('#wccg-edit-schedule-rule-id').val('');
        $('#wccg-edit-schedule-start').val('');
        $('#wccg-edit-schedule-end').val('');
        $('#wccg-schedule-modal-warning').hide();
        $modal.find('.wccg-schedule-modal-message').attr('role', 'status').empty();
        $modal.find('.wccg-schedule-modal-save').prop('disabled', false).text(strings.save_schedule || 'Save Schedule').removeAttr('aria-busy');
        $modal.removeAttr('aria-busy');
    };

    $(document).off('click.wccgSchedule', '.wccg-edit-schedule-btn').on('click.wccgSchedule', '.wccg-edit-schedule-btn', function() {
        const $button = $(this);

        $lastFocused = $button;
        $('#wccg-edit-schedule-rule-id').val($button.data('rule-id'));
        $('#wccg-edit-schedule-start').val($button.data('start-date') || '');
        $('#wccg-edit-schedule-end').val($button.data('end-date') || '');
        $('#wccg-schedule-modal-warning').toggle(parseInt($button.data('is-active'), 10) !== 1);
        $modal.find('.wccg-schedule-modal-message').attr('role', 'status').empty();
        $modal.fadeIn(200, () => {
            $modal.find('.wccg-modal-container').trigger('focus');
        });
        $('body').addClass('wccg-modal-open');
    });

    $(document).off('click.wccgSchedule', '.wccg-schedule-modal-close, .wccg-schedule-modal-cancel').on('click.wccgSchedule', '.wccg-schedule-modal-close, .wccg-schedule-modal-cancel', closeModal);
    $(document).off('click.wccgSchedule', '.wccg-schedule-modal-overlay').on('click.wccgSchedule', '.wccg-schedule-modal-overlay', closeModal);
    $(document).off('keydown.wccgScheduleModal').on('keydown.wccgScheduleModal', function(e) {
        if (!$modal.is(':visible')) {
            return;
        }

        if (e.key === 'Escape') {
            closeModal();
            return;
        }

        if (e.key !== 'Tab') {
            return;
        }

        const $focusable = getFocusableElements();
        if (!$focusable.length) {
            return;
        }

        const first = $focusable.get(0);
        const last = $focusable.get($focusable.length - 1);

        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
            return;
        }

        if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    });

    $(document).off('click.wccgSchedule', '.wccg-schedule-modal-save').on('click.wccgSchedule', '.wccg-schedule-modal-save', function() {
        const ruleId = $('#wccg-edit-schedule-rule-id').val();
        const $saveBtn = $(this);
        const $message = $modal.find('.wccg-schedule-modal-message');
        const startDate = $('#wccg-edit-schedule-start').val();
        const endDate = $('#wccg-edit-schedule-end').val();

        if (startDate && endDate && new Date(endDate) <= new Date(startDate)) {
            setStatusMessage($message, 'error', strings.end_date_after_start || 'End date must be after start date.');
            return;
        }

        $saveBtn.prop('disabled', true).text(strings.saving || 'Saving...').attr('aria-busy', 'true');
        setStatusMessage($message, 'info', strings.updating_schedule || 'Updating schedule...');

        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_update_rule_schedule',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId,
                start_date: startDate,
                end_date: endDate
            },
            success(response) {
                if (!response.success) {
                    setStatusMessage($message, 'error', (strings.error_prefix || 'Error:') + ' ' + response.data.message);
                    return;
                }

                const $ruleRow = $('tr[data-rule-id="' + ruleId + '"]').first();
                const $scheduleCell = $ruleRow.find('.wccg-schedule-cell');
                const $editBtn = $('.wccg-edit-schedule-btn[data-rule-id="' + ruleId + '"]');

                $scheduleCell.html(response.data.schedule_html || (response.data.schedule_badge_html + response.data.schedule_display_html));
                $editBtn.data('start-date', startDate || '');
                $editBtn.data('end-date', endDate || '');
                setStatusMessage($message, 'success', response.data.message);
                setTimeout(closeModal, 1500);
            },
            error() {
                setStatusMessage($message, 'error', strings.schedule_update_error || 'An error occurred while updating the schedule.');
            },
            complete() {
                $saveBtn.prop('disabled', false).text(strings.save_schedule || 'Save Schedule').removeAttr('aria-busy');
            }
        });
    });
}
