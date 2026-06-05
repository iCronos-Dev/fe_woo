/**
 * Inyecta una columna `CodigoTarifaIVA` en la tabla de WC tax rates
 * (`wc-settings&tab=tax`). WC no provee hook para extender columnas,
 * así que tocamos el DOM directo y persistimos via AJAX paralelo.
 *
 * Estrategia de persistencia (v2):
 *  - Filas con `tax_rate_id` REAL: persist inmediato on `change` del select.
 *    Esto evita el race con el re-render Backbone post-save de WC.
 *  - Filas con `tax_rate_id` TEMPORAL ("new-..."): snapshot por fingerprint
 *    (rate + name + priority + country + state). Después del save de WC,
 *    correlacionar fingerprint con la fila ahora con ID real y persistir.
 *
 * Globals: `feWooTaxCodigos` (localizado), jQuery.
 */
(function ($) {
    'use strict';

    if (typeof window.feWooTaxCodigos === 'undefined') {
        return;
    }

    var config = window.feWooTaxCodigos;
    var COLUMN_CLASS = 'fe-woo-codigo-tarifa-iva';
    var SELECT_DATA_ATTR = 'data-fe-codigo-row';

    // Pendientes por fingerprint para filas que aún no tienen ID real.
    // Se vacía cuando WC asigna IDs reales y correlacionamos.
    var pendingByFingerprint = {};

    function buildSelectHtml(currentValue) {
        var html = '<select class="' + COLUMN_CLASS + '-select">';
        html += '<option value="">' + escapeHtml(config.i18n.placeholder) + '</option>';
        var keys = Object.keys(config.catalog);
        keys.sort(); // 01, 02, ... 11
        keys.forEach(function (codigo) {
            var label = config.catalog[codigo];
            var selected = String(currentValue) === String(codigo) ? ' selected' : '';
            html += '<option value="' + escapeHtml(codigo) + '"' + selected + '>' + escapeHtml(label) + '</option>';
        });
        html += '</select>';
        return html;
    }

    function escapeHtml(str) {
        return String(str).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    function getRowId($tr) {
        return $tr.attr('data-id') || '';
    }

    function isTempRowId(id) {
        return /^new-/.test(id);
    }

    /**
     * Identidad estable de una fila en términos de los campos editables.
     * Sirve para correlacionar una fila pre-save (id temp) con su versión
     * post-save (id real) cuando los campos no han cambiado.
     */
    function rowFingerprint($tr) {
        var country  = ($tr.find('input.country, input[name^="tax_rate_country"]').val() || '').trim();
        var state    = ($tr.find('input.state, input[name^="tax_rate_state"]').val() || '').trim();
        var name     = ($tr.find('input.name, input[name^="tax_rate_name"]').val() || '').trim();
        var priority = ($tr.find('input.priority, input[name^="tax_rate_priority"]').val() || '').trim();
        // El input de la rate% tiene name="tax_rate" exacto; usar esa columna como ancla.
        var rate     = ($tr.find('input[name^="tax_rate"]').filter(function () {
            var n = this.name || '';
            // Filtrar tax_rate[ID] (la rate%) descartando tax_rate_country/state/name/etc.
            return /^tax_rate\[/.test(n);
        }).val() || '').trim();

        // WC normaliza la rate a 4 decimales en el server (ej. "4" -> "4.0000").
        // Normalizamos ambos lados de la comparación para que el fingerprint
        // pre-save (input "4") matchee el post-save ("4.0000").
        var rateNorm = '';
        if (rate !== '') {
            var rateFloat = parseFloat(rate);
            if (!isNaN(rateFloat)) {
                rateNorm = rateFloat.toFixed(4);
            }
        }

        if (!country && !rateNorm && !name) {
            return null;
        }
        return [country, state, rateNorm, name, priority].join('|');
    }

    function ensureHeaderColumn() {
        var $thead = $('table.wc_tax_rates thead tr').first();
        if (!$thead.length) {
            return false;
        }
        if ($thead.find('th.' + COLUMN_CLASS).length) {
            return true;
        }
        var $sortCell = $thead.find('th').last();
        var $newTh = $('<th class="' + COLUMN_CLASS + '">' + escapeHtml(config.columnLabel) + '</th>');
        if ($sortCell.length) {
            $sortCell.before($newTh);
        } else {
            $thead.append($newTh);
        }
        return true;
    }

    function ensureRowCell($tr) {
        if ($tr.find('td.' + COLUMN_CLASS).length) {
            return; // ya inyectada
        }

        var rowId = getRowId($tr);
        var currentValue = '';

        if (isTempRowId(rowId)) {
            // Fila nueva — puede tener selección pendiente por fingerprint.
            var fp = rowFingerprint($tr);
            if (fp && pendingByFingerprint[fp]) {
                currentValue = pendingByFingerprint[fp];
            }
        } else if (rowId && config.codigos) {
            currentValue = config.codigos[rowId] || '';
        }

        var $td = $('<td class="' + COLUMN_CLASS + '">' + buildSelectHtml(currentValue) + '</td>');
        $td.find('select').attr(SELECT_DATA_ATTR, rowId);

        var $lastTd = $tr.find('td').last();
        if ($lastTd.length) {
            $lastTd.before($td);
        } else {
            $tr.append($td);
        }
    }

    function ensureAllCells() {
        if (!ensureHeaderColumn()) {
            return;
        }
        $('table.wc_tax_rates tbody tr').each(function () {
            ensureRowCell($(this));
        });
    }

    /**
     * Persist inmediato para una fila con ID real, o snapshot para fila temp.
     * Llamado on `change` del select. Esto desacopla nuestra persistencia del
     * lifecycle de Backbone — la fila puede ser re-renderizada después y los
     * datos ya estarán en BD.
     */
    function bindSelectChange() {
        $(document).on('change', 'select.' + COLUMN_CLASS + '-select', function () {
            var $select = $(this);
            var $tr = $select.closest('tr');
            var rowId = $select.attr(SELECT_DATA_ATTR) || getRowId($tr);
            var value = $select.val() || '';

            if (isTempRowId(rowId)) {
                var fp = rowFingerprint($tr);
                if (fp) {
                    pendingByFingerprint[fp] = value;
                }
                return;
            }

            if (rowId && /^\d+$/.test(rowId)) {
                persistOne(rowId, value);
            }
        });
    }

    /**
     * Persist de un único par (tax_rate_id → codigo) via AJAX paralelo.
     * Actualiza también `config.codigos` local para que ensureRowCell
     * hidrate correctamente si Backbone re-renderiza la fila.
     */
    function persistOne(taxRateId, codigo) {
        var codigos = {};
        codigos[taxRateId] = codigo;
        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'fe_woo_save_codigos_tarifa_iva',
                nonce: config.nonce,
                codigos: codigos
            },
            success: function (resp) {
                if (resp && resp.success && resp.data && resp.data.saved) {
                    Object.keys(resp.data.saved).forEach(function (id) {
                        var v = resp.data.saved[id];
                        if (v) {
                            config.codigos[id] = v;
                        } else {
                            delete config.codigos[id];
                        }
                    });
                }
            }
        });
    }

    /**
     * Después del save de WC, correlacionar fingerprints pendientes con
     * filas que ahora tienen ID real, y persistir.
     */
    function persistPendingByFingerprint() {
        var fpKeys = Object.keys(pendingByFingerprint);
        if (fpKeys.length === 0) {
            return;
        }

        var codigos = {};
        $('table.wc_tax_rates tbody tr').each(function () {
            var $tr = $(this);
            var rowId = getRowId($tr);
            if (!rowId || !/^\d+$/.test(rowId)) {
                return;
            }
            var fp = rowFingerprint($tr);
            if (fp && pendingByFingerprint.hasOwnProperty(fp)) {
                codigos[rowId] = pendingByFingerprint[fp];
                delete pendingByFingerprint[fp];
            }
        });

        if (Object.keys(codigos).length === 0) {
            return;
        }

        $.ajax({
            url: config.ajaxUrl,
            method: 'POST',
            data: {
                action: 'fe_woo_save_codigos_tarifa_iva',
                nonce: config.nonce,
                codigos: codigos
            },
            success: function (resp) {
                if (resp && resp.success && resp.data && resp.data.saved) {
                    Object.keys(resp.data.saved).forEach(function (id) {
                        var v = resp.data.saved[id];
                        if (v) {
                            config.codigos[id] = v;
                        } else {
                            delete config.codigos[id];
                        }
                    });
                    // Re-render para hidratar visualmente con valores guardados.
                    ensureAllCells();
                }
            }
        });
    }

    /**
     * Hook al ajaxComplete de WC: cuando termine el save de tax rates,
     * re-inyectar la columna en filas re-renderizadas y resolver pending.
     */
    function bindWcSaveHook() {
        $(document).ajaxComplete(function (event, xhr, settings) {
            if (!settings) {
                return;
            }
            // WC 10.x pasa action=woocommerce_tax_rates_save_changes como query
            // param en la URL del AJAX, no en el POST body. Chequear ambos.
            var data = String(settings.data || '');
            var url  = String(settings.url || '');
            var marker = 'action=woocommerce_tax_rates_save_changes';
            if (data.indexOf(marker) === -1 && url.indexOf(marker) === -1) {
                return;
            }
            setTimeout(function () {
                ensureAllCells();
                persistPendingByFingerprint();
            }, 250);
        });
    }

    /**
     * MutationObserver detecta filas nuevas insertadas dinámicamente
     * (botón "Insert row") y filas re-renderizadas por Backbone.
     */
    function bindRowObserver() {
        var $tbody = $('table.wc_tax_rates tbody');
        if (!$tbody.length || typeof MutationObserver === 'undefined') {
            return;
        }
        var observer = new MutationObserver(function () {
            ensureAllCells();
        });
        observer.observe($tbody[0], { childList: true, subtree: false });
    }

    /**
     * Espera a que la tabla esté renderizada por Backbone antes de inyectar.
     */
    function waitForTable(maxAttempts, attempt) {
        attempt = attempt || 0;
        if ($('table.wc_tax_rates tbody').length) {
            ensureAllCells();
            bindSelectChange();
            bindWcSaveHook();
            bindRowObserver();
            return;
        }
        if (attempt >= maxAttempts) {
            return;
        }
        setTimeout(function () { waitForTable(maxAttempts, attempt + 1); }, 100);
    }

    $(function () {
        waitForTable(50, 0);
    });
})(jQuery);
