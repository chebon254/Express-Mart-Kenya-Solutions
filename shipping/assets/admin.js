jQuery(document).ready(function($) {
    'use strict';
    
    // Handle form submission via AJAX (optional enhancement)
    $('#pickup-station-form').on('submit', function(e) {
        e.preventDefault();
        
        var form = $(this);
        var submitBtn = form.find('input[type="submit"]');
        var originalText = submitBtn.val();
        
        // Validate required fields
        var hasErrors = false;
        form.find('input[required], textarea[required]').each(function() {
            if (!$(this).val().trim()) {
                $(this).addClass('error');
                hasErrors = true;
            } else {
                $(this).removeClass('error');
            }
        });
        
        if (hasErrors) {
            alert(pickup_admin_ajax.i18n.fill_required_fields || 'Please fill in all required fields.');
            return false;
        }
        
        // Show loading state
        submitBtn.val(pickup_admin_ajax.i18n.saving || 'Saving...').prop('disabled', true);
        
        $.ajax({
            url: pickup_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'save_pickup_station',
                nonce: pickup_admin_ajax.nonce,
                station_name: $('#station_name').val(),
                station_address: $('#station_address').val(),
                station_city: $('#station_city').val(),
                station_phone: $('#station_phone').val(),
                shipping_price: $('#shipping_price').val()
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice(pickup_admin_ajax.i18n.success_added, 'success');
                    
                    // Reset form
                    form[0].reset();
                    
                    // Reload page to show new station in list
                    setTimeout(function() {
                        location.reload();
                    }, 1500);
                } else {
                    showNotice(response.data || pickup_admin_ajax.i18n.error_occurred, 'error');
                }
            },
            error: function() {
                showNotice(pickup_admin_ajax.i18n.error_occurred, 'error');
            },
            complete: function() {
                // Restore button state
                submitBtn.val(originalText).prop('disabled', false);
            }
        });
    });
    
    // Handle delete button clicks
    $('.delete-station').on('click', function(e) {
        e.preventDefault();
        
        if (!confirm(pickup_admin_ajax.i18n.confirm_delete)) {
            return false;
        }
        
        var button = $(this);
        var stationId = button.data('id');
        var row = button.closest('tr');
        var originalText = button.text();
        
        // Show loading state
        button.text(pickup_admin_ajax.i18n.deleting || 'Deleting...').prop('disabled', true);
        
        $.ajax({
            url: pickup_admin_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'delete_pickup_station',
                nonce: pickup_admin_ajax.nonce,
                station_id: stationId
            },
            success: function(response) {
                if (response.success) {
                    // Show success message
                    showNotice(pickup_admin_ajax.i18n.success_deleted, 'success');
                    
                    // Remove row with animation
                    row.fadeOut(300, function() {
                        $(this).remove();
                        
                        // Check if table is empty
                        if ($('.pickup-stations-list tbody tr').length === 0) {
                            $('.pickup-stations-list').html('<p>' + 
                                (pickup_admin_ajax.i18n.no_stations || 'No pickup stations found. Add your first station above.') + 
                                '</p>');
                        }
                    });
                } else {
                    showNotice(response.data || pickup_admin_ajax.i18n.error_occurred, 'error');
                    button.text(originalText).prop('disabled', false);
                }
            },
            error: function() {
                showNotice(pickup_admin_ajax.i18n.error_occurred, 'error');
                button.text(originalText).prop('disabled', false);
            }
        });
    });
    
    // Form validation helpers
    $('input[required], textarea[required]').on('blur', function() {
        if (!$(this).val().trim()) {
            $(this).addClass('error');
        } else {
            $(this).removeClass('error');
        }
    });
    
    // Price field validation
    $('#shipping_price').on('input', function() {
        var value = parseFloat($(this).val());
        if (isNaN(value) || value < 0) {
            $(this).addClass('error');
        } else {
            $(this).removeClass('error');
        }
    });
    
    // Phone field formatting (basic)
    $('#station_phone').on('input', function() {
        var value = $(this).val().replace(/[^\d\s\-\+\(\)]/g, '');
        $(this).val(value);
    });
    
    // Helper function to show notices
    function showNotice(message, type) {
        type = type || 'info';
        
        // Remove existing notices
        $('.pickup-admin-notice').remove();
        
        // Create new notice
        var notice = $('<div class="notice notice-' + type + ' is-dismissible pickup-admin-notice">' +
                      '<p>' + message + '</p>' +
                      '<button type="button" class="notice-dismiss">' +
                      '<span class="screen-reader-text">Dismiss this notice.</span>' +
                      '</button>' +
                      '</div>');
        
        // Add to page
        $('.wrap h1').after(notice);
        
        // Handle dismiss button
        notice.find('.notice-dismiss').on('click', function() {
            notice.fadeOut(300, function() {
                $(this).remove();
            });
        });
        
        // Auto-dismiss success messages
        if (type === 'success') {
            setTimeout(function() {
                notice.fadeOut(300, function() {
                    $(this).remove();
                });
            }, 5000);
        }
        
        // Scroll to notice
        $('html, body').animate({
            scrollTop: notice.offset().top - 50
        }, 300);
    }
    
    // Add some loading states and better UX
    $(document).on('ajaxStart', function() {
        $('body').addClass('pickup-loading');
    }).on('ajaxStop', function() {
        $('body').removeClass('pickup-loading');
    });
});