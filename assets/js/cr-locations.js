/**
 * Costa Rica locations cascade widget.
 *
 * Wires up Provincia → Cantón → Distrito selects for any container that
 * declares `data-fe-cr-locations="1"`. Pulls cantones/distritos via AJAX so we
 * don't need to ship the full ~474-row catalog to every page.
 *
 * Expected globals (set via wp_localize_script as `feWooCrLocations`):
 *   ajaxUrl, nonce, provincias { code: name }, i18n {...}
 *
 * Expected DOM inside each container:
 *   <select name="fe_woo_provincia" data-fe-cr-role="provincia">
 *   <select name="fe_woo_canton"    data-fe-cr-role="canton" disabled>
 *   <select name="fe_woo_distrito"  data-fe-cr-role="distrito" disabled>
 *
 * Optional data-attributes on the container for hydration:
 *   data-initial-provincia, data-initial-canton, data-initial-distrito
 *
 * Globals: jQuery (used opportunistically for select2 enhancement).
 */
(function () {
    'use strict';

    if (typeof window.feWooCrLocations === 'undefined') {
        return;
    }

    var config = window.feWooCrLocations;

    function el(role, container) {
        return container.querySelector('[data-fe-cr-role="' + role + '"]');
    }

    function setOptions(select, items, placeholder) {
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder || '';
        select.appendChild(ph);

        // `items` is an ordered list of { code, name } objects. The server
        // sends a list (not a plain object) on purpose — see the PHP-side
        // comment in map_to_ordered_list().
        var list = Array.isArray(items) ? items : [];
        list.forEach(function (entry) {
            var opt = document.createElement('option');
            opt.value = entry.code;
            opt.textContent = entry.name;
            select.appendChild(opt);
        });
    }

    function disable(select, placeholder) {
        select.disabled = true;
        select.innerHTML = '';
        var ph = document.createElement('option');
        ph.value = '';
        ph.textContent = placeholder || '';
        select.appendChild(ph);
        triggerSelect2Update(select);
    }

    function enable(select) {
        select.disabled = false;
        triggerSelect2Update(select);
    }

    function applySelect2(select) {
        if (typeof window.jQuery !== 'undefined' && typeof window.jQuery.fn.select2 === 'function') {
            try {
                window.jQuery(select).select2({
                    width: '100%',
                    placeholder: select.options[0] ? select.options[0].textContent : '',
                    allowClear: true,
                    dropdownAutoWidth: true
                });
            } catch (e) {
                // select2 already attached or not available — ignore.
            }
        }
    }

    function triggerSelect2Update(select) {
        if (typeof window.jQuery !== 'undefined' && window.jQuery(select).hasClass('select2-hidden-accessible')) {
            window.jQuery(select).trigger('change.select2');
        }
    }

    function fetchJson(action, params) {
        var body = new URLSearchParams();
        body.set('action', action);
        body.set('nonce', config.nonce);
        Object.keys(params).forEach(function (k) { body.set(k, params[k]); });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function loadCantones(container, provinciaCode, preselect) {
        var canton = el('canton', container);
        var distrito = el('distrito', container);

        disable(distrito, config.i18n.placeholder_distrito);

        if (!provinciaCode) {
            disable(canton, config.i18n.placeholder_canton);
            return;
        }

        setOptions(canton, {}, config.i18n.loading);
        canton.disabled = true;
        triggerSelect2Update(canton);

        fetchJson('fe_woo_get_cantones', { provincia: provinciaCode })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    disable(canton, config.i18n.placeholder_canton);
                    return;
                }
                setOptions(canton, resp.data.cantones, config.i18n.placeholder_canton);
                enable(canton);
                if (preselect) {
                    canton.value = preselect;
                    triggerSelect2Update(canton);
                    if (canton.value === preselect) {
                        loadDistritos(container, provinciaCode, preselect, container.dataset.initialDistrito || '');
                    }
                }
            });
    }

    function loadDistritos(container, provinciaCode, cantonCode, preselect) {
        var distrito = el('distrito', container);

        if (!provinciaCode || !cantonCode) {
            disable(distrito, config.i18n.placeholder_distrito);
            return;
        }

        setOptions(distrito, {}, config.i18n.loading);
        distrito.disabled = true;
        triggerSelect2Update(distrito);

        fetchJson('fe_woo_get_distritos', { provincia: provinciaCode, canton: cantonCode })
            .then(function (resp) {
                if (!resp || !resp.success) {
                    disable(distrito, config.i18n.placeholder_distrito);
                    return;
                }
                setOptions(distrito, resp.data.distritos, config.i18n.placeholder_distrito);
                enable(distrito);
                if (preselect) {
                    distrito.value = preselect;
                    triggerSelect2Update(distrito);
                }
            });
    }

    function init(container) {
        if (container.dataset.feCrInitialized === '1') {
            return;
        }
        container.dataset.feCrInitialized = '1';

        var provincia = el('provincia', container);
        var canton    = el('canton', container);
        var distrito  = el('distrito', container);

        if (!provincia || !canton || !distrito) {
            return;
        }

        // Populate provincias from localized config.
        setOptions(provincia, config.provincias, config.i18n.placeholder_provincia);
        disable(canton, config.i18n.placeholder_canton);
        disable(distrito, config.i18n.placeholder_distrito);

        applySelect2(provincia);
        applySelect2(canton);
        applySelect2(distrito);

        // Bind change with jQuery when available — select2 triggers `change`
        // through jQuery, and jQuery 3.x does NOT fire native addEventListener
        // handlers for programmatic .trigger() events. Without this we'd never
        // see the user's select2 selection and the cantón would stay disabled.
        if (typeof window.jQuery !== 'undefined') {
            window.jQuery(provincia).on('change', function () {
                loadCantones(container, provincia.value, '');
            });
            window.jQuery(canton).on('change', function () {
                loadDistritos(container, provincia.value, canton.value, '');
            });
        } else {
            provincia.addEventListener('change', function () {
                loadCantones(container, provincia.value, '');
            });
            canton.addEventListener('change', function () {
                loadDistritos(container, provincia.value, canton.value, '');
            });
        }

        // Hydrate from data-initial-* (e.g. when editing an existing order).
        var initialProv = container.dataset.initialProvincia || '';
        var initialCanton = container.dataset.initialCanton || '';
        if (initialProv) {
            provincia.value = initialProv;
            triggerSelect2Update(provincia);
            loadCantones(container, initialProv, initialCanton);
        }
    }

    function initAll() {
        var containers = document.querySelectorAll('[data-fe-cr-locations="1"]');
        containers.forEach(init);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }

    // Expose for surfaces that inject the markup dynamically (e.g. POS modal).
    window.feWooCrLocationsInit = initAll;
})();
