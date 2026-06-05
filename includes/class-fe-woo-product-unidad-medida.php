<?php
/**
 * Product UnidadMedidaComercial association class (v1.15.0).
 *
 * Adds a per-product dropdown in the WooCommerce product editor's "Shipping"
 * tab so the merchant can choose the commercial unit of measure declared in
 * the electronic invoice (`<UnidadMedidaComercial>` in XSD v4.4).
 *
 * Behavior:
 *   - Virtual products → always emit "Unid" (no dropdown shown; WC hides the
 *     Shipping tab for virtual products anyway).
 *   - Physical products → use the configured value, or "Unid" as default.
 *
 * The catalog is curated from XSD v4.4 `UnidadMedidaType` (covering ~95
 * values). UnidadMedidaComercial itself is free text in the XSD (`xs:string
 * maxLength=20`); we use the same enum as UnidadMedida so the value is
 * recognized by Hacienda's catalog and stays consistent.
 *
 * @package FE_Woo
 * @since   1.15.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Product_Unidad_Medida {

    const META_KEY     = '_fe_woo_unidad_medida_comercial';
    const DEFAULT_CODE = 'Unid';

    /**
     * Subset curado del enum XSD `UnidadMedidaType`. Cubre los casos comunes
     * en e-commerce; ampliable si hace falta.
     *
     * @return array<string, string>  [codigo => label_humano]
     */
    public static function get_catalog() {
        return [
            'Unid'  => __('Unidad', 'fe-woo'),
            'Kg'    => __('Kilogramo (Kg)', 'fe-woo'),
            't'     => __('Tonelada (t)', 'fe-woo'),
            'L'     => __('Litro (L)', 'fe-woo'),
            'mL'    => __('Mililitro (mL)', 'fe-woo'),
            'M'     => __('Metro (m)', 'fe-woo'),
            'cm'    => __('Centímetro (cm)', 'fe-woo'),
            'Mm'    => __('Milímetro (mm)', 'fe-woo'),
            'm²'    => __('Metro cuadrado (m²)', 'fe-woo'),
            'm³'    => __('Metro cúbico (m³)', 'fe-woo'),
            'Km'    => __('Kilómetro (Km)', 'fe-woo'),
            'Os'    => __('Onza', 'fe-woo'),
            'Min'   => __('Minuto', 'fe-woo'),
            'h'     => __('Hora (h)', 'fe-woo'),
            'd'     => __('Día (d)', 'fe-woo'),
            'Sp'    => __('Sin especificar (Sp)', 'fe-woo'),
            'Spe'   => __('Servicios profesionales (Spe)', 'fe-woo'),
            'Otros' => __('Otros', 'fe-woo'),
        ];
    }

    public static function init() {
        // El dropdown va en la pestaña Shipping (WC la oculta para virtuales).
        add_action('woocommerce_product_options_shipping', [__CLASS__, 'render_field']);
        add_action('woocommerce_process_product_meta',      [__CLASS__, 'save_field']);
    }

    /**
     * Render select inside the product Shipping tab.
     */
    public static function render_field() {
        global $post;

        $current = (string) get_post_meta($post->ID, self::META_KEY, true);
        $catalog = self::get_catalog();
        ?>
        <div class="options_group fe-woo-product-unidad-medida">
            <p class="form-field">
                <label for="fe_woo_unidad_medida_comercial">
                    <?php esc_html_e('Unidad de medida (FE)', 'fe-woo'); ?>
                    <?php echo wc_help_tip(__('Unidad declarada en la factura electrónica como UnidadMedidaComercial. Si el producto es virtual se emite "Unid" automáticamente. Catálogo basado en XSD v4.4.', 'fe-woo')); ?>
                </label>
                <select
                    id="fe_woo_unidad_medida_comercial"
                    name="fe_woo_unidad_medida_comercial"
                    class="short fe-woo-unidad-medida-select"
                >
                    <option value=""><?php
                        printf(
                            /* translators: %s: default code */
                            esc_html__('— Default (%s — Unidad) —', 'fe-woo'),
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

        $raw = isset($_POST['fe_woo_unidad_medida_comercial'])
            ? sanitize_text_field(wp_unslash($_POST['fe_woo_unidad_medida_comercial']))
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
     * Reglas:
     *   - Virtual → siempre DEFAULT_CODE ("Unid").
     *   - Físico  → meta del producto, o DEFAULT_CODE si no hay valor válido.
     *
     * @param WC_Product|int|null $product Product object or ID.
     * @return string Codigo del catálogo (default "Unid", máx 20 chars).
     */
    public static function get_for_product($product) {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;

        if (!$product instanceof WC_Product) {
            return self::DEFAULT_CODE;
        }

        if ($product->is_virtual()) {
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
