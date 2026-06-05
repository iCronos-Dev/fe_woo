/**
 * FE WooCommerce Admin JavaScript
 *
 * @package FE_Woo
 */

(function($) {
    'use strict';

    /**
     * Initialize admin functionality
     */
    function init() {
        initConnectionTest();
        initFormValidation();
        initEnvironmentAutoFill();
    }

    /**
     * Initialize connection test functionality
     */
    function initConnectionTest() {
        const $testButton = $('#fe-woo-test-connection');
        const $resultSpan = $('#fe-woo-connection-result');

        if (!$testButton.length) {
            return;
        }

        $testButton.on('click', function(e) {
            e.preventDefault();

            // Disable button and show loading state
            $testButton.prop('disabled', true);
            $resultSpan
                .removeClass('success error')
                .addClass('testing')
                .html('<span class="fe-woo-spinner"></span> ' + feWooAdmin.strings.testing);

            // Make AJAX request
            $.ajax({
                url: feWooAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_test_connection',
                    nonce: feWooAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $resultSpan
                            .removeClass('testing error')
                            .addClass('success')
                            .text('✓ ' + feWooAdmin.strings.success);
                    } else {
                        const errorMessage = response.data && response.data.message
                            ? response.data.message
                            : feWooAdmin.strings.error;

                        $resultSpan
                            .removeClass('testing success')
                            .addClass('error')
                            .text('✗ ' + errorMessage);
                    }
                },
                error: function(xhr, status, error) {
                    $resultSpan
                        .removeClass('testing success')
                        .addClass('error')
                        .text('✗ ' + feWooAdmin.strings.error + ' (' + error + ')');
                },
                complete: function() {
                    // Re-enable button
                    $testButton.prop('disabled', false);

                    // Clear result after 10 seconds
                    setTimeout(function() {
                        $resultSpan
                            .removeClass('testing success error')
                            .text('');
                    }, 10000);
                }
            });
        });
    }

    /**
     * Initialize form validation
     */
    function initFormValidation() {
        const $cedulaField = $('#' + 'fe_woo_cedula_juridica');

        if ($cedulaField.length) {
            $cedulaField.on('input', function() {
                // Remove non-numeric characters
                const value = $(this).val().replace(/\D/g, '');
                $(this).val(value);
            });
        }

        // Certificate file validation
        const $certFile = $('#fe_woo_certificate_file');

        if ($certFile.length) {
            $certFile.on('change', function() {
                const file = this.files[0];

                if (!file) {
                    return;
                }

                const validExtensions = ['p12', 'pfx'];
                const fileExtension = file.name.split('.').pop().toLowerCase();

                if (!validExtensions.includes(fileExtension)) {
                    alert('Invalid file type. Please upload a .p12 or .pfx file.');
                    $(this).val('');
                    return;
                }

                const maxSize = 5 * 1024 * 1024; // 5MB

                if (file.size > maxSize) {
                    alert('File size exceeds maximum allowed (5MB).');
                    $(this).val('');
                    return;
                }
            });
        }

        // Form submission validation
        $('form').on('submit', function() {
            const $form = $(this);

            // Check if we're on the FE settings tab
            if (!$('#fe_woo_api_section_title').length) {
                return true;
            }

            // Validate required fields
            let isValid = true;
            const requiredFields = [
                'fe_woo_cedula_juridica',
                'fe_woo_company_name',
                'fe_woo_api_username',
                'fe_woo_api_password',
                'fe_woo_economic_activity'
            ];

            requiredFields.forEach(function(fieldId) {
                const $field = $('#' + fieldId);

                if ($field.length && !$field.val().trim()) {
                    $field.css('border-color', '#d63638');
                    isValid = false;

                    // Scroll to first invalid field
                    if (isValid === false) {
                        $('html, body').animate({
                            scrollTop: $field.offset().top - 100
                        }, 500);
                    }
                } else {
                    $field.css('border-color', '');
                }
            });

            if (!isValid) {
                alert('Please fill in all required fields.');
                return false;
            }

            return true;
        });
    }

    /**
     * Initialize environment auto-fill functionality
     */
    function initEnvironmentAutoFill() {
        const $envSelect = $('#fe_woo_environment');

        if (!$envSelect.length) {
            return;
        }

        // Auto-fill when environment changes to local
        $envSelect.on('change', function() {
            const selectedEnv = $(this).val();

            if (selectedEnv === 'local') {
                showAutoFillPrompt();
            }
        });

        // Auto-fill on page load if local is selected
        if ($envSelect.val() === 'local') {
            // Check if fields are already filled
            const companyName = $('#fe_woo_company_name').val();
            if (!companyName || companyName === '') {
                // Show prompt to auto-fill
                showAutoFillBanner();
            }
        }

        // Handle auto-fill button click (from settings page)
        $(document).on('click', '#fe-woo-autofill-btn', function(e) {
            e.preventDefault();
            autoFillTestData();
        });
    }

    /**
     * Show auto-fill prompt
     */
    function showAutoFillPrompt() {
        const message = 'Would you like to auto-fill all fields with test data for local development?';

        if (confirm(message)) {
            autoFillTestData();
        }
    }

    /**
     * Show auto-fill banner
     */
    function showAutoFillBanner() {
        const banner = $('<div class="notice notice-info is-dismissible" style="margin: 20px 0;">')
            .append('<p><strong>Local Development Mode:</strong> You can auto-fill all fields with test data. <button type="button" class="button button-primary" id="fe-woo-autofill-btn">Auto-Fill Test Data</button></p>');

        $('#fe_woo_environment').closest('table').before(banner);

        $('#fe-woo-autofill-btn').on('click', function() {
            autoFillTestData();
            banner.slideUp(300, function() {
                $(this).remove();
            });
        });

        // Handle dismiss button
        banner.find('.notice-dismiss').on('click', function() {
            banner.remove();
        });
    }

    /**
     * Auto-fill form with test data
     */
    function autoFillTestData() {
        const testData = {
            'fe_woo_company_name': 'Empresa de Prueba DDEV S.A.',
            'fe_woo_cedula_juridica': '310123456789',
            'fe_woo_economic_activity': '620100',
            'fe_woo_province_code': '1',
            'fe_woo_canton_code': '01',
            'fe_woo_district_code': '01',
            'fe_woo_neighborhood_code': '01',
            'fe_woo_address': 'San José, Avenida Central, Calle 1, Edificio Test',
            'fe_woo_phone': '2222-3333',
            'fe_woo_email': 'test@tiquetera.local',
            'fe_woo_api_username': 'test_user@local.dev',
            'fe_woo_api_password': 'test_password_123'
        };

        // Fill text fields
        $.each(testData, function(fieldId, value) {
            const $field = $('#' + fieldId);
            if ($field.length) {
                $field.val(value).trigger('change');
                // Add visual feedback
                $field.css('background-color', '#e7f7e7');
                setTimeout(function() {
                    $field.css('background-color', '');
                }, 1000);
            }
        });

        // Show success message
        const successNotice = $('<div class="notice notice-success is-dismissible" style="margin: 20px 0;">')
            .append('<p><strong>✓ Test data filled successfully!</strong> All fields have been populated with sample data for local testing.</p>')
            .hide()
            .fadeIn(300);

        $('#fe_woo_environment').closest('table').before(successNotice);

        // Auto-dismiss after 5 seconds
        setTimeout(function() {
            successNotice.fadeOut(300, function() {
                $(this).remove();
            });
        }, 5000);

        // Scroll to top to see the message
        $('html, body').animate({
            scrollTop: successNotice.offset().top - 100
        }, 500);
    }

    /**
     * Initialize when document is ready
     */
    $(document).ready(function() {
        init();
    });

})(jQuery);
