/**
 * FE Woo My Account JavaScript
 * Handles automatic autocomplete functionality from Hacienda API
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        const $cedulaInput = $('#fe-woo-user-cedula');
        const $idTypeInput = $('#fe-woo-user-id-type');
        const $loadingNotice = $('.fe-woo-autocomplete-notice');
        const $form = $('.fe-woo-factura-form');

        // Check if we should autocomplete
        if ($cedulaInput.length === 0 || $idTypeInput.length === 0) {
            return;
        }

        const cedula = $cedulaInput.val();
        const idType = $idTypeInput.val();

        if (!cedula) {
            return;
        }

        // Automatically make AJAX request on page load
        $.ajax({
            url: fe_woo_my_account.ajax_url,
            type: 'POST',
            data: {
                action: 'fe_woo_autocomplete_hacienda',
                cedula: cedula,
                nonce: fe_woo_my_account.nonce
            },
            success: function(response) {
                if (response.success && response.data) {
                    autocompletarFormulario(response.data, cedula, idType);
                } else {
                    mostrarError(response.data.message || 'No se encontraron datos en el sistema de Hacienda.');
                }
            },
            error: function() {
                mostrarError('Error de conexión con el servicio de Hacienda.');
            },
            complete: function() {
                // Hide loading notice
                $loadingNotice.fadeOut();
            }
        });

        function autocompletarFormulario(data, cedula, idType) {
            // Fill ID Type
            if (idType) {
                $('#fe_woo_id_type').val(idType);
            }

            // Fill ID Number
            $('#fe_woo_id_number').val(cedula);

            // Fill Full Name
            if (data.nombre) {
                $('#fe_woo_full_name').val(data.nombre);
            }

            // Get current user email as default
            const userEmail = $('input[name="account_email"]').val() || '';
            if (userEmail && !$('#fe_woo_invoice_email').val()) {
                $('#fe_woo_invoice_email').val(userEmail);
            }

            // Fill activity code if available
            if (data.activity_code) {
                $('#fe_woo_activity_code').val(data.activity_code);
            }

            // Show success message
            const successMessage = $('<div class="woocommerce-message" role="alert"></div>')
                .text('Datos autocompletados exitosamente desde Hacienda. Revise y complete los campos faltantes.')
                .hide();

            $form.prepend(successMessage);
            successMessage.fadeIn();
        }

        function mostrarError(mensaje) {
            // Hide loading notice
            $loadingNotice.fadeOut();

            // Show error message
            const errorMessage = $('<div class="woocommerce-error" role="alert"></div>')
                .text(mensaje)
                .hide();

            $form.prepend(errorMessage);
            errorMessage.fadeIn();
        }
    });

})(jQuery);
