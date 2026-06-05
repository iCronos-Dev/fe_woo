/**
 * FE WooCommerce Order Admin JavaScript
 *
 * Handles AJAX interactions for electronic invoice generation
 *
 * @package FE_Woo
 */

(function($) {
    'use strict';

    /**
     * Browser-side polling: tras el AJAX de "Ejecutar Factura", si la
     * respuesta vino con pending=true (Hacienda todavía no devolvió el
     * acuse firmado), pregunta al server cada 3s vía recheck_status hasta
     * verlo terminal o agotar 30 intentos (~90s). Mientras tanto, mantiene
     * el botón con spinner + texto "esperando acuse".
     *
     * Cada poll es una request HTTP independiente y corta (timeout 8s),
     * por lo que nunca cruza el límite de 60s de nginx Pantheon.
     *
     * Si el admin cierra la pestaña, el cron WP `fe_woo_poll_acuse_xml`
     * (programado por el server tras el envío) termina el trabajo en
     * background — la próxima vez que se abra la orden ya tendrá
     * veredicto.
     */
    function pollOrderStatus($button, originalText, orderId, clave, onTerminal, onTimeout) {
        var attempts = 0;
        var MAX_ATTEMPTS = 30;
        var INTERVAL_MS = 3000;

        var timer = setInterval(function () {
            attempts++;

            $.ajax({
                url:     feWooOrderAdmin.ajaxUrl,
                type:    'POST',
                timeout: 8000,
                data:    {
                    action:   'fe_woo_recheck_status',
                    order_id: orderId,
                    clave:    clave,
                    nonce:    feWooOrderAdmin.nonce
                }
            }).done(function (response) {
                var status = (response && response.data && response.data.hacienda_status) || '';
                status = String(status).toLowerCase();

                if (status === 'aceptado' || status === 'rechazado') {
                    clearInterval(timer);
                    onTerminal(status, response.data);
                    return;
                }

                if (attempts >= MAX_ATTEMPTS) {
                    clearInterval(timer);
                    onTimeout();
                }
            }).fail(function () {
                // Una falla en un poll individual no termina el loop:
                // puede haber sido glitch de red. Solo cortamos al MAX.
                if (attempts >= MAX_ATTEMPTS) {
                    clearInterval(timer);
                    onTimeout();
                }
            });
        }, INTERVAL_MS);

        return timer;
    }

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        initEjecutarButton();
        initDownloadAllButton();
        initRecheckStatusButton();
        initRetryWithUpdatedDataButton();
        initDownloadAllMultiButton();
        initDownloadSingleFacturaButton();
        initDownloadNotaDocsButton();
        initGenerateNoteButton();
        initReasonCounter();
        initLockGuard();
    });

    /**
     * Lock guard: si el meta-box render server-side detectó que la orden tiene
     * una operación FE en progreso, deshabilita todos los botones FE y
     * recarga la página cuando expire el lock para reflejar el resultado.
     *
     * Refrescos durante una operación en vuelo no pueden disparar una
     * segunda emisión: el handler AJAX rechaza con "ya hay operación en
     * proceso" y este guard refuerza la UX para que el operador no insista.
     */
    function initLockGuard() {
        var $box = $('.fe-woo-factura-status-box');
        if (!$box.length || $box.data('feLocked') !== 1) {
            return;
        }
        var remaining = parseInt($box.data('feLockRemaining'), 10) || 30;

        // Deshabilitar acciones críticas mientras dure el lock.
        $box.find('.fe-woo-manual-execute, .fe-woo-retry-with-updated-data, .fe-woo-recheck-status, .fe-woo-generate-note')
            .prop('disabled', true)
            .css('opacity', 0.5)
            .attr('title', 'Espera a que termine la operación FE en curso');

        // Reload cuando expire (más un buffer de 2s por si el otro proceso
        // todavía no terminó de guardar). Cap a 60s aunque el server reporte
        // más.
        var reloadIn = Math.min(remaining + 2, 60) * 1000;
        setTimeout(function () {
            window.location.reload();
        }, reloadIn);
    }

    /**
     * Initialize EJECUTAR button
     */
    function initEjecutarButton() {
        $('.fe-woo-ejecutar-factura').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalText = $button.html();

            // Fase 1 (envío): el AJAX firma + POST a Hacienda + persiste.
            // En la nueva arquitectura este AJAX retorna en <40s sin
            // bloquear esperando el acuse. Por eso el texto distingue
            // "Enviando…" (esta fase) de "Esperando acuse…" (fase 2,
            // polling JS posterior).
            $button.prop('disabled', true);
            $button.html(
                '<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>' +
                'Enviando factura a Hacienda…'
            );

            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                // El AJAX 1 ya no espera el veredicto — solo envía y
                // persiste. 60s es margen suficiente para el envío + 1
                // query corta opcional. El polling del veredicto se
                // hace en fase 2 con requests independientes.
                timeout: 60000,
                data: {
                    action: 'fe_woo_manual_execute_factura',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (!response.success) {
                        showMessage((response.data && response.data.message) || feWooOrderAdmin.i18n.error, 'error');
                        $button.prop('disabled', false).html(originalText);
                        return;
                    }

                    var data    = response.data || {};
                    var pending = !!data.pending;
                    var clave   = data.clave;
                    var oid     = data.order_id || orderId;

                    if (!pending) {
                        // Happy path: Hacienda ya devolvió veredicto en
                        // la misma respuesta del envío.
                        showMessage(data.message || 'Factura procesada', 'success');
                        setTimeout(function() { location.reload(); }, 800);
                        return;
                    }

                    // Fase 2 (espera de acuse): JS hace polling al server
                    // hasta veredicto o timeout. El cron WP en background
                    // sigue corriendo como red de seguridad.
                    $button.html(
                        '<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>' +
                        'Enviada, esperando acuse de Hacienda…'
                    );

                    pollOrderStatus(
                        $button, originalText, oid, clave,
                        function onTerminal(status) {
                            showMessage(
                                status === 'aceptado'
                                    ? 'Factura aceptada por Hacienda.'
                                    : 'Factura rechazada por Hacienda. Revisa los detalles en la orden.',
                                status === 'aceptado' ? 'success' : 'error'
                            );
                            setTimeout(function() { location.reload(); }, 800);
                        },
                        function onTimeout() {
                            showMessage(
                                'Factura enviada, pero Hacienda aún no ha respondido. Refresca la página en unos minutos para ver el estado actualizado.',
                                'info'
                            );
                            $button.prop('disabled', false).html(originalText);
                        }
                    );
                },
                error: function(xhr, status) {
                    var msg = (status === 'timeout')
                        ? 'Tiempo de espera agotado. La factura puede haber sido enviada. Recarga la página para ver el estado.'
                        : feWooOrderAdmin.i18n.error;
                    showMessage(msg, 'error');
                    $button.prop('disabled', false).html(originalText);
                }
            });
        });
    }

    /**
     * Shown on rejected/pending invoices — re-hits Hacienda's consulta
     * endpoint for the current clave so the operator can pick up late
     * state changes (e.g. Hacienda flips "rechazado" → "aceptado" after a
     * manual override, or a "recibido" progresses to final). Does not
     * re-sign or re-send; the reexecute button handles that.
     */
    function initRecheckStatusButton() {
        $(document).on('click', '.fe-woo-recheck-status', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalHtml = $button.html();

            // Visible spinner — the recheck can take a few seconds on the
            // first call (fresh OAuth token + consulta round-trip).
            $button.prop('disabled', true).html(
                '<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>' +
                'Consultando a Hacienda…'
            );

            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                timeout: 60000,
                data: {
                    action: 'fe_woo_recheck_status',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        showMessage((response.data && response.data.message) || feWooOrderAdmin.i18n.error, 'error');
                        $button.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr, status) {
                    var msg = (status === 'timeout')
                        ? 'La consulta tardó demasiado. Intenta de nuevo en unos segundos.'
                        : feWooOrderAdmin.i18n.error;
                    showMessage(msg, 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        });
    }

    /**
     * Shown only on rejected invoices. Discards the rejected attempt and
     * reprocesses the order with the current emisor/config — a fresh
     * clave, new signature, new POST to Hacienda. User-facing confirm
     * prompts them about the consequences since the previous clave is
     * effectively abandoned.
     */
    function initRetryWithUpdatedDataButton() {
        $(document).on('click', '.fe-woo-retry-with-updated-data', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalHtml = $button.html();

            if (!window.confirm(
                'Esto descartará la factura rechazada y generará una nueva con los datos actuales del emisor.\n\n' +
                '¿Continuar?'
            )) {
                return;
            }

            $button.prop('disabled', true).html(
                '<span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>' +
                'Reprocesando…'
            );

            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'fe_woo_retry_with_updated_data',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showMessage(response.data.message, 'success');
                        setTimeout(function() { location.reload(); }, 800);
                    } else {
                        showMessage((response.data && response.data.message) || feWooOrderAdmin.i18n.error, 'error');
                        $button.prop('disabled', false).html(originalHtml);
                    }
                },
                error: function(xhr, status) {
                    var msg = (status === 'timeout')
                        ? 'Tiempo de espera agotado. Recarga la página para ver el estado actual.'
                        : feWooOrderAdmin.i18n.error;
                    showMessage(msg, 'error');
                    $button.prop('disabled', false).html(originalHtml);
                }
            });
        });
    }

    /**
     * Initialize Download All button
     */
    function initDownloadAllButton() {
        $('.fe-woo-download-all').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip);

            // Send AJAX request to create ZIP
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_documents',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Show message to user
     *
     * @param {string} message Message text
     * @param {string} type Message type ('success' or 'error')
     */
    function showMessage(message, type) {
        var messageClass;
        if (type === 'success') {
            messageClass = 'notice-success';
        } else if (type === 'info') {
            messageClass = 'notice-info';
        } else {
            messageClass = 'notice-error';
        }

        // Escapamos el mensaje y luego convertimos \n a <br> para que los
        // pre-flight con varios errores ("• ...\n• ...") se rendericen en
        // varias líneas en lugar de colapsarse a una sola. Sin escape, valores
        // como direccion del receptor podrían inyectar HTML.
        var $p = $('<p></p>').text(message);
        $p.html($p.html().replace(/\n/g, '<br>'));

        var $notice = $('<div class="notice ' + messageClass + ' is-dismissible fe-woo-notice"></div>')
            .append($p)
            .append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Descartar este aviso.</span></button>');

        // Remove any existing FE Woo notices first
        $('.wrap .notice.fe-woo-notice').remove();

        // Insert message at the top of the page, right after h1
        var $wrap = $('.wrap');
        if ($wrap.length) {
            var $h1 = $wrap.find('h1, h2').first();
            if ($h1.length) {
                $h1.after($notice);
            } else {
                $wrap.prepend($notice);
            }
        } else {
            // Fallback: insert after first heading found
            $('h1, h2').first().after($notice);
        }

        // Initialize WordPress dismiss button functionality
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });

        // Auto-dismiss para success/info (mensajes informativos breves). Los
        // 'error' quedan persistentes hasta que el admin haga clic en la X —
        // los mensajes de pre-flight pueden tener varias viñetas y el admin
        // necesita tiempo para leerlos y corregir antes de reintentar.
        if (type !== 'error') {
            setTimeout(function() {
                if ($notice.is(':visible')) {
                    $notice.fadeOut(function() {
                        $(this).remove();
                    });
                }
            }, 8000);
        }

        // Scroll to top to show message
        $('html, body').animate({
            scrollTop: 0
        }, 300);
    }

    /**
     * Initialize Download All Multi-Factura button
     */
    function initDownloadAllMultiButton() {
        $('.fe-woo-download-all-multi').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip || 'Preparando descarga...');

            // Send AJAX request to create ZIP with all multi-factura documents
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_multi_factura',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Initialize Download Single Factura button (for individual facturas in multi-factura orders)
     */
    function initDownloadSingleFacturaButton() {
        $('.fe-woo-download-single-factura').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.html();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="font-size: 14px; vertical-align: text-top; animation: rotation 1s infinite linear;"></span> ...');

            // Send AJAX request to create ZIP (reuses existing download_all_documents action)
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_documents',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.html(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.html(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.html(originalText);
                }
            });
        });
    }

    /**
     * Initialize Download Nota Documents button
     * Uses event delegation to support dynamically rendered nota buttons
     */
    function initDownloadNotaDocsButton() {
        $(document).on('click', '.fe-woo-download-nota-docs', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip || 'Preparando ZIP...');

            // Send AJAX request to create ZIP with this note's documents
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_nota_docs',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Initialize character counter for reason fields
     * Uses event delegation with class-based selectors to support multiple nota forms
     */
    function initReasonCounter() {
        $(document).on('input', '.fe-woo-note-reason', function() {
            var length = $(this).val().length;
            $(this).closest('.fe-woo-nota-form-container').find('.fe-woo-reason-counter').text(length + '/180');
        });
    }

    /**
     * Initialize Generate Note button
     * Uses event delegation and scoped selectors to support multiple nota forms
     * (one per factura in multi-factura orders, plus single-factura orders)
     */
    function initGenerateNoteButton() {
        $(document).on('click', '.fe-woo-generate-note', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $container = $button.closest('.fe-woo-nota-form-container');
            var orderId = $button.data('order-id');
            var referencedClave = $button.data('clave');
            var emisorId = $button.data('emisor-id') || 0;

            // Read form values from scoped container
            var noteType = $container.find('.fe-woo-note-type').val();
            var referenceCode = $container.find('.fe-woo-reference-code').val();
            var reason = $container.find('.fe-woo-note-reason').val().trim();
            var additionalNotes = $container.find('.fe-woo-note-additional').val().trim();
            var originalText = $button.text();
            var $messageBox = $container.find('.fe-woo-note-message');

            // Validate inputs
            if (!reason) {
                $messageBox.removeClass('notice-success').addClass('notice-error')
                    .html('<strong>Error:</strong> La razón es obligatoria.')
                    .show();
                return;
            }

            if (reason.length > 180) {
                $messageBox.removeClass('notice-success').addClass('notice-error')
                    .html('<strong>Error:</strong> La razón no puede exceder 180 caracteres.')
                    .show();
                return;
            }

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text('Generando...');
            $messageBox.hide();

            // Send AJAX request
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_generate_nota',
                    order_id: orderId,
                    referenced_clave: referencedClave,
                    emisor_id: emisorId,
                    note_type: noteType,
                    reference_code: referenceCode,
                    reason: reason,
                    additional_notes: additionalNotes,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $messageBox.removeClass('notice-error').addClass('notice-success')
                            .html('<strong>' + response.data.message + '</strong>')
                            .show();

                        // Clear form
                        $container.find('.fe-woo-note-reason').val('');
                        $container.find('.fe-woo-note-additional').val('');
                        $container.find('.fe-woo-reason-counter').text('0/180');

                        // Reload page after 2 seconds to show updated list
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        $messageBox.removeClass('notice-success').addClass('notice-error')
                            .html('<strong>Error:</strong> ' + response.data.message)
                            .show();

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    $messageBox.removeClass('notice-success').addClass('notice-error')
                        .html('<strong>Error:</strong> Error de conexión al servidor.')
                        .show();

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

})(jQuery);
