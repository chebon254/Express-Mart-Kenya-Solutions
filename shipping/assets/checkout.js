jQuery(document).ready(function($) {
    'use strict';
    
    // Handle pickup station selection
    $(document.body).on('change', '#pickup_station', function() {
        var selectedStation = $(this).val();
        var detailsContainer = $('#pickup-station-details');
        
        // Clear previous details
        detailsContainer.html('');
        
        if (selectedStation) {
            // Show loading
            detailsContainer.html('<p>' + pickup_station_ajax.i18n.loading + '</p>');
            
            // Get station details via AJAX
            $.ajax({
                url: pickup_station_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'get_pickup_station_details',
                    nonce: pickup_station_ajax.nonce,
                    station_id: selectedStation
                },
                success: function(response) {
                    if (response.success) {
                        var station = response.data;
                        var html = '<div class="pickup-station-info">' +
                                  '<h4>' + station.name + '</h4>' +
                                  '<p><strong>' + pickup_station_ajax.i18n.address + ':</strong> ' + station.address + '</p>' +
                                  '<p><strong>' + pickup_station_ajax.i18n.city + ':</strong> ' + station.city + '</p>' +
                                  '<p><strong>' + pickup_station_ajax.i18n.phone + ':</strong> ' + station.phone + '</p>' +
                                  '<p><strong>' + pickup_station_ajax.i18n.shipping_cost + ':</strong> ' + station.price + '</p>' +
                                  '</div>';
                        detailsContainer.html(html);
                    } else {
                        detailsContainer.html('<p class="pickup-error">' + pickup_station_ajax.i18n.error_loading + '</p>');
                    }
                },
                error: function() {
                    detailsContainer.html('<p class="pickup-error">' + pickup_station_ajax.i18n.error_loading + '</p>');
                }
            });
            
            // Trigger checkout update to recalculate shipping
            $('body').trigger('update_checkout');
        }
    });
    
    // Handle checkout validation
    $(document.body).on('checkout_error', function() {
        var pickupField = $('#pickup_station');
        if (pickupField.length && !pickupField.val()) {
            pickupField.closest('.form-row').addClass('woocommerce-invalid');
        }
    });
    
    // Clear validation errors when field is changed
    $(document.body).on('change', '#pickup_station', function() {
        $(this).closest('.form-row').removeClass('woocommerce-invalid woocommerce-invalid-required-field');
    });
    
    // Ensure pickup station is selected before allowing checkout
    $(document.body).on('click', '#place_order', function(e) {
        var pickupField = $('#pickup_station');
        if (pickupField.length && pickupField.is(':visible') && !pickupField.val()) {
            e.preventDefault();
            pickupField.focus();
            
            // Show error message
            $('.woocommerce-error, .woocommerce-message').remove();
            var errorHtml = '<ul class="woocommerce-error" role="alert"><li>' + 
                           pickup_station_ajax.i18n.select_station + '</li></ul>';
            $('.woocommerce-notices-wrapper').first().html(errorHtml);
            
            $('html, body').animate({
                scrollTop: $('.woocommerce-notices-wrapper').first().offset().top - 100
            }, 500);
            
            return false;
        }
    });
});