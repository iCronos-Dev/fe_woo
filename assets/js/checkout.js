/**
 * FE WooCommerce Checkout JavaScript
 *
 * Handles conditional display of factura electrónica fields
 *
 * @package FE_Woo
 */

jQuery(document).ready(function($) {
    'use strict';

    // Check if factura fields exist
    if ($('#fe_woo_factura_fields').length === 0) {
        return; // No factura fields, exit
    }

    /**
     * Toggle factura electrónica fields visibility
     */
    function toggleFacturaFields() {
        const $checkbox = $('#fe_woo_require_factura');
        const $detailsContainer = $('#fe_woo_factura_details');
        const $fields = $detailsContainer.find('input, select');

        if ($checkbox.is(':checked')) {
            $detailsContainer.slideDown(300);
            // Mark required fields (excluding optional fields)
            $fields.each(function() {
                const $field = $(this);
                const $wrapper = $field.closest('.form-row');
                const fieldId = $field.attr('id');

                // Skip optional fields (phone and activity code)
                if (fieldId === 'fe_woo_phone' || fieldId === 'fe_woo_activity_code') {
                    return; // Skip this field
                }

                // Add required class to wrapper
                if (!$wrapper.hasClass('validate-required')) {
                    $wrapper.addClass('validate-required');
                }

                // Add required attribute
                $field.attr('required', 'required');

                // Add asterisk to label if not present
                const $label = $wrapper.find('label');
                if ($label.length && !$label.find('.required').length) {
                    $label.append(' <abbr class="required" title="obligatorio">*</abbr>');
                }
            });
        } else {
            $detailsContainer.slideUp(300);
            // Remove required attributes
            $fields.each(function() {
                const $field = $(this);
                const $wrapper = $field.closest('.form-row');

                // Remove required class
                $wrapper.removeClass('validate-required');

                // Remove required attribute
                $field.removeAttr('required');

                // Remove asterisk from label
                $wrapper.find('label .required').remove();
            });
        }
    }

    /**
     * Validate email format
     */
    function validateEmail() {
        const $email = $('#fe_woo_invoice_email');
        const $wrapper = $email.closest('.form-row');
        const email = $email.val();

        if (!email) {
            return;
        }

        // Email regex pattern
        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        const isValid = emailPattern.test(email);

        // Remove previous error messages
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');

        if (!isValid) {
            $wrapper.addClass('woocommerce-invalid');
            $email.after('<span class="fe-woo-error-message" style="color: #e2401c; font-size: 0.875em; display: block; margin-top: 5px;">Por favor ingrese un correo electrónico válido.</span>');
        }
    }

    /**
     * Validate phone format (numbers only)
     */
    function validatePhone() {
        const $phone = $('#fe_woo_phone');
        const $wrapper = $phone.closest('.form-row');
        const phone = $phone.val();

        if (!phone) {
            return;
        }

        // Remove previous error messages
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');

        // Check if contains only numbers
        if (!/^[0-9]+$/.test(phone)) {
            $wrapper.addClass('woocommerce-invalid');
            $phone.after('<span class="fe-woo-error-message" style="color: #e2401c; font-size: 0.875em; display: block; margin-top: 5px;">El teléfono debe contener solo números.</span>');
            return false;
        }

        // Check length (8-15 digits)
        if (phone.length < 8 || phone.length > 15) {
            $wrapper.addClass('woocommerce-invalid');
            $phone.after('<span class="fe-woo-error-message" style="color: #e2401c; font-size: 0.875em; display: block; margin-top: 5px;">El teléfono debe tener entre 8 y 15 dígitos.</span>');
            return false;
        }

        return true;
    }

    /**
     * Sanitize phone input (remove non-numeric characters)
     */
    function sanitizePhoneInput() {
        const $phone = $('#fe_woo_phone');
        const value = $phone.val();
        const sanitized = value.replace(/[^0-9]/g, '');

        if (value !== sanitized) {
            $phone.val(sanitized);
        }
    }

    /**
     * Validate ID number format based on selected type
     */
    function validateIdNumber() {
        const $idType = $('#fe_woo_id_type');
        const $idNumber = $('#fe_woo_id_number');
        const $wrapper = $idNumber.closest('.form-row');

        if (!$idType.val() || !$idNumber.val()) {
            return;
        }

        const idType = $idType.val();
        const idNumber = $idNumber.val().replace(/[^0-9A-Za-z]/g, ''); // Remove non-alphanumeric
        let isValid = true;
        let errorMessage = '';

        switch (idType) {
            case '01': // Cédula Física
                if (!/^[0-9]{9}$/.test(idNumber)) {
                    isValid = false;
                    errorMessage = 'La Cédula Física debe contener 9 dígitos.';
                }
                break;

            case '02': // Cédula Jurídica
                if (!/^[0-9]{10}$/.test(idNumber)) {
                    isValid = false;
                    errorMessage = 'La Cédula Jurídica debe contener 10 dígitos.';
                }
                break;

            case '03': // DIMEX
                if (!/^[0-9]{11,12}$/.test(idNumber)) {
                    isValid = false;
                    errorMessage = 'El DIMEX debe contener 11 o 12 dígitos.';
                }
                break;

            case '04': // Pasaporte
                if (!/^[0-9A-Za-z]{6,20}$/.test(idNumber)) {
                    isValid = false;
                    errorMessage = 'El Pasaporte debe contener entre 6 y 20 caracteres alfanuméricos.';
                }
                break;
        }

        // Remove previous error messages
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');

        if (!isValid) {
            $wrapper.addClass('woocommerce-invalid');
            $idNumber.after('<span class="fe-woo-error-message" style="color: #e2401c; font-size: 0.875em; display: block; margin-top: 5px;">' + errorMessage + '</span>');
        }
    }

    /**
     * Format ID number as user types (add dashes for Costa Rican IDs)
     */
    function formatIdNumber() {
        const $idType = $('#fe_woo_id_type');
        const $idNumber = $('#fe_woo_id_number');

        if (!$idType.val() || !$idNumber.val()) {
            return;
        }

        const idType = $idType.val();
        let value = $idNumber.val().replace(/[^0-9A-Za-z]/g, '');

        // Format based on type
        if (idType === '01' && value.length > 0) {
            // Cédula Física: 0-0000-0000
            if (value.length > 5) {
                value = value.substring(0, 1) + '-' + value.substring(1, 5) + '-' + value.substring(5, 9);
            } else if (value.length > 1) {
                value = value.substring(0, 1) + '-' + value.substring(1);
            }
        } else if (idType === '02' && value.length > 0) {
            // Cédula Jurídica: 0-000-000000
            if (value.length > 4) {
                value = value.substring(0, 1) + '-' + value.substring(1, 4) + '-' + value.substring(4, 10);
            } else if (value.length > 1) {
                value = value.substring(0, 1) + '-' + value.substring(1);
            }
        }

        $idNumber.val(value);
    }

    // Initialize on page load - hide details if checkbox not checked
    try {
        const $checkbox = $('#fe_woo_require_factura');
        const $detailsContainer = $('#fe_woo_factura_details');

        if (!$checkbox.is(':checked')) {
            $detailsContainer.hide(); // Hide immediately without animation
        }

        toggleFacturaFields();
    } catch (e) {
        console.error('Error in toggleFacturaFields:', e);
    }

    // Toggle fields when checkbox changes
    $(document).on('change', '#fe_woo_require_factura', function() {
        try {
            toggleFacturaFields();
        } catch (e) {
            console.error('Error in toggleFacturaFields:', e);
        }
    });

    // Validate email when it changes
    $(document).on('blur', '#fe_woo_invoice_email', function() {
        validateEmail();
    });

    $(document).on('input', '#fe_woo_invoice_email', function() {
        // Clear error on input
        const $wrapper = $(this).closest('.form-row');
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');
    });

    // Validate and sanitize phone as user types
    $(document).on('input', '#fe_woo_phone', function() {
        sanitizePhoneInput(); // Remove non-numeric characters immediately
        // Clear error on input
        const $wrapper = $(this).closest('.form-row');
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');
    });

    // Validate phone when user leaves field
    $(document).on('blur', '#fe_woo_phone', function() {
        validatePhone();
    });

    // Prevent non-numeric key presses (only for typing, not form submission)
    $(document).on('keydown', '#fe_woo_phone', function(e) {
        // Allow: backspace, delete, tab, escape, enter, arrows
        if ($.inArray(e.keyCode, [46, 8, 9, 27, 13, 110, 190, 37, 38, 39, 40]) !== -1 ||
            // Allow: Ctrl+A, Ctrl+C, Ctrl+V, Ctrl+X
            (e.keyCode === 65 && e.ctrlKey === true) ||
            (e.keyCode === 67 && e.ctrlKey === true) ||
            (e.keyCode === 86 && e.ctrlKey === true) ||
            (e.keyCode === 88 && e.ctrlKey === true) ||
            // Allow: home, end
            (e.keyCode >= 35 && e.keyCode <= 40)) {
            return;
        }
        // Ensure that it is a number and stop the keypress
        if ((e.shiftKey || (e.keyCode < 48 || e.keyCode > 57)) && (e.keyCode < 96 || e.keyCode > 105)) {
            e.preventDefault();
        }
    });

    // Validate ID number when it changes
    $(document).on('blur', '#fe_woo_id_number', function() {
        validateIdNumber();
    });

    // Validate when ID type changes
    $(document).on('change', '#fe_woo_id_type', function() {
        $('#fe_woo_id_number').val(''); // Clear ID number when type changes
        $('#fe_woo_load_status').hide(); // Status obsoleto al cambiar tipo
        const $wrapper = $('#fe_woo_id_number').closest('.form-row');
        $wrapper.find('.fe-woo-error-message').remove();
        $wrapper.removeClass('woocommerce-invalid');

        // Update placeholder based on selected type
        updateIdNumberPlaceholder();
    });

    // Format ID number as user types
    $(document).on('input', '#fe_woo_id_number', function() {
        formatIdNumber();
        $('#fe_woo_load_status').hide(); // Status obsoleto al editar cedula
    });

    /**
     * Update ID number placeholder based on selected type
     */
    function updateIdNumberPlaceholder() {
        const $idType = $('#fe_woo_id_type');
        const $idNumber = $('#fe_woo_id_number');

        const placeholders = {
            '01': '0-0000-0000',
            '02': '0-000-000000',
            '03': '123456789012',
            '04': 'ABC123456'
        };

        const placeholder = placeholders[$idType.val()] || 'Ingrese su número de identificación';
        $idNumber.attr('placeholder', placeholder);
    }

    // Update placeholder on page load if type is selected
    if ($('#fe_woo_id_type').val()) {
        updateIdNumberPlaceholder();
    }

    // Handle WooCommerce checkout update
    $(document.body).on('updated_checkout', function() {
        toggleFacturaFields();
        if ($('#fe_woo_id_type').val()) {
            updateIdNumberPlaceholder();
        }
    });

    /**
     * Mostrar mensaje de estado de la carga de datos.
     * Tipos: 'db', 'api', 'not-found', 'error'
     */
    function showLoadStatus(type, message) {
        $('#fe_woo_load_status')
            .removeClass('status-db status-api status-not-found status-error')
            .addClass('status-' + type)
            .text(message)
            .show();
    }

    /**
     * Handler del botón "Cargar".
     * Consulta la API de Hacienda con la identificación ingresada en el formulario.
     */
    $(document).on('click', '#fe_woo_load_factura', function() {
        var $btn     = $(this);
        var idType   = $('#fe_woo_id_type').val();
        var idNumber = $('#fe_woo_id_number').val();

        if (!idType || !idNumber) {
            showLoadStatus('error', 'Por favor seleccione el tipo y número de identificación.');
            return;
        }

        var originalText = $btn.text();
        $btn.text('Cargando\u2026').prop('disabled', true);
        $('#fe_woo_load_status').hide();

        $.ajax({
            url:  fe_woo_checkout.ajax_url,
            type: 'POST',
            data: {
                action:    'fe_woo_load_factura',
                id_type:   idType,
                id_number: idNumber.replace(/[^0-9A-Za-z]/g, ''),
                nonce:     fe_woo_checkout.load_nonce
            },
            success: function(response) {
                $btn.text(originalText).prop('disabled', false);

                if (response.success) {
                    var data = response.data.data;

                    if (data.nombre)        { $('#fe_woo_full_name').val(data.nombre); }
                    // activity_code NO se auto-rellena: el código devuelto por
                    // el autocompletar de Hacienda no siempre es uno aceptado
                    // por el endpoint de recepción para esa cédula (bug -411).
                    // El cliente lo escribe manualmente sólo si lo conoce.
                    if (data.invoice_email && !$('#fe_woo_invoice_email').val()) {
                        $('#fe_woo_invoice_email').val(data.invoice_email);
                    }
                    if (data.phone && !$('#fe_woo_phone').val()) {
                        $('#fe_woo_phone').val(data.phone);
                    }
                    showLoadStatus('api', 'Datos cargados desde el sistema de Hacienda. Puede editarlos libremente.');

                    updateIdNumberPlaceholder();
                } else {
                    var errorSource  = response.data && response.data.source;
                    var errorMessage = (response.data && response.data.message) || 'No se encontró información disponible para esta identificación.';

                    if (errorSource === 'service_unavailable') {
                        showLoadStatus('error', errorMessage);
                    } else {
                        showLoadStatus('not-found', errorMessage);
                    }
                }
            },
            error: function() {
                $btn.text(originalText).prop('disabled', false);
                showLoadStatus('error', 'Error al conectar con el servicio. Intente nuevamente.');
            }
        });
    });
});
