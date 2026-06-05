<?php
/**
 * FE WooCommerce Exoneración (Tax Exemption) Handler
 *
 * Manages tax exemption data for electronic invoices in Costa Rica
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Exoneracion Class
 *
 * Handles all exoneración (tax exemption) functionality
 */
class FE_Woo_Exoneracion {

    /**
     * Exoneración status constants
     */
    const STATUS_NOT_APPLICABLE = 'not_applicable';
    const STATUS_REGISTERED = 'registered';
    const STATUS_VALID = 'valid';
    const STATUS_REJECTED_VALIDATION = 'rejected_validation';
    const STATUS_REJECTED_HACIENDA = 'rejected_hacienda';

    /**
     * Exoneración types according to Hacienda
     */
    const TYPE_PURCHASE = '01'; // Compras autorizadas
    const TYPE_EXPORT = '02'; // Ventas exentas a diplomáticos
    const TYPE_DONATION = '03'; // Donaciones
    const TYPE_INCENTIVE = '04'; // Incentivos
    const TYPE_ZONE_FRANCA = '05'; // Zona Franca
    const TYPE_OTHER = '99'; // Otros

    /**
     * Allowed IVA rates for exemption (replaces standard 13%)
     */
    const ALLOWED_RATES = ['0', '1', '2', '4', '8'];

    /**
     * Initialize the exoneración functionality
     */
    public static function init() {
        // Add order meta box (admin only)
        add_action('add_meta_boxes', [__CLASS__, 'add_exoneracion_meta_box']);

        // Save meta box data - support both legacy and HPOS
        add_action('save_post', [__CLASS__, 'save_meta_box_data']);
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_order_meta'], 10, 1);
    }

    /**
     * Add exoneración meta box to order edit page
     */
    public static function add_exoneracion_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'fe_woo_exoneracion',
            __('Tax Exemption (Exoneración)', 'fe-woo'),
            [__CLASS__, 'render_exoneracion_meta_box'],
            $screen,
            'side',
            'default'
        );
    }

    /**
     * Render exoneración meta box
     */
    public static function render_exoneracion_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            return;
        }

        $order_id = $order->get_id();
        $has_exoneracion = $order->get_meta('_fe_woo_has_exoneracion') === 'yes';
        $status = $order->get_meta('_fe_woo_exoneracion_status');

        wp_nonce_field('fe_woo_exoneracion_meta_box', 'fe_woo_exoneracion_nonce');

        ?>
        <div class="fe-woo-exoneracion-box">
            <p>
                <label>
                    <input type="checkbox" name="fe_woo_has_exoneracion" value="yes" <?php checked($has_exoneracion, true); ?> id="fe_woo_has_exoneracion_admin">
                    <?php esc_html_e('Has tax exemption', 'fe-woo'); ?>
                </label>
            </p>

            <div id="fe_woo_exoneracion_admin_details" style="<?php echo $has_exoneracion ? '' : 'display:none;'; ?>">
                <p>
                    <label><?php esc_html_e('Status:', 'fe-woo'); ?></label><br>
                    <span class="fe-woo-exon-status-badge fe-woo-exon-<?php echo esc_attr($status); ?>">
                        <?php echo esc_html(self::get_status_label($status)); ?>
                    </span>
                </p>

                <p>
                    <label><?php esc_html_e('Type:', 'fe-woo'); ?></label>
                    <select name="fe_woo_exoneracion_tipo" style="width:100%;">
                        <option value=""><?php esc_html_e('Select type', 'fe-woo'); ?></option>
                        <?php
                        $tipos = self::get_tipos();
                        $selected_tipo = $order->get_meta('_fe_woo_exoneracion_tipo');
                        foreach ($tipos as $value => $label) {
                            echo '<option value="' . esc_attr($value) . '" ' . selected($selected_tipo, $value, false) . '>' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                </p>

                <p>
                    <label><?php esc_html_e('Number:', 'fe-woo'); ?></label>
                    <input type="text" name="fe_woo_exoneracion_numero" value="<?php echo esc_attr($order->get_meta('_fe_woo_exoneracion_numero')); ?>" style="width:100%;">
                </p>

                <p>
                    <label><?php esc_html_e('Institution:', 'fe-woo'); ?></label>
                    <input type="text" name="fe_woo_exoneracion_institucion" value="<?php echo esc_attr($order->get_meta('_fe_woo_exoneracion_institucion')); ?>" style="width:100%;">
                </p>

                <p>
                    <label><?php esc_html_e('Issue Date:', 'fe-woo'); ?></label>
                    <input type="date" name="fe_woo_exoneracion_fecha_emision" value="<?php echo esc_attr($order->get_meta('_fe_woo_exoneracion_fecha_emision')); ?>" style="width:100%;">
                </p>

                <p>
                    <label><?php esc_html_e('Expiration Date:', 'fe-woo'); ?></label>
                    <input type="date" name="fe_woo_exoneracion_fecha_vencimiento" value="<?php echo esc_attr($order->get_meta('_fe_woo_exoneracion_fecha_vencimiento')); ?>" style="width:100%;">
                </p>

                <p>
                    <label><?php esc_html_e('IVA Rate (replaces standard 13%):', 'fe-woo'); ?></label>
                    <select name="fe_woo_exoneracion_porcentaje" style="width:100%;">
                        <option value=""><?php esc_html_e('Select IVA rate', 'fe-woo'); ?></option>
                        <?php
                        $selected_porcentaje = $order->get_meta('_fe_woo_exoneracion_porcentaje');
                        foreach (self::ALLOWED_RATES as $rate) {
                            $label = $rate . '% ' . ($rate === '0' ? __('- Total exemption', 'fe-woo') : '');
                            echo '<option value="' . esc_attr($rate) . '" ' . selected($selected_porcentaje, $rate, false) . '>' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                    <small style="display:block;margin-top:5px;"><?php esc_html_e('The selected rate will replace the standard 13% IVA for this invoice.', 'fe-woo'); ?></small>
                </p>
            </div>
        </div>

        <style>
            .fe-woo-exon-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
            }
            .fe-woo-exon-not_applicable {
                background: #f0f0f0;
                color: #666;
            }
            .fe-woo-exon-registered {
                background: #fff9c4;
                color: #f57f17;
            }
            .fe-woo-exon-valid {
                background: #c8e6c9;
                color: #2e7d32;
            }
            .fe-woo-exon-rejected_validation,
            .fe-woo-exon-rejected_hacienda {
                background: #ffcdd2;
                color: #c62828;
            }
        </style>

        <script type="text/javascript">
        jQuery(function($) {
            $('#fe_woo_has_exoneracion_admin').change(function() {
                if ($(this).is(':checked')) {
                    $('#fe_woo_exoneracion_admin_details').slideDown();
                } else {
                    $('#fe_woo_exoneracion_admin_details').slideUp();
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Save meta box data (legacy post-based orders)
     */
    public static function save_meta_box_data($post_id) {
        // Check nonce
        if (!isset($_POST['fe_woo_exoneracion_nonce']) || !wp_verify_nonce($_POST['fe_woo_exoneracion_nonce'], 'fe_woo_exoneracion_meta_box')) {
            return;
        }

        // Check if this is a shop order
        if (get_post_type($post_id) !== 'shop_order') {
            return;
        }

        // Check permissions
        if (!current_user_can('edit_shop_order', $post_id)) {
            return;
        }

        self::save_exoneracion_data($post_id);
    }

    /**
     * Save order meta (HPOS-compatible)
     */
    public static function save_order_meta($order_id) {
        // Check nonce
        if (!isset($_POST['fe_woo_exoneracion_nonce']) || !wp_verify_nonce($_POST['fe_woo_exoneracion_nonce'], 'fe_woo_exoneracion_meta_box')) {
            return;
        }

        self::save_exoneracion_data($order_id);
    }

    /**
     * Save exoneración data (works for both legacy and HPOS)
     */
    private static function save_exoneracion_data($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $has_exoneracion = isset($_POST['fe_woo_has_exoneracion']) && $_POST['fe_woo_has_exoneracion'] === 'yes';

        $order->update_meta_data('_fe_woo_has_exoneracion', $has_exoneracion ? 'yes' : 'no');

        if ($has_exoneracion) {
            $order->update_meta_data('_fe_woo_exoneracion_tipo', sanitize_text_field($_POST['fe_woo_exoneracion_tipo'] ?? ''));
            $order->update_meta_data('_fe_woo_exoneracion_numero', sanitize_text_field($_POST['fe_woo_exoneracion_numero'] ?? ''));
            $order->update_meta_data('_fe_woo_exoneracion_institucion', sanitize_text_field($_POST['fe_woo_exoneracion_institucion'] ?? ''));
            $order->update_meta_data('_fe_woo_exoneracion_fecha_emision', sanitize_text_field($_POST['fe_woo_exoneracion_fecha_emision'] ?? ''));
            $order->update_meta_data('_fe_woo_exoneracion_fecha_vencimiento', sanitize_text_field($_POST['fe_woo_exoneracion_fecha_vencimiento'] ?? ''));
            $order->update_meta_data('_fe_woo_exoneracion_porcentaje', intval($_POST['fe_woo_exoneracion_porcentaje'] ?? 0));

            // Validate after saving
            $validation = self::validate_exoneracion($order_id);
            if ($validation['valid']) {
                $order->update_meta_data('_fe_woo_exoneracion_status', self::STATUS_VALID);
            } else {
                $order->update_meta_data('_fe_woo_exoneracion_status', self::STATUS_REJECTED_VALIDATION);
                $order->update_meta_data('_fe_woo_exoneracion_validation_errors', $validation['errors']);
            }
        } else {
            $order->update_meta_data('_fe_woo_exoneracion_status', self::STATUS_NOT_APPLICABLE);
        }

        $order->save();
    }

    /**
     * Validate exoneración data
     *
     * @param int $order_id Order ID
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validate_exoneracion($order_id) {
        $order = wc_get_order($order_id);
        $errors = [];

        if (!$order || $order->get_meta('_fe_woo_has_exoneracion') !== 'yes') {
            return ['valid' => true, 'errors' => []];
        }

        // Check required fields (use isset/strlen for porcentaje since 0 is a valid value)
        $text_required_fields = [
            '_fe_woo_exoneracion_tipo' => __('Exemption type', 'fe-woo'),
            '_fe_woo_exoneracion_numero' => __('Exemption number', 'fe-woo'),
            '_fe_woo_exoneracion_institucion' => __('Institution', 'fe-woo'),
            '_fe_woo_exoneracion_fecha_emision' => __('Issue date', 'fe-woo'),
            '_fe_woo_exoneracion_fecha_vencimiento' => __('Expiration date', 'fe-woo'),
        ];

        foreach ($text_required_fields as $field => $label) {
            if (empty($order->get_meta($field))) {
                $errors[] = sprintf(__('%s is required', 'fe-woo'), $label);
            }
        }

        // Validate porcentaje separately: 0 is a valid value, so check for empty string or null
        $porcentaje_raw = $order->get_meta('_fe_woo_exoneracion_porcentaje');
        if ($porcentaje_raw === '' || $porcentaje_raw === null || $porcentaje_raw === false) {
            $errors[] = __('Percentage is required', 'fe-woo');
        }

        // Validate dates
        $fecha_emision = $order->get_meta('_fe_woo_exoneracion_fecha_emision');
        $fecha_vencimiento = $order->get_meta('_fe_woo_exoneracion_fecha_vencimiento');

        if ($fecha_emision && $fecha_vencimiento) {
            $emision_time = strtotime($fecha_emision);
            $vencimiento_time = strtotime($fecha_vencimiento);
            $now = time();

            if ($vencimiento_time < $emision_time) {
                $errors[] = __('Expiration date cannot be before issue date', 'fe-woo');
            }

            if ($vencimiento_time < $now) {
                $errors[] = __('Exemption has expired', 'fe-woo');
            }
        }

        // Validate IVA rate (must be one of the allowed values)
        $porcentaje = $order->get_meta('_fe_woo_exoneracion_porcentaje');
        if ($porcentaje !== '' && !in_array((string)$porcentaje, self::ALLOWED_RATES, true)) {
            $errors[] = __('Invalid IVA rate. Must be 0%, 1%, 2%, 4%, or 8%.', 'fe-woo');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Get exemption types
     *
     * @return array Array of types
     */
    public static function get_tipos() {
        return [
            self::TYPE_PURCHASE => __('Authorized Purchases', 'fe-woo'),
            self::TYPE_EXPORT => __('Diplomatic Sales', 'fe-woo'),
            self::TYPE_DONATION => __('Donations', 'fe-woo'),
            self::TYPE_INCENTIVE => __('Incentives', 'fe-woo'),
            self::TYPE_ZONE_FRANCA => __('Free Zone', 'fe-woo'),
            self::TYPE_OTHER => __('Other', 'fe-woo'),
        ];
    }

    /**
     * Get status label
     *
     * @param string $status Status code
     * @return string Status label
     */
    public static function get_status_label($status) {
        $labels = [
            self::STATUS_NOT_APPLICABLE => __('Not Applicable', 'fe-woo'),
            self::STATUS_REGISTERED => __('Registered', 'fe-woo'),
            self::STATUS_VALID => __('Valid', 'fe-woo'),
            self::STATUS_REJECTED_VALIDATION => __('Rejected by Validation', 'fe-woo'),
            self::STATUS_REJECTED_HACIENDA => __('Rejected by Hacienda', 'fe-woo'),
        ];

        return isset($labels[$status]) ? $labels[$status] : __('Unknown', 'fe-woo');
    }

    /**
     * Get exoneración data for order
     *
     * @param int $order_id Order ID
     * @return array|null Exoneración data or null if not applicable
     */
    public static function get_exoneracion_data($order_id) {
        $order = wc_get_order($order_id);

        if (!$order || $order->get_meta('_fe_woo_has_exoneracion') !== 'yes') {
            return null;
        }

        return [
            'tipo' => $order->get_meta('_fe_woo_exoneracion_tipo'),
            'numero' => $order->get_meta('_fe_woo_exoneracion_numero'),
            'institucion' => $order->get_meta('_fe_woo_exoneracion_institucion'),
            'fecha_emision' => $order->get_meta('_fe_woo_exoneracion_fecha_emision'),
            'fecha_vencimiento' => $order->get_meta('_fe_woo_exoneracion_fecha_vencimiento'),
            'porcentaje' => $order->get_meta('_fe_woo_exoneracion_porcentaje'),
            'status' => $order->get_meta('_fe_woo_exoneracion_status'),
        ];
    }
}
