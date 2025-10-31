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
});
