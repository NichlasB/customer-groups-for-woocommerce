(function($) {
    'use strict';

    // Main admin functionality object
    const WCCG_Admin = {
        init: function() {
            this.initUserAssignments();
            this.initPricingRules();
            this.initCustomerGroups();
        },

        // User Assignments page functionality
        initUserAssignments: function() {
            if (!$('body').hasClass('customer-groups_page_wccg_user_assignments')) {
                return;
            }

            // Export CSV validation
            this.initExportValidation();

            // User selection functionality
            this.initUserSelection();

            // Search and filter functionality
            this.initSearchFilter();
        },

        initExportValidation: function() {
            $("button[name='export_csv']").off("click.wccg").on("click.wccg", function(e) {
                const checkedUsers = $("input[name='user_ids[]']:checked").length;
                if (checkedUsers === 0) {
                    e.preventDefault();
                    alert("Please select at least one user to export.");
                    return false;
                }
            });
        },

        initUserSelection: function() {
            // Remove existing handlers
            $("#select-all-users").off("click.wccg");
            $("input[name='user_ids[]']").off("click.wccg");
            $(document).off("mousedown.wccg", "input[type=checkbox]");
            
            // Select all users checkbox
            $("#select-all-users").on("click.wccg", function() {
                $("input[name='user_ids[]']").prop("checked", this.checked);
            });

            // Shift-click functionality for bulk selection
            let lastChecked = null;
            const checkboxes = $("input[name='user_ids[]']");

            checkboxes.on("click.wccg", function(e) {
                if (!lastChecked) {
                    lastChecked = this;
                    return;
                }

                if (e.shiftKey) {
                    const start = checkboxes.index(this);
                    const end = checkboxes.index(lastChecked);

                    checkboxes.slice(Math.min(start, end), Math.max(start, end) + 1)
                        .prop("checked", lastChecked.checked);
                }

                lastChecked = this;
            });

            // Prevent text selection when shift-clicking
            $(document).on("mousedown.wccg", "input[type=checkbox]", function(e) {
                if (e.shiftKey) {
                    e.preventDefault();
                }
            });
        },

        initSearchFilter: function() {
            // Remove existing handlers
            $('#group-filter, #per_page').off('change.wccg');
            $('#date-from, #date-to').off('change.wccg');
            
            // Auto-submit on select change
            $('#group-filter, #per_page').on('change.wccg', function() {
                $(this).closest('form').submit();
            });

            // Date range validation
            $('#date-from, #date-to').on('change.wccg', function() {
                const fromDate = $('#date-from').val();
                const toDate = $('#date-to').val();

                if (fromDate && toDate && fromDate > toDate) {
                    alert('From date cannot be later than To date');
                    $(this).val('');
                }
            });
        },

        // Pricing Rules page functionality
        initPricingRules: function() {
            if (!$('body').hasClass('customer-groups_page_wccg_pricing_rules')) {
                return;
            }

            this.initDiscountValidation();
            this.initProductSelection();
            this.initRuleToggle();
            this.initBulkDelete();
            this.initBulkToggle();
        },

        initDiscountValidation: function() {
            const $discountType = $('#discount_type');
            const $discountValue = $('#discount_value');

            // Remove existing handlers to prevent duplicates
            $discountValue.off('input.wccg');
            
            $discountValue.on('input.wccg', function() {
                const value = parseFloat($(this).val());
                const type = $discountType.val();

                if (type === 'percentage') {
                    if (value < 0 || value > 100) {
                        alert('Percentage discount must be between 0 and 100');
                        $(this).val('');
                    }
                } else if (type === 'fixed') {
                    if (value < 0) {
                        alert('Fixed discount cannot be negative');
                        $(this).val('');
                    }
                }
            });
        },

        initProductSelection: function() {
            // Enable search in product/category selection
            $('select[name="product_ids[]"], select[name="category_ids[]"]').select2({
                width: '100%',
                placeholder: 'Search...',
                allowClear: true
            });
        },

        initRuleToggle: function() {
            // Remove any existing handlers to prevent duplicates
            $('.wccg-rule-toggle').off('change.wccg');
            
            $('.wccg-rule-toggle').on('change.wccg', function(e) {
                const $toggle = $(this);
                
                // Ignore programmatic changes
                if ($toggle.data('programmatic-change')) {
                    $toggle.data('programmatic-change', false);
                    return;
                }
                
                // Prevent multiple simultaneous requests
                if ($toggle.data('is-updating')) {
                    e.preventDefault();
                    return false;
                }
                
                const ruleId = $toggle.data('rule-id');
                const newStatus = $toggle.prop('checked') ? 1 : 0; // Get the NEW state (what user just clicked to)
                
                // Mark as updating and disable toggle
                $toggle.data('is-updating', true);
                $toggle.prop('disabled', true);

                $.ajax({
                    url: wccg_pricing_rules.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wccg_toggle_pricing_rule',
                        nonce: wccg_pricing_rules.nonce,
                        rule_id: ruleId,
                        new_status: newStatus
                    },
                    success: function(response) {
                        console.log('Toggle response:', response);
                        if (response.success) {
                            const isActive = parseInt(response.data.is_active, 10);
                            const isChecked = (isActive === 1);
                            
                            console.log('Rule ID:', ruleId, 'Requested:', newStatus, 'Got back:', isActive, 'Setting checked to:', isChecked);
                            
                            // Find status text (re-select to ensure fresh reference)
                            const $statusText = $toggle.closest('td').find('.wccg-status-text');
                            
                            // Update status text
                            $statusText.text(isChecked ? 'Active' : 'Inactive');
                            
                            // Update checkbox (mark as programmatic change to avoid triggering event)
                            $toggle.data('programmatic-change', true);
                            $toggle.prop('checked', isChecked);
                            
                            console.log('Updated checkbox to:', $toggle.prop('checked'), 'Status text to:', $statusText.text());
                        } else {
                            console.error('Toggle failed:', response.data.message);
                            alert('Error: ' + response.data.message);
                            // Revert toggle
                            $toggle.data('programmatic-change', true);
                            $toggle.prop('checked', !$toggle.prop('checked'));
                        }
                    },
                    error: function() {
                        alert('Failed to update rule status. Please try again.');
                        // Revert toggle
                        $toggle.data('programmatic-change', true);
                        $toggle.prop('checked', !$toggle.prop('checked'));
                    },
                    complete: function() {
                        $toggle.data('is-updating', false);
                        $toggle.prop('disabled', false);
                    }
                });
            });
        },

        initBulkDelete: function() {
            // Remove existing handlers to prevent duplicates
            $('#wccg-delete-all-rules').off('click.wccg');
            
            $('#wccg-delete-all-rules').on('click.wccg', function(e) {
                e.preventDefault();
                
                // First confirmation
                if (!confirm('Are you sure you want to delete ALL pricing rules? This action cannot be undone!')) {
                    return;
                }
                
                // Second confirmation (double-check)
                if (!confirm('This will permanently delete ALL pricing rules. Are you absolutely sure?')) {
                    return;
                }

                const $button = $(this);
                $button.prop('disabled', true).text('Deleting...');

                $.ajax({
                    url: wccg_pricing_rules.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wccg_delete_all_pricing_rules',
                        nonce: wccg_pricing_rules.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert(response.data.message);
                            location.reload();
                        } else {
                            alert('Error: ' + response.data.message);
                            $button.prop('disabled', false).text('Delete All Pricing Rules');
                        }
                    },
                    error: function() {
                        alert('Failed to delete pricing rules. Please try again.');
                        $button.prop('disabled', false).text('Delete All Pricing Rules');
                    }
                });
            });
        },

        initBulkToggle: function() {
            // Remove existing handlers to prevent duplicates
            $('#wccg-enable-all-rules, #wccg-disable-all-rules').off('click.wccg');
            
            // Enable all rules
            $('#wccg-enable-all-rules').on('click.wccg', function(e) {
                e.preventDefault();
                
                const $button = $(this);
                const originalText = $button.text();
                $button.prop('disabled', true).text('Enabling...');

                $.ajax({
                    url: wccg_pricing_rules.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wccg_bulk_toggle_pricing_rules',
                        nonce: wccg_pricing_rules.nonce,
                        status: 1
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update all toggles to enabled (mark as programmatic to prevent event firing)
                            $('.wccg-rule-toggle').each(function() {
                                const $thisToggle = $(this);
                                $thisToggle.data('programmatic-change', true).prop('checked', true);
                                // Update status text for this specific toggle
                                $thisToggle.closest('td').find('.wccg-status-text').text('Active');
                            });
                            alert(response.data.message);
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                        $button.prop('disabled', false).text(originalText);
                    },
                    error: function() {
                        alert('Failed to enable pricing rules. Please try again.');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });

            // Disable all rules
            $('#wccg-disable-all-rules').on('click.wccg', function(e) {
                e.preventDefault();
                
                if (!confirm('Are you sure you want to disable all pricing rules?')) {
                    return;
                }

                const $button = $(this);
                const originalText = $button.text();
                $button.prop('disabled', true).text('Disabling...');

                $.ajax({
                    url: wccg_pricing_rules.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wccg_bulk_toggle_pricing_rules',
                        nonce: wccg_pricing_rules.nonce,
                        status: 0
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update all toggles to disabled (mark as programmatic to prevent event firing)
                            $('.wccg-rule-toggle').each(function() {
                                const $thisToggle = $(this);
                                $thisToggle.data('programmatic-change', true).prop('checked', false);
                                // Update status text for this specific toggle
                                $thisToggle.closest('td').find('.wccg-status-text').text('Inactive');
                            });
                            alert(response.data.message);
                        } else {
                            alert('Error: ' + response.data.message);
                        }
                        $button.prop('disabled', false).text(originalText);
                    },
                    error: function() {
                        alert('Failed to disable pricing rules. Please try again.');
                        $button.prop('disabled', false).text(originalText);
                    }
                });
            });
        },

        // Customer Groups page functionality
        initCustomerGroups: function() {
            if (!$('body').hasClass('toplevel_page_wccg_customer_groups')) {
                return;
            }

            // Remove existing handlers to prevent duplicates
            $('form input[name="action"][value="delete_group"]').closest('form').off('submit.wccg');
            $('input[name="group_name"]').off('input.wccg');

            // Confirm group deletion
            $('form input[name="action"][value="delete_group"]').closest('form').on('submit.wccg', function(e) {
                if (!confirm('Are you sure you want to delete this group? This action cannot be undone.')) {
                    e.preventDefault();
                    return false;
                }
            });

            // Group name validation
            $('input[name="group_name"]').on('input.wccg', function() {
                const value = $(this).val();
                if (value.length > 255) {
                    alert('Group name cannot exceed 255 characters');
                    $(this).val(value.substring(0, 255));
                }
            });
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        WCCG_Admin.init();
    });

    // Handle AJAX responses
    $(document).ajaxComplete(function(event, xhr, settings) {
        if (settings.url.indexOf('admin-ajax.php') !== -1) {
            // Don't reinitialize on our own AJAX actions (toggle, bulk operations)
            if (settings.data && typeof settings.data === 'string') {
                const isOwnAction = settings.data.indexOf('wccg_toggle_pricing_rule') !== -1 ||
                                   settings.data.indexOf('wccg_bulk_toggle_pricing_rules') !== -1 ||
                                   settings.data.indexOf('wccg_delete_all_pricing_rules') !== -1;
                if (isOwnAction) {
                    return;
                }
            }
            // Reinitialize functionality after AJAX loads
            WCCG_Admin.init();
        }
    });

})(jQuery);
