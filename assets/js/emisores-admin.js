/**
 * Emisores Admin JavaScript
 *
 * @package FE_Woo
 */

(function($) {
    'use strict';

    const FeWooEmisoresAdmin = {
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Add emisor button
            $('#fe-woo-add-emisor').on('click', this.showAddModal.bind(this));

            // Edit emisor button
            $(document).on('click', '.fe-woo-edit-emisor', this.showEditModal.bind(this));

            // Delete emisor button
            $(document).on('click', '.fe-woo-delete-emisor', this.deleteEmisor.bind(this));

            // Close modal
            $('.fe-woo-modal-close, #fe-woo-cancel-emisor').on('click', this.closeModal.bind(this));

            // Close modal on outside click
            $('.fe-woo-modal').on('click', function(e) {
                if ($(e.target).hasClass('fe-woo-modal')) {
                    FeWooEmisoresAdmin.closeModal();
                }
            });

            // Save emisor form
            $('#fe-woo-emisor-form').on('submit', this.saveEmisor.bind(this));

            // Escape key closes modal
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    FeWooEmisoresAdmin.closeModal();
                }
            });
        },

        /**
         * Show add modal
         */
        showAddModal: function() {
            $('#fe-woo-modal-title').text('Agregar Nuevo Emisor');
            $('#fe-woo-emisor-form')[0].reset();
            $('#emisor-id').val('');
            $('#emisor-active').prop('checked', true);
            this.openModal();
        },

        /**
         * Show edit modal
         */
        showEditModal: function(e) {
            const emisorId = $(e.currentTarget).data('emisor-id');

            $.ajax({
                url: feWooEmisores.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_get_emisor',
                    nonce: feWooEmisores.nonce,
                    emisor_id: emisorId
                },
                beforeSend: function() {
                    $(e.currentTarget).prop('disabled', true).text('Cargando...');
                },
                success: function(response) {
                    if (response.success) {
                        FeWooEmisoresAdmin.populateForm(response.data.emisor);
                        $('#fe-woo-modal-title').text('Editar Emisor');
                        FeWooEmisoresAdmin.openModal();
                    } else {
                        alert(response.data.message || feWooEmisores.strings.error);
                    }
                },
                error: function() {
                    alert(feWooEmisores.strings.error);
                },
                complete: function() {
                    $(e.currentTarget).prop('disabled', false).text('Editar');
                }
            });
        },

        /**
         * Populate form with emisor data
         */
        populateForm: function(emisor) {
            $('#emisor-id').val(emisor.id);
            $('#emisor-is-parent').prop('checked', emisor.is_parent == 1);
            $('#emisor-nombre-legal').val(emisor.nombre_legal);
            $('#emisor-cedula-juridica').val(emisor.cedula_juridica);
            $('#emisor-nombre-comercial').val(emisor.nombre_comercial || '');
            $('#emisor-api-username').val(emisor.api_username || '');
            $('#emisor-actividad-economica').val(emisor.actividad_economica);
            $('#emisor-provincia').val(emisor.codigo_provincia);
            $('#emisor-canton').val(emisor.codigo_canton);
            $('#emisor-distrito').val(emisor.codigo_distrito);
            $('#emisor-barrio').val(emisor.codigo_barrio || '');
            $('#emisor-direccion').val(emisor.direccion);
            $('#emisor-telefono').val(emisor.telefono || '');
            $('#emisor-email').val(emisor.email || '');
            $('#emisor-active').prop('checked', emisor.active == 1);

            // Show certificate status if exists
            if (emisor.certificate_path) {
                $('#emisor-certificate-status').html('<span style="color: #46b450;">✓ Certificado cargado</span>');
            } else {
                $('#emisor-certificate-status').html('<span style="color: #dc3232;">Sin certificado</span>');
            }
        },

        /**
         * Save emisor
         */
        saveEmisor: function(e) {
            e.preventDefault();

            const formData = new FormData(e.target);
            formData.append('action', 'fe_woo_save_emisor');
            formData.append('nonce', feWooEmisores.nonce);

            $.ajax({
                url: feWooEmisores.ajaxUrl,
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                beforeSend: function() {
                    $('#fe-woo-save-emisor').prop('disabled', true).text(feWooEmisores.strings.saving);
                },
                success: function(response) {
                    if (response.success) {
                        FeWooEmisoresAdmin.closeModal();
                        location.reload();
                    } else {
                        let errorMsg = feWooEmisores.strings.error;
                        if (response.data && response.data.errors) {
                            errorMsg = response.data.errors.join('\n');
                        } else if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        alert(errorMsg);
                    }
                },
                error: function() {
                    alert(feWooEmisores.strings.error);
                },
                complete: function() {
                    $('#fe-woo-save-emisor').prop('disabled', false).text('Guardar Emisor');
                }
            });
        },

        /**
         * Delete emisor
         */
        deleteEmisor: function(e) {
            const emisorId = $(e.currentTarget).data('emisor-id');

            if (!confirm(feWooEmisores.strings.confirmDelete)) {
                return;
            }

            $.ajax({
                url: feWooEmisores.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_delete_emisor',
                    nonce: feWooEmisores.nonce,
                    emisor_id: emisorId
                },
                beforeSend: function() {
                    $(e.currentTarget).prop('disabled', true).text(feWooEmisores.strings.deleting);
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        let errorMsg = feWooEmisores.strings.error;
                        if (response.data && response.data.errors) {
                            errorMsg = response.data.errors.join('\n');
                        } else if (response.data && response.data.message) {
                            errorMsg = response.data.message;
                        }
                        alert(errorMsg);
                    }
                },
                error: function() {
                    alert(feWooEmisores.strings.error);
                },
                complete: function() {
                    $(e.currentTarget).prop('disabled', false).text('Eliminar');
                }
            });
        },

        /**
         * Open modal
         */
        openModal: function() {
            $('#fe-woo-emisor-modal').addClass('active').fadeIn(200);
            $('body').css('overflow', 'hidden');
        },

        /**
         * Close modal
         */
        closeModal: function() {
            $('#fe-woo-emisor-modal').removeClass('active').fadeOut(200);
            $('body').css('overflow', '');
            $('#fe-woo-emisor-form')[0].reset();
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        FeWooEmisoresAdmin.init();

        // Initialize WooCommerce product search
        if (typeof $.fn.selectWoo !== 'undefined') {
            $('.wc-product-search').selectWoo({
                ajax: {
                    url: ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            term: params.term,
                            action: 'woocommerce_json_search_products',
                            security: wc_enhanced_select_params.search_products_nonce
                        };
                    },
                    processResults: function(data) {
                        var results = [];
                        $.each(data, function(id, text) {
                            results.push({
                                id: id,
                                text: text
                            });
                        });
                        return {
                            results: results
                        };
                    },
                    cache: true
                },
                minimumInputLength: 3,
                allowClear: true,
                placeholder: $('.wc-product-search').data('placeholder')
            });
        }
    });

})(jQuery);
