jQuery(document).ready(function($) {
    // Initialize Select2 for products
    $('#product-select').select2({
        placeholder: 'Search and select products...',
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });

    // Initialize Select2 for categories
    $('#category-select').select2({
        placeholder: 'Search and select categories...',
        allowClear: true,
        width: '100%',
        closeOnSelect: false
    });

    // Handle discount type changes
    $('#discount_type').on('change', function() {
        var $value = $('#discount_value');
        if ($(this).val() === 'percentage') {
            $value.attr('max', '100');
        } else {
            $value.removeAttr('max');
        }
    });

    // Validate discount value
    $('#discount_value').on('input', function() {
        var value = parseFloat($(this).val());
        var type = $('#discount_type').val();

        if (type === 'percentage') {
            if (value < 0 || value > 100) {
                alert('Percentage discount must be between 0 and 100');
                $(this).val('');
            }
        } else if (value < 0) {
            alert('Fixed discount cannot be negative');
            $(this).val('');
        }
    });

    // Validate schedule dates
    function validateScheduleDates() {
        var startDate = $('#start_date').val();
        var endDate = $('#end_date').val();

        // If both dates are provided, validate that end is after start
        if (startDate && endDate) {
            var start = new Date(startDate);
            var end = new Date(endDate);

            if (end <= start) {
                return {
                    valid: false,
                    message: 'End date must be after start date.'
                };
            }
        }

        return { valid: true };
    }

    // Validate dates on change
    $('#start_date, #end_date').on('change', function() {
        var validation = validateScheduleDates();
        if (!validation.valid) {
            alert(validation.message);
            $(this).val('');
        }
    });

    // Validate dates on form submission
    $('.wccg-pricing-rules-form').closest('form').on('submit', function(e) {
        var validation = validateScheduleDates();
        if (!validation.valid) {
            e.preventDefault();
            alert(validation.message);
            $('#end_date').focus();
            return false;
        }
    });

    // Initialize drag-and-drop sorting for pricing rules
    if ($('#wccg-sortable-rules').length && typeof $.fn.sortable !== 'undefined') {
        $('#wccg-sortable-rules').sortable({
            handle: '.wccg-drag-handle',
            placeholder: 'wccg-sortable-placeholder',
            cursor: 'move',
            opacity: 0.8,
            helper: function(e, tr) {
                var $originals = tr.children();
                var $helper = tr.clone();
                $helper.children().each(function(index) {
                    $(this).width($originals.eq(index).width());
                });
                return $helper;
            },
            update: function(event, ui) {
                // Get the new order
                var order = [];
                $('#wccg-sortable-rules tr').each(function() {
                    var ruleId = $(this).data('rule-id');
                    if (ruleId) {
                        order.push(ruleId);
                    }
                });

                // Save the new order via AJAX
                $.ajax({
                    url: wccg_pricing_rules.ajax_url,
                    type: 'POST',
                    data: {
                        action: 'wccg_reorder_pricing_rules',
                        nonce: wccg_pricing_rules.nonce,
                        order: order
                    },
                    success: function(response) {
                        if (response.success) {
                            // Optional: Show success message
                            console.log('Rule order updated successfully');
                        } else {
                            alert('Failed to update rule order: ' + response.data.message);
                            // Revert the sort
                            $('#wccg-sortable-rules').sortable('cancel');
                        }
                    },
                    error: function() {
                        alert('An error occurred while updating the rule order');
                        // Revert the sort
                        $('#wccg-sortable-rules').sortable('cancel');
                    }
                });
            }
        });
    }

    // Edit Schedule functionality
    
    // Show edit form when Edit Schedule button is clicked
    $(document).on('click', '.wccg-edit-schedule-btn', function() {
        var ruleId = $(this).data('rule-id');
        
        // Hide all other edit forms
        $('.wccg-schedule-edit-row').hide();
        
        // Show this rule's edit form
        $('#edit-schedule-' + ruleId).show();
    });

    // Cancel edit - hide form and reset values
    $(document).on('click', '.wccg-cancel-schedule-btn', function() {
        var ruleId = $(this).data('rule-id');
        var $editRow = $('#edit-schedule-' + ruleId);
        var $editBtn = $('.wccg-edit-schedule-btn[data-rule-id="' + ruleId + '"]');
        
        // Reset to original values
        var originalStartDate = $editBtn.data('start-date');
        var originalEndDate = $editBtn.data('end-date');
        
        // Convert UTC to local for display if values exist
        if (originalStartDate) {
            // The values from data attributes are already in UTC, we need to convert for datetime-local
            $editRow.find('.wccg-edit-start-date').val(convertUTCtoLocal(originalStartDate));
        } else {
            $editRow.find('.wccg-edit-start-date').val('');
        }
        
        if (originalEndDate) {
            $editRow.find('.wccg-edit-end-date').val(convertUTCtoLocal(originalEndDate));
        } else {
            $editRow.find('.wccg-edit-end-date').val('');
        }
        
        // Clear any messages
        $editRow.find('.wccg-schedule-edit-message').html('');
        
        // Hide the form
        $editRow.hide();
    });

    // Save schedule changes
    $(document).on('click', '.wccg-save-schedule-btn', function() {
        var ruleId = $(this).data('rule-id');
        var $editRow = $('#edit-schedule-' + ruleId);
        var $saveBtn = $(this);
        var $message = $editRow.find('.wccg-schedule-edit-message');
        
        var startDate = $editRow.find('.wccg-edit-start-date').val();
        var endDate = $editRow.find('.wccg-edit-end-date').val();
        
        // Validate dates
        if (startDate && endDate) {
            var start = new Date(startDate);
            var end = new Date(endDate);
            
            if (end <= start) {
                $message.html('<span style="color: #dc3232;">End date must be after start date.</span>');
                return;
            }
        }
        
        // Disable button and show loading
        $saveBtn.prop('disabled', true).text('Saving...');
        $message.html('<span style="color: #666;">Updating schedule...</span>');
        
        // Send AJAX request
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
            success: function(response) {
                if (response.success) {
                    // Update the schedule cell in the main table
                    var $scheduleCell = $editRow.prev('tr').find('.wccg-schedule-cell');
                    $scheduleCell.html(response.data.schedule_badge_html + response.data.schedule_display_html);
                    
                    // Update data attributes on edit button for future cancels
                    var $editBtn = $('.wccg-edit-schedule-btn[data-rule-id="' + ruleId + '"]');
                    $editBtn.data('start-date', startDate ? convertLocalToUTC(startDate) : '');
                    $editBtn.data('end-date', endDate ? convertLocalToUTC(endDate) : '');
                    
                    // Show success message
                    $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    
                    // Hide form after a short delay
                    setTimeout(function() {
                        $editRow.hide();
                        $message.html('');
                    }, 1500);
                } else {
                    $message.html('<span style="color: #dc3232;">Error: ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: #dc3232;">An error occurred while updating the schedule.</span>');
            },
            complete: function() {
                // Re-enable button
                $saveBtn.prop('disabled', false).text('Save Schedule');
            }
        });
    });

    // Helper function to convert UTC datetime string to local for datetime-local input
    function convertUTCtoLocal(utcString) {
        if (!utcString) return '';
        
        // Parse UTC date
        var date = new Date(utcString + ' UTC');
        
        // Format as YYYY-MM-DDTHH:MM for datetime-local input
        var year = date.getFullYear();
        var month = String(date.getMonth() + 1).padStart(2, '0');
        var day = String(date.getDate()).padStart(2, '0');
        var hours = String(date.getHours()).padStart(2, '0');
        var minutes = String(date.getMinutes()).padStart(2, '0');
        
        return year + '-' + month + '-' + day + 'T' + hours + ':' + minutes;
    }

    // Helper function to convert local datetime to UTC string
    function convertLocalToUTC(localString) {
        if (!localString) return '';
        
        var date = new Date(localString);
        return date.toISOString().slice(0, 19).replace('T', ' ');
    }

    // =========================================================================
    // Edit Rule Modal Functionality
    // =========================================================================
    
    var $modal = $('#wccg-edit-rule-modal');
    var currentEditRuleId = null;

    // Open modal when Edit button is clicked
    $(document).on('click', '.wccg-edit-rule-btn', function() {
        var ruleId = $(this).data('rule-id');
        currentEditRuleId = ruleId;
        
        // Hide any open schedule edit rows
        $('.wccg-schedule-edit-row').hide();
        
        // Show loading state
        $modal.find('.wccg-modal-message').html('<span style="color: #666;">Loading rule data...</span>');
        $modal.find('.wccg-modal-save').prop('disabled', true);
        
        // Show modal
        $modal.fadeIn(200);
        $('body').addClass('wccg-modal-open');
        
        // Fetch rule data via AJAX
        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_get_rule_data',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId
            },
            success: function(response) {
                if (response.success) {
                    var rule = response.data.rule;
                    var productIds = response.data.product_ids;
                    var categoryIds = response.data.category_ids;
                    
                    // Populate modal fields
                    $('#wccg-edit-rule-id').val(ruleId);
                    $('#wccg-edit-group').val(rule.group_id);
                    $('#wccg-edit-discount-type').val(rule.discount_type);
                    $('#wccg-edit-discount-value').val(rule.discount_value);
                    
                    // Update max attribute based on discount type
                    if (rule.discount_type === 'percentage') {
                        $('#wccg-edit-discount-value').attr('max', '100');
                        $('.wccg-edit-discount-hint').text('Enter a percentage between 0 and 100.');
                    } else {
                        $('#wccg-edit-discount-value').removeAttr('max');
                        $('.wccg-edit-discount-hint').text('Enter the fixed discount amount.');
                    }
                    
                    // Set selected products
                    $('#wccg-edit-products').val(productIds.map(String));
                    
                    // Set selected categories
                    $('#wccg-edit-categories').val(categoryIds.map(String));
                    
                    // Clear message and enable save
                    $modal.find('.wccg-modal-message').html('');
                    $modal.find('.wccg-modal-save').prop('disabled', false);
                } else {
                    $modal.find('.wccg-modal-message').html('<span style="color: #dc3232;">Error: ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $modal.find('.wccg-modal-message').html('<span style="color: #dc3232;">Failed to load rule data.</span>');
            }
        });
    });

    // Close modal functions
    function closeModal() {
        $modal.fadeOut(200);
        $('body').removeClass('wccg-modal-open');
        currentEditRuleId = null;
        
        // Reset form
        $modal.find('.wccg-modal-message').html('');
        $modal.find('.wccg-modal-save').prop('disabled', false).text('Save Changes');
    }

    // Close modal on X click
    $(document).on('click', '.wccg-modal-close, .wccg-modal-cancel', function() {
        closeModal();
    });

    // Close modal on overlay click
    $(document).on('click', '.wccg-modal-overlay', function() {
        closeModal();
    });

    // Close modal on Escape key
    $(document).on('keydown', function(e) {
        if (e.key === 'Escape' && $modal.is(':visible')) {
            closeModal();
        }
    });

    // Handle discount type change in modal
    $(document).on('change', '#wccg-edit-discount-type', function() {
        var $valueInput = $('#wccg-edit-discount-value');
        
        if ($(this).val() === 'percentage') {
            $valueInput.attr('max', '100');
            $('.wccg-edit-discount-hint').text('Enter a percentage between 0 and 100.');
        } else {
            $valueInput.removeAttr('max');
            $('.wccg-edit-discount-hint').text('Enter the fixed discount amount.');
        }
    });

    // Validate discount value in modal
    $(document).on('input', '#wccg-edit-discount-value', function() {
        var value = parseFloat($(this).val());
        var type = $('#wccg-edit-discount-type').val();
        
        if (type === 'percentage' && value > 100) {
            $(this).val(100);
        } else if (value < 0) {
            $(this).val(0);
        }
    });

    // Save rule changes from modal
    $(document).on('click', '.wccg-modal-save', function() {
        var $saveBtn = $(this);
        var $message = $modal.find('.wccg-modal-message');
        
        var ruleId = $('#wccg-edit-rule-id').val();
        var groupId = $('#wccg-edit-group').val();
        var discountType = $('#wccg-edit-discount-type').val();
        var discountValue = $('#wccg-edit-discount-value').val();
        var productIds = $('#wccg-edit-products').val() || [];
        var categoryIds = $('#wccg-edit-categories').val() || [];
        
        // Validate
        if (!groupId) {
            $message.html('<span style="color: #dc3232;">Please select a customer group.</span>');
            return;
        }
        
        if (!discountValue || discountValue <= 0) {
            $message.html('<span style="color: #dc3232;">Please enter a valid discount value.</span>');
            return;
        }
        
        if (discountType === 'percentage' && discountValue > 100) {
            $message.html('<span style="color: #dc3232;">Percentage discount cannot exceed 100%.</span>');
            return;
        }
        
        // Disable button and show loading
        $saveBtn.prop('disabled', true).text('Saving...');
        $message.html('<span style="color: #666;">Updating rule...</span>');
        
        // Send AJAX request
        $.ajax({
            url: wccg_pricing_rules.ajax_url,
            type: 'POST',
            data: {
                action: 'wccg_update_pricing_rule',
                nonce: wccg_pricing_rules.nonce,
                rule_id: ruleId,
                group_id: groupId,
                discount_type: discountType,
                discount_value: discountValue,
                product_ids: productIds,
                category_ids: categoryIds
            },
            success: function(response) {
                if (response.success) {
                    // Update the table row with new data
                    var $ruleRow = $('tr[data-rule-id="' + ruleId + '"]').first();
                    
                    // Update group name (column index 2)
                    $ruleRow.find('td:eq(2)').text(response.data.group_name);
                    
                    // Update discount type (column index 3)
                    var discountTypeHtml = response.data.discount_type;
                    if (response.data.discount_type_raw === 'fixed') {
                        discountTypeHtml += ' <span class="dashicons dashicons-star-filled" title="Fixed discounts take precedence"></span>';
                    }
                    $ruleRow.find('td:eq(3)').html(discountTypeHtml);
                    
                    // Update discount value (column index 4) - use html() to render currency symbols
                    $ruleRow.find('td:eq(4)').html(response.data.discount_value);
                    
                    // Update products column (column index 5)
                    var productsHtml = '';
                    if (response.data.product_names.length > 0) {
                        productsHtml = '<span class="rule-type-indicator product">Product Rule</span><br>' + 
                            response.data.product_names.join(', ');
                    }
                    $ruleRow.find('td:eq(5)').html(productsHtml);
                    
                    // Update categories column (column index 6)
                    var categoriesHtml = '';
                    if (response.data.category_names.length > 0) {
                        categoriesHtml = '<span class="rule-type-indicator category">Category Rule</span><br>' + 
                            response.data.category_names.join(', ');
                    }
                    $ruleRow.find('td:eq(6)').html(categoriesHtml);
                    
                    // Show success message
                    $message.html('<span style="color: #46b450;">✓ ' + response.data.message + '</span>');
                    
                    // Close modal after a short delay
                    setTimeout(function() {
                        closeModal();
                    }, 1000);
                } else {
                    $message.html('<span style="color: #dc3232;">Error: ' + response.data.message + '</span>');
                }
            },
            error: function() {
                $message.html('<span style="color: #dc3232;">An error occurred while updating the rule.</span>');
            },
            complete: function() {
                // Re-enable button
                $saveBtn.prop('disabled', false).text('Save Changes');
            }
        });
    });
});
