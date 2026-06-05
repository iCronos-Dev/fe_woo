<?php
/**
 * Product TipoTransaccion association class.
 *
 * Adds a `TipoTransaccion` field to the WooCommerce product editor and
 * persists it as product post meta. The factura generator reads it via
 * `get_for_product()`, falling back to the default `'01'` (Venta Normal)
 * when not assigned. Mirrors `FE_Woo_Product_CABYS` for consistency.
 *
 * Codes follow XSD v4.4 `TipoTransaccionType` (enum 01-13).
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Product_Tipo_Transaccion {

    const META_KEY     = '_fe_woo_tipo_transaccion';
    const DEFAULT_CODE = '01';

    /**
     * XSD v4.4 TipoTransaccionType enum (13 valores).
     *
     * @return array<string, string>  [codigo => label]
     */
    public static function get_catalog() {
        return [
            '01' => __('01 — Venta Normal de Bienes y Servicios',                                       'fe-woo'),
            '02' => __('02 — Mercancía de Autoconsumo exento',                                          'fe-woo'),
            '03' => __('03 — Mercancía de Autoconsumo gravado',                                         'fe-woo'),
            '04' => __('04 — Servicio de Autoconsumo exento',                                           'fe-woo'),
            '05' => __('05 — Servicio de Autoconsumo gravado',                                          'fe-woo'),
            '06' => __('06 — Cuota de afiliación',                                                      'fe-woo'),
            '07' => __('07 — Cuota de afiliación Exenta',                                               'fe-woo'),
            '08' => __('08 — Bienes de Capital para el emisor',                                         'fe-woo'),
            '09' => __('09 — Bienes de Capital para el receptor',                                       'fe-woo'),
            '10' => __('10 — Bienes de Capital para emisor y receptor',                                 'fe-woo'),
            '11' => __('11 — Bienes de capital de autoconsumo exento para emisor',                      'fe-woo'),
            '12' => __('12 — Bienes de capital sin contraprestación a terceros exento para emisor',     'fe-woo'),
            '13' => __('13 — Sin contraprestación a terceros',                                          'fe-woo'),
        ];
    }

    public static function init() {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_field']);
        add_action('woocommerce_process_product_meta',                 [__CLASS__, 'save_field']);
    }

    /**
     * Render select inside the product general tab.
     */
    public static function render_field() {
        global $post;

        $current = (string) get_post_meta($post->ID, self::META_KEY, true);
        $catalog = self::get_catalog();
        ?>
        <div class="options_group fe-woo-product-tipo-transaccion">
            <p class="form-field">
                <label for="fe_woo_tipo_transaccion">
                    <?php esc_html_e('Tipo Transacción', 'fe-woo'); ?>
                    <?php echo wc_help_tip(__('Default: 01 — Venta Normal. Cambiar solo si el producto requiere otro código (autoconsumo, bienes de capital, cuotas de afiliación, etc.). Se emite en cada línea del XML como LineaDetalle/TipoTransaccion (XSD v4.4).', 'fe-woo')); ?>
                </label>
                <select
                    id="fe_woo_tipo_transaccion"
                    name="fe_woo_tipo_transaccion"
                    class="short fe-woo-tipo-transaccion-select"
                >
                    <option value=""><?php
                        printf(
                            /* translators: %s: default code */
                            esc_html__('— Default (%s — Venta Normal) —', 'fe-woo'),
                            esc_html(self::DEFAULT_CODE)
                        );
                    ?></option>
                    <?php foreach ($catalog as $codigo => $label): ?>
                        <option value="<?php echo esc_attr($codigo); ?>" <?php selected($current, $codigo); ?>>
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Persist on product save. Empty / invalid → delete meta (vuelve al default).
     *
     * @param int $product_id Product ID.
     */
    public static function save_field($product_id) {
        if (!current_user_can('edit_product', $product_id)) {
            return;
        }

        $raw = isset($_POST['fe_woo_tipo_transaccion'])
            ? sanitize_text_field(wp_unslash($_POST['fe_woo_tipo_transaccion']))
            : '';

        if ($raw === '' || !array_key_exists($raw, self::get_catalog())) {
            delete_post_meta($product_id, self::META_KEY);
            return;
        }

        update_post_meta($product_id, self::META_KEY, $raw);
    }

    /**
     * Resolver con default — usado por el generador.
     *
     * @param WC_Product|int|null $product Product object or ID.
     * @return string Codigo de 2 dígitos del enum (default DEFAULT_CODE).
     */
    public static function get_for_product($product) {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;

        if (!$product instanceof WC_Product) {
            return self::DEFAULT_CODE;
        }

        $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $val       = (string) get_post_meta($lookup_id, self::META_KEY, true);

        if ($val !== '' && array_key_exists($val, self::get_catalog())) {
            return $val;
        }

        return self::DEFAULT_CODE;
    }
}
