/**
 * FE WooCommerce — CABYS picker on the product editor.
 */
(function ($) {
    'use strict';

    $(document).ready(function () {
        var $codeInput        = $('#fe_woo_cabys_code');
        var $descriptionInput = $('#fe_woo_cabys_description');
        var $searchInput      = $('#fe_woo_cabys_search');
        var $results          = $('#fe-woo-cabys-results');
        var $spinner          = $('.fe-woo-cabys-spinner');

        if ($searchInput.length === 0) {
            return;
        }

        var debounceTimer = null;
        var DEBOUNCE_MS   = 500;

        // Don't submit the product form when the user presses Enter inside the search input.
        $searchInput.on('keypress', function (e) {
            if (e.which === 13) {
                e.preventDefault();
                return false;
            }
        });

        $searchInput.on('input', function () {
            var query = $(this).val().trim();

            window.clearTimeout(debounceTimer);

            if (query.length < 2) {
                $spinner.removeClass('is-active');
                $results.empty();
                return;
            }

            $spinner.addClass('is-active');

            debounceTimer = window.setTimeout(function () {
                runSearch(query);
            }, DEBOUNCE_MS);
        });

        $results.on('click', '.fe-woo-cabys-result', function () {
            var data = $(this).data('cabys');
            if (!data) { return; }
            $codeInput.val(data.codigo).trigger('change');
            $descriptionInput.val(data.descripcion).trigger('change');
            $results.empty();
            $searchInput.val('');
        });

        function runSearch(query) {
            $.ajax({
                url:  feWooProductCABYS.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_search_cabys',
                    nonce:  feWooProductCABYS.nonce,
                    query:  query
                },
                success: function (response) {
                    $spinner.removeClass('is-active');
                    if (response && response.success) {
                        renderResults(response.data.results || []);
                    } else {
                        var data = (response && response.data) || {};
                        renderError(data.message || feWooProductCABYS.strings.error, data.hint);
                    }
                },
                error: function () {
                    $spinner.removeClass('is-active');
                    renderError(feWooProductCABYS.strings.error);
                }
            });
        }

        function renderResults(items) {
            $results.empty();

            if (!items.length) {
                $results.append(
                    $('<div>', {
                        'class': 'fe-woo-cabys-no-results',
                        text:    feWooProductCABYS.strings.noResults
                    })
                );
                return;
            }

            items.forEach(function (item) {
                if (!item || !item.codigo) { return; }

                var $row = $('<div>', { 'class': 'fe-woo-cabys-result' });
                $row.data('cabys', {
                    codigo:      String(item.codigo),
                    descripcion: String(item.descripcion || '')
                });

                $row.append($('<span>', { 'class': 'fe-woo-cabys-result-code', text: item.codigo }));
                $row.append($('<span>', { 'class': 'fe-woo-cabys-result-description', text: item.descripcion || '' }));
                $results.append($row);
            });
        }

        function renderError(message, hint) {
            $results.empty();
            var $box = $('<div>', { 'class': 'fe-woo-cabys-error', text: message });
            if (hint) {
                $box.append(
                    $('<div>', {
                        'class': 'fe-woo-cabys-hint',
                        text:    hint
                    })
                );
            }
            $results.append($box);
        }
    });
})(jQuery);
