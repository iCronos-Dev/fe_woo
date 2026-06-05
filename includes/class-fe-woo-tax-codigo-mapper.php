<?php
/**
 * Tax rate → CodigoTarifaIVA mapper.
 *
 * Almacena la asignación explícita por `tax_rate_id` (de la tabla nativa de
 * WooCommerce) al `CodigoTarifaIVA` del catálogo Hacienda v4.4 (11 valores).
 * El mapeo numérico por rate% es ambiguo (ej. 13% puede ser '06' o '08'),
 * así que necesitamos una asignación explícita por fila.
 *
 * Storage: tabla paralela `wp_fe_woo_tax_rate_codigos`. No tocamos el schema
 * de WC. Sincronía via hook `woocommerce_tax_rate_deleted`.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Tax_Codigo_Mapper {

    const TABLE_NAME = 'fe_woo_tax_rate_codigos';
    const NONCE_ACTION = 'fe_woo_tax_codigos';

    /**
     * Catálogo de los 11 códigos válidos según v4.4.
     *
     * @return array<string, string>  [codigo => label]
     */
    public static function get_codigo_catalog() {
        return [
            '01' => __('01 — Tarifa 0% (gravado)',          'fe-woo'),
            '02' => __('02 — Tarifa reducida 1%',           'fe-woo'),
            '03' => __('03 — Tarifa reducida 2%',           'fe-woo'),
            '04' => __('04 — Tarifa reducida 4%',           'fe-woo'),
            '05' => __('05 — Transitorio 0%',               'fe-woo'),
            '06' => __('06 — Transitorio 4%',               'fe-woo'),
            '07' => __('07 — Tarifa transitoria 8%',        'fe-woo'),
            '08' => __('08 — Tarifa general 13%',           'fe-woo'),
            '09' => __('09 — Tarifa reducida 0.5%',          'fe-woo'),
            '10' => __('10 — Exento',                       'fe-woo'),
            '11' => __('11 — No sujeto',                    'fe-woo'),
        ];
    }

    /**
     * Crear la tabla paralela. Idempotente (dbDelta).
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table_name} (
            tax_rate_id bigint(20) UNSIGNED NOT NULL,
            codigo_tarifa_iva varchar(2) NOT NULL,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (tax_rate_id)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
    }

    /**
     * Obtener el código asignado a una tax_rate_id, o null si no hay.
     *
     * @param int|string $tax_rate_id
     * @return string|null
     */
    public static function get_codigo($tax_rate_id) {
        global $wpdb;

        $tax_rate_id = (int) $tax_rate_id;
        if ($tax_rate_id <= 0) {
            return null;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $codigo = $wpdb->get_var($wpdb->prepare(
            "SELECT codigo_tarifa_iva FROM {$table_name} WHERE tax_rate_id = %d",
            $tax_rate_id
        ));

        return $codigo !== null ? (string) $codigo : null;
    }

    /**
     * Asignar/actualizar el código para una tax_rate_id.
     *
     * @param int|string $tax_rate_id
     * @param string $codigo  Debe ser una key de get_codigo_catalog().
     * @return bool true si persistió.
     */
    public static function set_codigo($tax_rate_id, $codigo) {
        global $wpdb;

        $tax_rate_id = (int) $tax_rate_id;
        $codigo = (string) $codigo;

        if ($tax_rate_id <= 0) {
            return false;
        }
        if (!array_key_exists($codigo, self::get_codigo_catalog())) {
            return false;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $result = $wpdb->replace(
            $table_name,
            [
                'tax_rate_id' => $tax_rate_id,
                'codigo_tarifa_iva' => $codigo,
                'updated_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s']
        );

        return $result !== false;
    }

    /**
     * Borrar la asignación para una tax_rate_id.
     */
    public static function delete($tax_rate_id) {
        global $wpdb;

        $tax_rate_id = (int) $tax_rate_id;
        if ($tax_rate_id <= 0) {
            return false;
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        return $wpdb->delete($table_name, ['tax_rate_id' => $tax_rate_id], ['%d']) !== false;
    }

    /**
     * Bulk fetch para hidratar el JS en la página de tax rates.
     *
     * @return array<string, string>  [tax_rate_id_string => codigo]
     */
    public static function get_all_codigos() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $rows = $wpdb->get_results(
            "SELECT tax_rate_id, codigo_tarifa_iva FROM {$table_name}",
            ARRAY_A
        );

        $out = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $out[(string) $row['tax_rate_id']] = (string) $row['codigo_tarifa_iva'];
            }
        }
        return $out;
    }

    /**
     * Hook registration.
     */
    public static function init() {
        add_action('woocommerce_tax_rate_deleted', [__CLASS__, 'on_rate_deleted']);
        add_action('wp_ajax_fe_woo_save_codigos_tarifa_iva', [__CLASS__, 'ajax_save_codigos']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'maybe_enqueue']);
    }

    /**
     * Cleanup cuando WC borra una tax rate.
     */
    public static function on_rate_deleted($tax_rate_id) {
        self::delete($tax_rate_id);
    }

    /**
     * AJAX: guardar mapeo de códigos.
     *
     * Payload esperado (POST):
     *   - nonce
     *   - codigos: { tax_rate_id_1: "08", tax_rate_id_2: "10", ... }
     */
    public static function ajax_save_codigos() {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes.', 'fe-woo')], 403);
        }

        $codigos = isset($_POST['codigos']) && is_array($_POST['codigos']) ? wp_unslash($_POST['codigos']) : [];
        $catalog = self::get_codigo_catalog();
        $saved = [];
        $errors = [];

        foreach ($codigos as $tax_rate_id => $codigo) {
            $tax_rate_id = (int) $tax_rate_id;
            $codigo = sanitize_text_field((string) $codigo);

            if ($tax_rate_id <= 0) {
                continue;
            }

            // Permitir limpiar la asignación enviando string vacío.
            if ($codigo === '') {
                self::delete($tax_rate_id);
                $saved[$tax_rate_id] = '';
                continue;
            }

            if (!array_key_exists($codigo, $catalog)) {
                $errors[] = sprintf(
                    /* translators: 1: codigo recibido */
                    __('Código inválido: %s', 'fe-woo'),
                    $codigo
                );
                continue;
            }

            if (self::set_codigo($tax_rate_id, $codigo)) {
                $saved[$tax_rate_id] = $codigo;
            } else {
                $errors[] = sprintf(
                    /* translators: 1: tax_rate_id */
                    __('No se pudo guardar el código para tax_rate_id %d', 'fe-woo'),
                    $tax_rate_id
                );
            }
        }

        wp_send_json_success([
            'saved' => $saved,
            'errors' => $errors,
        ]);
    }

    /**
     * Enqueue JS solo en la página de tax rates de WC.
     */
    public static function maybe_enqueue($hook) {
        // WC settings page hook = "woocommerce_page_wc-settings".
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'tax') {
            return;
        }

        wp_enqueue_script(
            'fe-woo-tax-rates-codigo',
            FE_WOO_PLUGIN_URL . 'assets/js/tax-rates-codigo.js',
            ['jquery', 'wp-util'],
            FE_WOO_VERSION,
            true
        );

        wp_localize_script('fe-woo-tax-rates-codigo', 'feWooTaxCodigos', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce(self::NONCE_ACTION),
            'codigos' => self::get_all_codigos(),
            'catalog' => self::get_codigo_catalog(),
            'columnLabel' => __('CodigoTarifaIVA', 'fe-woo'),
            'i18n' => [
                'placeholder' => __('— Sin asignar —', 'fe-woo'),
                'savedOk'     => __('Códigos Hacienda guardados.', 'fe-woo'),
                'savedFail'   => __('Error guardando códigos Hacienda.', 'fe-woo'),
            ],
        ]);
    }
}
