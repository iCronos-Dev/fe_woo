<?php
/**
 * Product Emisor Association Class
 *
 * Manages the association between products and emisores
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Product_Emisor
 *
 * Handles product-emisor associations in product admin
 */
class FE_Woo_Product_Emisor {

    /**
     * Meta key for product emisor association
     */
    const META_KEY = '_fe_woo_emisor_id';

    /**
     * Initialize
     */
    public static function init() {
        // Add emisor field to product general tab
        add_action('woocommerce_product_options_general_product_data', [__CLASS__, 'add_product_emisor_field']);

        // Save emisor field
        add_action('woocommerce_process_product_meta', [__CLASS__, 'save_product_emisor_field']);

        // Enqueue scripts for product edit page
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);

        // AJAX handler for searching emisores
        add_action('wp_ajax_fe_woo_search_emisores_for_product', [__CLASS__, 'ajax_search_emisores']);

        // Note: Emisor column in products list removed per business requirement
        // The emisor field is still available in the product edit page
    }

    /**
     * Add emisor field to product general tab
     */
    public static function add_product_emisor_field() {
        global $post;

        $current_emisor_id = get_post_meta($post->ID, self::META_KEY, true);
        $emisores = FE_Woo_Emisor_Manager::get_all_emisores(true);

        ?>
        <div class="options_group fe-woo-product-emisor-field">
            <p class="form-field">
                <label for="fe_woo_emisor_id">
                    <?php esc_html_e('Emisor de Factura Electrónica', 'fe-woo'); ?>
                    <?php echo wc_help_tip(__('Seleccione el emisor que generará la factura para este producto. Si no selecciona ninguno, se usará el emisor por defecto.', 'fe-woo')); ?>
                </label>
                <select
                    id="fe_woo_emisor_id"
                    name="fe_woo_emisor_id"
                    class="wc-enhanced-select"
                    style="width: 50%;"
                >
                    <option value="">
                        <?php esc_html_e('Usar emisor por defecto', 'fe-woo'); ?>
                    </option>
                    <?php foreach ($emisores as $emisor) : ?>
                        <option
                            value="<?php echo esc_attr($emisor->id); ?>"
                            <?php selected($current_emisor_id, $emisor->id); ?>
                        >
                            <?php
                            echo esc_html($emisor->nombre_legal);
                            if ($emisor->is_parent) {
                                echo ' ⭐';
                            }
                            echo ' - ' . esc_html($emisor->cedula_juridica);
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </p>
        </div>
        <?php
    }

    /**
     * Save product emisor field
     *
     * @param int $product_id Product ID
     */
    public static function save_product_emisor_field($product_id) {
        // Save emisor field
        if (isset($_POST['fe_woo_emisor_id'])) {
            $emisor_id = !empty($_POST['fe_woo_emisor_id']) ? absint($_POST['fe_woo_emisor_id']) : '';

            if ($emisor_id) {
                // Verify emisor exists
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if ($emisor) {
                    update_post_meta($product_id, self::META_KEY, $emisor_id);
                } else {
                    delete_post_meta($product_id, self::META_KEY);
                }
            } else {
                // Empty value - delete meta (will use parent emisor)
                delete_post_meta($product_id, self::META_KEY);
            }
        }
    }

    /**
     * Get product emisor
     *
     * @param int $product_id Product ID
     * @return object|null Emisor object or null if using parent
     */
    public static function get_product_emisor($product_id) {
        // For variations, check the parent product for emisor assignment
        $lookup_id = $product_id;
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $lookup_id = $product->get_parent_id();
        }

        $emisor_id = get_post_meta($lookup_id, self::META_KEY, true);

        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
            // Fallback to parent if assigned emisor is deleted or inactive
            if (!$emisor || !$emisor->active) {
                return FE_Woo_Emisor_Manager::get_parent_emisor();
            }
            return $emisor;
        }

        // No emisor assigned - return parent emisor
        return FE_Woo_Emisor_Manager::get_parent_emisor();
    }

    /**
     * Get product emisor ID
     *
     * @param int $product_id Product ID
     * @return int|null Emisor ID or null if using parent
     */
    public static function get_product_emisor_id($product_id) {
        // For variations, check the parent product for emisor assignment
        $lookup_id = $product_id;
        $product = wc_get_product($product_id);
        if ($product && $product->is_type('variation')) {
            $lookup_id = $product->get_parent_id();
        }

        $emisor_id = get_post_meta($lookup_id, self::META_KEY, true);

        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor((int) $emisor_id);
            // Fallback to parent if assigned emisor is deleted or inactive
            if (!$emisor || !$emisor->active) {
                $parent = FE_Woo_Emisor_Manager::get_parent_emisor();
                return $parent ? (int) $parent->id : null;
            }
            return (int) $emisor_id;
        }

        // No emisor assigned - return parent emisor ID
        $parent = FE_Woo_Emisor_Manager::get_parent_emisor();
        return $parent ? (int) $parent->id : null;
    }

    /**
     * Add emisor column to products list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_emisor_column($columns) {
        $new_columns = [];

        foreach ($columns as $key => $column) {
            $new_columns[$key] = $column;

            // Add emisor column after price
            if ($key === 'price') {
                $new_columns['fe_woo_emisor'] = __('Emisor FE', 'fe-woo');
            }
        }

        return $new_columns;
    }

    /**
     * Render emisor column content
     *
     * @param string $column  Column name
     * @param int    $post_id Product ID
     */
    public static function render_emisor_column($column, $post_id) {
        if ($column === 'fe_woo_emisor') {
            $emisor_id = get_post_meta($post_id, self::META_KEY, true);

            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if ($emisor) {
                    echo '<span style="font-size: 12px;">';
                    if ($emisor->is_parent) {
                        echo '⭐ ';
                    }
                    echo esc_html($emisor->nombre_legal);
                    echo '</span>';
                } else {
                    echo '<span style="color: #999;">—</span>';
                }
            } else {
                $parent = FE_Woo_Emisor_Manager::get_parent_emisor();
                if ($parent) {
                    echo '<span style="color: #999; font-size: 12px;" title="' . esc_attr__('Usando emisor por defecto', 'fe-woo') . '">';
                    echo '⭐ ' . esc_html($parent->nombre_legal);
                    echo '</span>';
                } else {
                    echo '<span style="color: #dc3232; font-size: 12px;">Sin emisor</span>';
                }
            }
        }
    }

    /**
     * Enqueue scripts for product edit page
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_scripts($hook) {
        // Only on product edit page
        if (!in_array($hook, ['post.php', 'post-new.php'])) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'product') {
            return;
        }

        wp_enqueue_style(
            'fe-woo-product-emisor',
            FE_WOO_PLUGIN_URL . 'assets/css/product-emisor.css',
            [],
            FE_WOO_VERSION
        );

        wp_enqueue_script(
            'fe-woo-product-emisor',
            FE_WOO_PLUGIN_URL . 'assets/js/product-emisor.js',
            ['jquery', 'wc-enhanced-select'],
            FE_WOO_VERSION,
            true
        );

        wp_localize_script('fe-woo-product-emisor', 'feWooProductEmisor', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fe_woo_product_emisor'),
        ]);
    }

    /**
     * AJAX handler to search emisores for product
     */
    public static function ajax_search_emisores() {
        check_ajax_referer('fe_woo_product_emisor', 'nonce');

        if (!current_user_can('edit_products')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'fe-woo')]);
        }

        $search_term = !empty($_POST['search']) ? sanitize_text_field($_POST['search']) : '';

        if (empty($search_term)) {
            $emisores = FE_Woo_Emisor_Manager::get_all_emisores(true);
        } else {
            $emisores = FE_Woo_Emisor_Manager::search_emisores($search_term, true);
        }

        // Format for select2
        $results = [];
        foreach ($emisores as $emisor) {
            $results[] = [
                'id' => $emisor->id,
                'text' => sprintf(
                    '%s%s - %s',
                    $emisor->nombre_legal,
                    $emisor->is_parent ? ' ⭐' : '',
                    $emisor->cedula_juridica
                ),
            ];
        }

        wp_send_json_success(['results' => $results]);
    }

    /**
     * Get products count by emisor
     *
     * @param int $emisor_id Emisor ID
     * @return int Products count
     */
    public static function get_products_count_by_emisor($emisor_id) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
            WHERE pm.meta_key = %s
            AND pm.meta_value = %d
            AND p.post_type = 'product'
            AND p.post_status = 'publish'",
            self::META_KEY,
            $emisor_id
        ));

        return (int) $count;
    }

    /**
     * Bulk update products emisor
     *
     * @param array $product_ids Product IDs
     * @param int   $emisor_id   Emisor ID (0 to clear)
     * @return array Result with success status
     */
    public static function bulk_update_products_emisor($product_ids, $emisor_id) {
        if (empty($product_ids) || !is_array($product_ids)) {
            return [
                'success' => false,
                'message' => __('No se proporcionaron productos', 'fe-woo'),
            ];
        }

        $updated = 0;

        foreach ($product_ids as $product_id) {
            if ($emisor_id) {
                update_post_meta($product_id, self::META_KEY, $emisor_id);
            } else {
                delete_post_meta($product_id, self::META_KEY);
            }
            $updated++;
        }

        return [
            'success' => true,
            'updated' => $updated,
            'message' => sprintf(
                __('%d producto(s) actualizado(s)', 'fe-woo'),
                $updated
            ),
        ];
    }
}
