<?php
/**
 * Costa Rica territorial division catalog (Provincia / Cantón / Distrito).
 *
 * Provides:
 *  - Static accessors backed by data/cr-locations.json
 *  - AJAX endpoints for cascade dropdowns
 *  - XML formatting helper that matches XSD v4.4 width rules
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_CR_Locations {

    /**
     * In-process cache for the parsed catalog.
     *
     * @var array|null
     */
    private static $catalog = null;

    /**
     * Register AJAX handlers.
     */
    public static function init() {
        add_action('wp_ajax_fe_woo_get_cantones',         [__CLASS__, 'ajax_get_cantones']);
        add_action('wp_ajax_nopriv_fe_woo_get_cantones',  [__CLASS__, 'ajax_get_cantones']);
        add_action('wp_ajax_fe_woo_get_distritos',        [__CLASS__, 'ajax_get_distritos']);
        add_action('wp_ajax_nopriv_fe_woo_get_distritos', [__CLASS__, 'ajax_get_distritos']);
    }

    /**
     * Load and cache the catalog.
     *
     * @return array
     */
    private static function get_catalog() {
        if (self::$catalog !== null) {
            return self::$catalog;
        }

        $path = FE_WOO_PLUGIN_DIR . 'data/cr-locations.json';
        if (!file_exists($path)) {
            self::$catalog = [];
            return self::$catalog;
        }

        $raw  = file_get_contents($path);
        $data = json_decode($raw, true);
        self::$catalog = is_array($data) ? $data : [];
        return self::$catalog;
    }

    /**
     * Sort an associative [code => label] map ascending by numeric value of the
     * code. Padded strings ("01", "02", "10") otherwise sort lexicographically
     * which puts "10" before "01" — wrong UX in the cascade dropdowns.
     *
     * @param array $map
     * @return array
     */
    private static function sort_by_numeric_key(array $map) {
        uksort($map, function ($a, $b) {
            return intval($a) <=> intval($b);
        });
        return $map;
    }

    /**
     * Return [provincia_code => provincia_name] sorted by code.
     *
     * @return array<string, string>
     */
    public static function get_provincias() {
        $out = [];
        foreach (self::get_catalog() as $code => $entry) {
            $out[$code] = isset($entry['nombre']) ? $entry['nombre'] : $code;
        }
        return self::sort_by_numeric_key($out);
    }

    /**
     * Return [canton_code => canton_name] for a provincia, or [] if not found.
     *
     * @param string $provincia_code
     * @return array<string, string>
     */
    public static function get_cantones($provincia_code) {
        $catalog = self::get_catalog();
        $provincia_code = (string) $provincia_code;

        if (!isset($catalog[$provincia_code]['cantones'])) {
            return [];
        }

        $out = [];
        foreach ($catalog[$provincia_code]['cantones'] as $code => $entry) {
            $out[$code] = isset($entry['nombre']) ? $entry['nombre'] : $code;
        }
        return self::sort_by_numeric_key($out);
    }

    /**
     * Return [distrito_code => distrito_name] for a (provincia, canton) pair.
     *
     * @param string $provincia_code
     * @param string $canton_code
     * @return array<string, string>
     */
    public static function get_distritos($provincia_code, $canton_code) {
        $catalog = self::get_catalog();
        $provincia_code = (string) $provincia_code;
        $canton_code    = (string) $canton_code;

        if (!isset($catalog[$provincia_code]['cantones'][$canton_code]['distritos'])) {
            return [];
        }

        return self::sort_by_numeric_key($catalog[$provincia_code]['cantones'][$canton_code]['distritos']);
    }

    /**
     * @return string|null
     */
    public static function get_provincia_name($provincia_code) {
        $catalog = self::get_catalog();
        $provincia_code = (string) $provincia_code;
        return isset($catalog[$provincia_code]['nombre']) ? $catalog[$provincia_code]['nombre'] : null;
    }

    /**
     * @return string|null
     */
    public static function get_canton_name($provincia_code, $canton_code) {
        $catalog = self::get_catalog();
        $provincia_code = (string) $provincia_code;
        $canton_code    = (string) $canton_code;
        return isset($catalog[$provincia_code]['cantones'][$canton_code]['nombre'])
            ? $catalog[$provincia_code]['cantones'][$canton_code]['nombre']
            : null;
    }

    /**
     * @return string|null
     */
    public static function get_distrito_name($provincia_code, $canton_code, $distrito_code) {
        $catalog = self::get_catalog();
        $provincia_code = (string) $provincia_code;
        $canton_code    = (string) $canton_code;
        $distrito_code  = (string) $distrito_code;

        return isset($catalog[$provincia_code]['cantones'][$canton_code]['distritos'][$distrito_code])
            ? $catalog[$provincia_code]['cantones'][$canton_code]['distritos'][$distrito_code]
            : null;
    }

    /**
     * Validate that a (provincia, canton, distrito) combination exists in the catalog.
     */
    public static function validate($provincia_code, $canton_code, $distrito_code) {
        return self::get_distrito_name($provincia_code, $canton_code, $distrito_code) !== null;
    }

    /**
     * Normalize/pad a code to the same format used in the catalog (canton/distrito = 2 chars, provincia = 1 char).
     *
     * @param string $value
     * @param int    $width 1 for provincia, 2 for canton/distrito.
     * @return string
     */
    public static function pad($value, $width) {
        $numeric = preg_replace('/\D/', '', (string) $value);
        if ($numeric === '') {
            return '';
        }
        return str_pad($numeric, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Convert an associative [code => name] map to an ordered list of
     * {code, name} objects. Necessary because JS iterates object keys with
     * "integer-indexed" properties first (so "10","11",...,"20" come before
     * "01","02",...,"09" in any plain object). Arrays preserve order exactly.
     *
     * @param array $map
     * @return array<int, array{code:string,name:string}>
     */
    private static function map_to_ordered_list(array $map) {
        $out = [];
        foreach ($map as $code => $name) {
            $out[] = ['code' => (string) $code, 'name' => $name];
        }
        return $out;
    }

    /**
     * AJAX: return cantones for a given provincia code.
     */
    public static function ajax_get_cantones() {
        check_ajax_referer('fe_woo_cr_locations', 'nonce');

        $provincia = isset($_REQUEST['provincia']) ? sanitize_text_field(wp_unslash($_REQUEST['provincia'])) : '';
        $cantones = self::get_cantones($provincia);

        wp_send_json_success([
            'provincia' => $provincia,
            'cantones'  => self::map_to_ordered_list($cantones),
        ]);
    }

    /**
     * AJAX: return distritos for a (provincia, canton) pair.
     */
    public static function ajax_get_distritos() {
        check_ajax_referer('fe_woo_cr_locations', 'nonce');

        $provincia = isset($_REQUEST['provincia']) ? sanitize_text_field(wp_unslash($_REQUEST['provincia'])) : '';
        $canton    = isset($_REQUEST['canton'])    ? sanitize_text_field(wp_unslash($_REQUEST['canton']))    : '';
        $distritos = self::get_distritos($provincia, $canton);

        wp_send_json_success([
            'provincia' => $provincia,
            'canton'    => $canton,
            'distritos' => self::map_to_ordered_list($distritos),
        ]);
    }

    /**
     * Build a wp_localize_script payload (provincias + nonce + ajaxurl) so the
     * cascade JS can render the first dropdown without an extra round-trip.
     *
     * @return array
     */
    public static function get_localize_payload() {
        return [
            'ajaxUrl'    => admin_url('admin-ajax.php'),
            'nonce'      => wp_create_nonce('fe_woo_cr_locations'),
            'provincias' => self::map_to_ordered_list(self::get_provincias()),
            'i18n'       => [
                'placeholder_provincia' => __('Seleccione provincia', 'fe-woo'),
                'placeholder_canton'    => __('Seleccione cantón',    'fe-woo'),
                'placeholder_distrito'  => __('Seleccione distrito',  'fe-woo'),
                'loading'               => __('Cargando...',          'fe-woo'),
            ],
        ];
    }
}
