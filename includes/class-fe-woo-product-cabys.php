<?php
/**
 * Product CABYS Association Class
 *
 * Adds a CABYS code field to the WooCommerce product editor and persists it as
 * product post meta. The factura/PDF generators read the CABYS directly from
 * this meta — replacing the previous coupling between WooCommerce tax classes
 * and CABYS classification.
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Product_CABYS
 *
 * Handles per-product CABYS classification.
 */
class FE_Woo_Product_CABYS {

    /**
     * Meta key for the CABYS code (13 digits).
     */
    const META_KEY_CODE = '_fe_woo_cabys_code';

    /**
     * Meta key for the cached CABYS description (UI/PDF).
     */
    const META_KEY_DESCRIPTION = '_fe_woo_cabys_descripcion';

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'render_field']);
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_field']);

        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);

        add_action('wp_ajax_fe_woo_search_cabys', [__CLASS__, 'ajax_search_cabys']);
    }

    /**
     * Render the CABYS field group inside the product general tab.
     */
    public static function render_field() {
        global $post;

        $code        = (string) get_post_meta($post->ID, self::META_KEY_CODE, true);
        $description = (string) get_post_meta($post->ID, self::META_KEY_DESCRIPTION, true);
        ?>
        <div class="options_group fe-woo-product-cabys">
            <p class="form-field">
                <label for="fe_woo_cabys_code">
                    <?php esc_html_e('Código CABYS', 'fe-woo'); ?>
                    <?php echo wc_help_tip(__('Clasificación CABYS de Hacienda (13 dígitos). Buscá por código exacto o por descripción del bien/servicio. Este código se usa en el XML de la factura electrónica.', 'fe-woo')); ?>
                </label>
                <input
                    type="text"
                    id="fe_woo_cabys_code"
                    name="fe_woo_cabys_code"
                    class="short fe-woo-cabys-code-input"
                    value="<?php echo esc_attr($code); ?>"
                    pattern="\d{13}"
                    maxlength="13"
                    placeholder="<?php esc_attr_e('Ej: 6423100000000', 'fe-woo'); ?>"
                />
            </p>
            <p class="form-field fe-woo-cabys-search-field">
                <label for="fe_woo_cabys_search">
                    <?php esc_html_e('Buscar CABYS', 'fe-woo'); ?>
                </label>
                <input
                    type="text"
                    id="fe_woo_cabys_search"
                    class="short fe-woo-cabys-search-input"
                    placeholder="<?php esc_attr_e('Escribí descripción o código (mín. 2 caracteres)', 'fe-woo'); ?>"
                    autocomplete="off"
                />
                <span class="spinner fe-woo-cabys-spinner"></span>
            </p>
            <p class="form-field fe-woo-cabys-description-field">
                <label for="fe_woo_cabys_description">
                    <?php esc_html_e('Descripción CABYS', 'fe-woo'); ?>
                </label>
                <input
                    type="text"
                    id="fe_woo_cabys_description"
                    name="fe_woo_cabys_description"
                    class="short fe-woo-cabys-description-input"
                    value="<?php echo esc_attr($description); ?>"
                    readonly
                />
            </p>
            <div id="fe-woo-cabys-results" class="fe-woo-cabys-results"></div>
        </div>
        <?php
    }

    /**
     * Persist CABYS code and description on product save.
     *
     * @param int $product_id Product ID.
     */
    public static function save_field($product_id) {
        if (!current_user_can('edit_product', $product_id)) {
            return;
        }

        $raw_code = isset($_POST['fe_woo_cabys_code']) ? sanitize_text_field(wp_unslash($_POST['fe_woo_cabys_code'])) : '';
        $code     = preg_replace('/\D/', '', $raw_code);

        if ($code === '') {
            delete_post_meta($product_id, self::META_KEY_CODE);
            delete_post_meta($product_id, self::META_KEY_DESCRIPTION);
            return;
        }

        // Hacienda CABYS codes are exactly 13 digits — silently ignore malformed input.
        if (strlen($code) !== 13) {
            return;
        }

        update_post_meta($product_id, self::META_KEY_CODE, $code);

        if (isset($_POST['fe_woo_cabys_description'])) {
            $description = sanitize_text_field(wp_unslash($_POST['fe_woo_cabys_description']));
            if ($description !== '') {
                update_post_meta($product_id, self::META_KEY_DESCRIPTION, $description);
            } else {
                delete_post_meta($product_id, self::META_KEY_DESCRIPTION);
            }
        }
    }

    /**
     * Get the CABYS code stored on a product (or its parent for variations).
     *
     * @param WC_Product|int|null $product Product object or ID.
     * @return string CABYS code (13 digits) or empty string when not configured.
     */
    public static function get_product_cabys_code($product) {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;

        if (!$product instanceof WC_Product) {
            return '';
        }

        $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();
        $code      = (string) get_post_meta($lookup_id, self::META_KEY_CODE, true);

        return $code;
    }

    /**
     * Get the CABYS description stored on a product (or its parent for variations).
     *
     * @param WC_Product|int|null $product Product object or ID.
     * @return string CABYS description or empty string.
     */
    public static function get_product_cabys_description($product) {
        $product = is_numeric($product) ? wc_get_product((int) $product) : $product;

        if (!$product instanceof WC_Product) {
            return '';
        }

        $lookup_id = $product->is_type('variation') ? $product->get_parent_id() : $product->get_id();

        return (string) get_post_meta($lookup_id, self::META_KEY_DESCRIPTION, true);
    }

    /**
     * Enqueue admin scripts only on the product edit screen.
     *
     * @param string $hook Current admin page hook.
     */
    public static function enqueue_scripts($hook) {
        if (!in_array($hook, ['post.php', 'post-new.php'], true)) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_style(
            'fe-woo-product-cabys',
            FE_WOO_PLUGIN_URL . 'assets/css/product-cabys.css',
            [],
            FE_WOO_VERSION
        );

        wp_enqueue_script(
            'fe-woo-product-cabys',
            FE_WOO_PLUGIN_URL . 'assets/js/product-cabys.js',
            ['jquery'],
            FE_WOO_VERSION,
            true
        );

        wp_localize_script('fe-woo-product-cabys', 'feWooProductCABYS', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('fe_woo_product_cabys'),
            'strings' => [
                'noResults' => __('No se encontraron códigos CABYS.', 'fe-woo'),
                'error'     => __('Error al buscar códigos CABYS.', 'fe-woo'),
                'searching' => __('Buscando…', 'fe-woo'),
            ],
        ]);
    }

    /**
     * AJAX handler for CABYS search against Hacienda's API.
     */
    public static function ajax_search_cabys() {
        check_ajax_referer('fe_woo_product_cabys', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'fe-woo')]);
        }

        $query = isset($_POST['query']) ? sanitize_text_field(wp_unslash($_POST['query'])) : '';

        if ($query === '') {
            wp_send_json_error(['message' => __('Se requiere un término de búsqueda.', 'fe-woo')]);
        }

        $api_endpoint = FE_Woo_Hacienda_Config::get_cabys_api_endpoint();

        $is_numeric       = ctype_digit(trim($query));
        $is_complete_code = $is_numeric && strlen(trim($query)) === 13;
        $search_param     = $is_complete_code ? 'codigo' : 'q';

        $response = wp_remote_get(
            add_query_arg($search_param, $query, $api_endpoint),
            [
                'timeout' => 15,
                'headers' => ['Accept' => 'application/json'],
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error(['message' => __('Respuesta JSON inválida de la API.', 'fe-woo')]);
        }

        if (isset($data['code']) && $data['code'] >= 400) {
            if ($is_numeric && !$is_complete_code) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: the search query */
                        __('Los códigos CABYS deben tener 13 dígitos completos. "%s" es un código parcial. Buscá por descripción del producto o servicio.', 'fe-woo'),
                        $query
                    ),
                    'hint'    => __('Ejemplo: "transporte pasajeros", "evento", "blusa"', 'fe-woo'),
                ]);
            }

            wp_send_json_error([
                'message' => isset($data['status']) ? $data['status'] : __('Error en la búsqueda.', 'fe-woo'),
            ]);
        }

        $cabys_list = [];
        if (is_array($data)) {
            if (isset($data['cabys']) && is_array($data['cabys'])) {
                $cabys_list = $data['cabys'];
            } elseif (isset($data[0])) {
                $cabys_list = $data;
            }
        }

        wp_send_json_success([
            'results' => $cabys_list,
            'total'   => count($cabys_list),
        ]);
    }
}
