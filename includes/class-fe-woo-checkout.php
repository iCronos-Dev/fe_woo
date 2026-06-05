<?php
/**
 * FE WooCommerce Checkout Fields
 *
 * Handles custom checkout fields for electronic invoicing (Factura Electrónica)
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Checkout Class
 *
 * Adds custom fields to WooCommerce checkout for electronic invoice data collection
 */
class FE_Woo_Checkout {

    /**
     * Identification type constants
     */
    const ID_TYPE_CEDULA_FISICA = '01';
    const ID_TYPE_CEDULA_JURIDICA = '02';
    const ID_TYPE_DIMEX = '03';
    const ID_TYPE_PASAPORTE = '04';

    /**
     * Initialize the checkout fields
     */
    public static function init() {
        // Add custom fields to checkout - using single reliable hook
        add_action('woocommerce_before_order_notes', [__CLASS__, 'add_factura_electronica_fields'], 10);

        // Validate custom fields
        add_action('woocommerce_checkout_process', [__CLASS__, 'validate_factura_electronica_fields']);

        // Save custom fields to order meta
        add_action('woocommerce_checkout_create_order', [__CLASS__, 'save_factura_electronica_fields'], 10, 2);

        // Display editable custom fields in order admin
        add_action('woocommerce_admin_order_data_after_billing_address', [__CLASS__, 'display_factura_electronica_admin_fields'], 10, 1);

        // Save custom fields from admin order edit
        add_action('woocommerce_process_shop_order_meta', [__CLASS__, 'save_factura_electronica_admin_fields'], 10, 1);

        // Enqueue checkout scripts
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_checkout_scripts']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);

        // AJAX handler for autocomplete
        add_action('wp_ajax_fe_woo_checkout_autocomplete', [__CLASS__, 'ajax_checkout_autocomplete']);

        // AJAX handler for admin order - get user factura data
        add_action('wp_ajax_fe_woo_get_user_factura_data', [__CLASS__, 'ajax_get_user_factura_data']);

        // AJAX handler for admin Hacienda API lookup by ID number
        add_action('wp_ajax_fe_woo_admin_hacienda_lookup', [__CLASS__, 'ajax_admin_hacienda_lookup']);

        // AJAX handler for checkout load button (logged-in and guest)
        add_action('wp_ajax_fe_woo_load_factura', [__CLASS__, 'ajax_load_factura']);
        add_action('wp_ajax_nopriv_fe_woo_load_factura', [__CLASS__, 'ajax_load_factura']);
    }

    /**
     * Enqueue checkout scripts and styles
     */
    public static function enqueue_checkout_scripts() {
        if (is_checkout()) {
            wp_enqueue_script(
                'fe-woo-checkout',
                FE_WOO_PLUGIN_URL . 'assets/js/checkout.js',
                ['jquery'],
                FE_WOO_VERSION,
                true
            );

            // Localize script with AJAX data for autocomplete
            wp_localize_script('fe-woo-checkout', 'fe_woo_checkout', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fe_woo_autocomplete'),
                'load_nonce' => wp_create_nonce('fe_woo_load_factura'),
            ]);

            wp_enqueue_style(
                'fe-woo-checkout',
                FE_WOO_PLUGIN_URL . 'assets/css/checkout.css',
                [],
                FE_WOO_VERSION
            );

            // CR ubicacion cascade (Provincia/Cantón/Distrito).
            wp_enqueue_script(
                'fe-woo-cr-locations',
                FE_WOO_PLUGIN_URL . 'assets/js/cr-locations.js',
                ['jquery'],
                FE_WOO_VERSION,
                true
            );
            wp_localize_script('fe-woo-cr-locations', 'feWooCrLocations', FE_Woo_CR_Locations::get_localize_payload());
        }
    }

    /**
     * Add factura electrónica fields to checkout
     *
     * @param WC_Checkout $checkout Checkout object
     */
    public static function add_factura_electronica_fields($checkout) {
        // Check if checkout form is enabled in settings
        if (get_option(FE_Woo_Hacienda_Config::OPTION_ENABLE_CHECKOUT_FORM, 'yes') !== 'yes') {
            return;
        }

        if (!function_exists('woocommerce_form_field')) {
            return;
        }

        // Get user saved data if user is logged in
        $user_id = get_current_user_id();
        $user_data = [];
        if ($user_id) {
            $user_data = [
                'id_type' => get_user_meta($user_id, 'fe_woo_id_type', true),
                'id_number' => get_user_meta($user_id, 'fe_woo_id_number', true),
            ];
        }

        echo '<div id="fe_woo_factura_fields" class="fe-woo-factura-section" style="margin-top: 20px; border-top: 2px solid #e5e5e5; padding-top: 20px;">';

        echo '<h3>' . esc_html__('Factura Electrónica', 'fe-woo') . '</h3>';

        // Checkbox to enable factura electrónica
        woocommerce_form_field('fe_woo_require_factura', [
            'type' => 'checkbox',
            'class' => ['form-row-wide', 'fe-woo-factura-checkbox'],
            'label' => __('Requiero Factura Electrónica', 'fe-woo'),
            'required' => false,
        ], $checkout->get_value('fe_woo_require_factura'));

        // Container for conditional fields (initially hidden via JS)
        echo '<div id="fe_woo_factura_details" class="fe-woo-factura-details">';

        // Tipo de Identificación
        woocommerce_form_field('fe_woo_id_type', [
            'type' => 'select',
            'class' => ['form-row-wide', 'fe-woo-field'],
            'label' => __('Tipo de Identificación', 'fe-woo'),
            'required' => false,
            'options' => [
                '' => __('Seleccione un tipo', 'fe-woo'),
                self::ID_TYPE_CEDULA_FISICA => __('Cédula Física', 'fe-woo'),
                self::ID_TYPE_CEDULA_JURIDICA => __('Cédula Jurídica', 'fe-woo'),
                self::ID_TYPE_DIMEX => __('DIMEX', 'fe-woo'),
                self::ID_TYPE_PASAPORTE => __('Pasaporte', 'fe-woo'),
            ],
        ], $checkout->get_value('fe_woo_id_type') ?: (!empty($user_data['id_type']) ? $user_data['id_type'] : ''));

        // Número de Identificación con botón de carga
        $id_number_value = $checkout->get_value('fe_woo_id_number') ?: (!empty($user_data['id_number']) ? $user_data['id_number'] : '');
        echo '<div class="form-row form-row-wide fe-woo-field">';
        echo '<label for="fe_woo_id_number">' . __('Número de Identificación', 'fe-woo') . '</label>';
        echo '<div class="fe-woo-cedular-row">';
        echo '<span class="input-wrapper">';
        echo '<input type="text" class="input-text" name="fe_woo_id_number" id="fe_woo_id_number" value="' . esc_attr($id_number_value) . '" autocomplete="off" placeholder="' . esc_attr__('Ingrese su número de identificación', 'fe-woo') . '" maxlength="20">';
        echo '</span>';
        echo '<button type="button" id="fe_woo_load_factura" class="fe-woo-load-btn" aria-label="' . esc_attr__('Cargar información de factura electrónica', 'fe-woo') . '">' . esc_html__('Cargar', 'fe-woo') . '</button>';
        echo '</div>';
        echo '<div id="fe_woo_load_status" class="fe-woo-load-status" aria-live="polite" style="display:none;"></div>';
        echo '</div>';

        // Nombre completo o Razón Social
        woocommerce_form_field('fe_woo_full_name', [
            'type' => 'text',
            'class' => ['form-row-wide', 'fe-woo-field'],
            'label' => __('Nombre Completo o Razón Social', 'fe-woo'),
            'required' => false,
            'placeholder' => __('Ingrese el nombre completo o razón social', 'fe-woo'),
        ], $checkout->get_value('fe_woo_full_name') ?: '');

        // Correo electrónico para recibir la factura
        woocommerce_form_field('fe_woo_invoice_email', [
            'type' => 'email',
            'class' => ['form-row-wide', 'fe-woo-field'],
            'label' => __('Correo Electrónico para Factura', 'fe-woo'),
            'required' => false,
            'placeholder' => __('correo@ejemplo.com', 'fe-woo'),
            'validate' => ['email'],
        ], $checkout->get_value('fe_woo_invoice_email') ?: '');

        // Teléfono
        woocommerce_form_field('fe_woo_phone', [
            'type' => 'tel',
            'class' => ['form-row-wide', 'fe-woo-field'],
            'label' => __('Teléfono', 'fe-woo'),
            'required' => false, // Will be set dynamically by JavaScript
            'placeholder' => __('88888888', 'fe-woo'),
            'custom_attributes' => [
                'pattern' => '[0-9]{8,15}',
            ],
        ], $checkout->get_value('fe_woo_phone') ?: '');

        // Código de actividad económica.
        // No usamos el atributo 'description' de woocommerce_form_field porque
        // WC lo renderiza como tooltip absoluto (.woocommerce-input-wrapper
        // .description) que se rompe visualmente sobre el input. En vez, lo
        // emitimos como bloque de ayuda estático debajo del campo.
        woocommerce_form_field('fe_woo_activity_code', [
            'type' => 'text',
            'class' => ['form-row-wide', 'fe-woo-field'],
            'label' => __('Código de actividad económica', 'fe-woo'),
            'required' => false,
            'placeholder' => __('Ej: 1234.5', 'fe-woo'),
            'custom_attributes' => [
                'pattern' => '\d{4}\.\d{1}',
                'maxlength' => '6',
                'title' => __('Formato requerido: 4 dígitos, punto, 1 dígito. Ejemplo: 1234.5', 'fe-woo'),
            ],
        ], $checkout->get_value('fe_woo_activity_code') ?: '');
        echo '<p class="fe-woo-field-help">'
            . esc_html__('Opcional. Llenar solo si conoces el código exacto de actividad económica registrado para tu empresa en Hacienda. Si lo dejas en blanco, la factura se emite sin este campo.', 'fe-woo')
            . '</p>';

        // Ubicación (Provincia / Cantón / Distrito + OtrasSenas).
        // Hidratamos desde user_meta si el cliente tiene datos previos guardados,
        // de modo que el cascade JS los preseleccione en su orden correcto.
        $initial_provincia = $checkout->get_value('fe_woo_provincia')
            ?: ($user_id ? (string) get_user_meta($user_id, 'fe_woo_provincia', true) : '');
        $initial_canton = $checkout->get_value('fe_woo_canton')
            ?: ($user_id ? (string) get_user_meta($user_id, 'fe_woo_canton', true) : '');
        $initial_distrito = $checkout->get_value('fe_woo_distrito')
            ?: ($user_id ? (string) get_user_meta($user_id, 'fe_woo_distrito', true) : '');
        $initial_otras_senas = $checkout->get_value('fe_woo_otras_senas')
            ?: ($user_id ? (string) get_user_meta($user_id, 'fe_woo_otras_senas', true) : '');
        ?>
        <div class="fe-woo-ubicacion-block"
             data-fe-cr-locations="1"
             data-initial-provincia="<?php echo esc_attr($initial_provincia); ?>"
             data-initial-canton="<?php echo esc_attr($initial_canton); ?>"
             data-initial-distrito="<?php echo esc_attr($initial_distrito); ?>">

            <p class="form-row form-row-wide fe-woo-field">
                <label for="fe_woo_provincia"><?php esc_html_e('Provincia', 'fe-woo'); ?> <abbr class="required" title="required">*</abbr></label>
                <select name="fe_woo_provincia" id="fe_woo_provincia" data-fe-cr-role="provincia" class="select"></select>
            </p>

            <p class="form-row form-row-wide fe-woo-field">
                <label for="fe_woo_canton"><?php esc_html_e('Cantón', 'fe-woo'); ?> <abbr class="required" title="required">*</abbr></label>
                <select name="fe_woo_canton" id="fe_woo_canton" data-fe-cr-role="canton" class="select" disabled></select>
            </p>

            <p class="form-row form-row-wide fe-woo-field">
                <label for="fe_woo_distrito"><?php esc_html_e('Distrito', 'fe-woo'); ?> <abbr class="required" title="required">*</abbr></label>
                <select name="fe_woo_distrito" id="fe_woo_distrito" data-fe-cr-role="distrito" class="select" disabled></select>
            </p>

            <p class="form-row form-row-wide fe-woo-field">
                <label for="fe_woo_otras_senas"><?php esc_html_e('Otras Señas', 'fe-woo'); ?></label>
                <textarea name="fe_woo_otras_senas" id="fe_woo_otras_senas" rows="3" maxlength="250"
                          placeholder="<?php esc_attr_e('Ej: 200m sur del parque, casa color blanco', 'fe-woo'); ?>"><?php echo esc_textarea($initial_otras_senas); ?></textarea>
                <span class="description"><?php esc_html_e('Opcional. Si lo dejas vacío, completaremos genéricamente para Hacienda. Máximo 250 caracteres.', 'fe-woo'); ?></span>
            </p>
        </div>
        <?php

        echo '</div>'; // End fe_woo_factura_details
        echo '</div>'; // End fe_woo_factura_fields
    }

    /**
     * Validate factura electrónica fields during checkout
     */
    public static function validate_factura_electronica_fields() {
        // Check if factura is required
        if (empty($_POST['fe_woo_require_factura']) || $_POST['fe_woo_require_factura'] != '1') {
            return; // Factura not required, skip validation
        }

        // Validate ID Type
        if (empty($_POST['fe_woo_id_type'])) {
            wc_add_notice(__('Por favor seleccione el tipo de identificación para la factura electrónica.', 'fe-woo'), 'error');
        }

        // Validate ID Number
        if (empty($_POST['fe_woo_id_number'])) {
            wc_add_notice(__('Por favor ingrese el número de identificación para la factura electrónica.', 'fe-woo'), 'error');
        } else {
            $id_number = sanitize_text_field($_POST['fe_woo_id_number']);
            $id_type = isset($_POST['fe_woo_id_type']) ? sanitize_text_field($_POST['fe_woo_id_type']) : '';

            // Validate format based on ID type
            $validation_result = self::validate_id_number($id_number, $id_type);
            if (!$validation_result['valid']) {
                wc_add_notice($validation_result['message'], 'error');
            }
        }

        // Validate Full Name
        if (empty($_POST['fe_woo_full_name'])) {
            wc_add_notice(__('Por favor ingrese el nombre completo o razón social para la factura electrónica.', 'fe-woo'), 'error');
        }

        // Validate Invoice Email
        if (empty($_POST['fe_woo_invoice_email'])) {
            wc_add_notice(__('Por favor ingrese el correo electrónico para recibir la factura electrónica.', 'fe-woo'), 'error');
        } elseif (!is_email($_POST['fe_woo_invoice_email'])) {
            wc_add_notice(__('Por favor ingrese un correo electrónico válido para la factura electrónica.', 'fe-woo'), 'error');
        }

        // Validate Phone (optional - only validate format if provided)
        if (!empty($_POST['fe_woo_phone'])) {
            $phone = sanitize_text_field($_POST['fe_woo_phone']);
            if (!preg_match('/^[0-9]{8,15}$/', $phone)) {
                wc_add_notice(__('Por favor ingrese un número de teléfono válido (8-15 dígitos).', 'fe-woo'), 'error');
            }
        }

        // Validate Activity Code (optional - only validate format if provided)
        if (!empty($_POST['fe_woo_activity_code'])) {
            $activity_code = sanitize_text_field($_POST['fe_woo_activity_code']);
            if (!FE_Woo_Hacienda_Config::validate_activity_code_format($activity_code)) {
                wc_add_notice(__('El código de actividad económica es inválido. Formato requerido: 4 dígitos, punto, 1 dígito. Ejemplo: 1234.5', 'fe-woo'), 'error');
            }
        }

        // Validate Ubicación (provincia / cantón / distrito + otras señas)
        $provincia = isset($_POST['fe_woo_provincia']) ? sanitize_text_field(wp_unslash($_POST['fe_woo_provincia'])) : '';
        $canton    = isset($_POST['fe_woo_canton'])    ? sanitize_text_field(wp_unslash($_POST['fe_woo_canton']))    : '';
        $distrito  = isset($_POST['fe_woo_distrito'])  ? sanitize_text_field(wp_unslash($_POST['fe_woo_distrito']))  : '';

        if ($provincia === '' || $canton === '' || $distrito === '') {
            wc_add_notice(__('Por favor seleccione provincia, cantón y distrito para la factura electrónica.', 'fe-woo'), 'error');
        } elseif (!FE_Woo_CR_Locations::validate($provincia, $canton, $distrito)) {
            wc_add_notice(__('La combinación de provincia, cantón y distrito no es válida.', 'fe-woo'), 'error');
        }

        // OtrasSenas: opcional. build_receptor concatena RECEPTOR_OTRAS_SENAS_SUFFIX
        // y trunca a 250, así que vacío o cualquier longitud ≤ 250 es válido.
    }

    /**
     * Validate ID number format based on type
     *
     * @param string $id_number ID number to validate
     * @param string $id_type ID type code
     * @return array Validation result with 'valid' boolean and 'message'
     */
    private static function validate_id_number($id_number, $id_type) {
        $id_number = preg_replace('/[^0-9A-Za-z]/', '', $id_number); // Remove non-alphanumeric

        switch ($id_type) {
            case self::ID_TYPE_CEDULA_FISICA:
                // Cédula Física: 9 digits
                if (!preg_match('/^[0-9]{9}$/', $id_number)) {
                    return [
                        'valid' => false,
                        'message' => __('La Cédula Física debe contener 9 dígitos.', 'fe-woo'),
                    ];
                }
                break;

            case self::ID_TYPE_CEDULA_JURIDICA:
                // Cédula Jurídica: 10 digits
                if (!preg_match('/^[0-9]{10}$/', $id_number)) {
                    return [
                        'valid' => false,
                        'message' => __('La Cédula Jurídica debe contener 10 dígitos.', 'fe-woo'),
                    ];
                }
                break;

            case self::ID_TYPE_DIMEX:
                // DIMEX: 11 or 12 digits
                if (!preg_match('/^[0-9]{11,12}$/', $id_number)) {
                    return [
                        'valid' => false,
                        'message' => __('El DIMEX debe contener 11 o 12 dígitos.', 'fe-woo'),
                    ];
                }
                break;

            case self::ID_TYPE_PASAPORTE:
                // Pasaporte: alphanumeric, typically 6-20 characters
                if (!preg_match('/^[0-9A-Za-z]{6,20}$/', $id_number)) {
                    return [
                        'valid' => false,
                        'message' => __('El Pasaporte debe contener entre 6 y 20 caracteres alfanuméricos.', 'fe-woo'),
                    ];
                }
                break;
        }

        return ['valid' => true];
    }

    /**
     * Save factura electrónica fields to order meta
     *
     * @param WC_Order $order Order object
     * @param array    $data Posted data
     */
    public static function save_factura_electronica_fields($order, $data) {
        // Check if factura is required
        if (isset($_POST['fe_woo_require_factura']) && $_POST['fe_woo_require_factura'] === '1') {
            $order->update_meta_data('_fe_woo_require_factura', 'yes');

            // Save FE-specific fields from checkout
            if (isset($_POST['fe_woo_id_type'])) {
                $order->update_meta_data('_fe_woo_id_type', sanitize_text_field($_POST['fe_woo_id_type']));
            }

            if (isset($_POST['fe_woo_id_number'])) {
                $order->update_meta_data('_fe_woo_id_number', sanitize_text_field($_POST['fe_woo_id_number']));
            }

            // Save optional fields if provided
            if (!empty($_POST['fe_woo_full_name'])) {
                $order->update_meta_data('_fe_woo_full_name', sanitize_text_field($_POST['fe_woo_full_name']));
            }

            if (!empty($_POST['fe_woo_invoice_email'])) {
                $order->update_meta_data('_fe_woo_invoice_email', sanitize_email($_POST['fe_woo_invoice_email']));
            }

            if (!empty($_POST['fe_woo_phone'])) {
                $order->update_meta_data('_fe_woo_phone', sanitize_text_field($_POST['fe_woo_phone']));
            }

            if (!empty($_POST['fe_woo_activity_code'])) {
                $order->update_meta_data('_fe_woo_activity_code', sanitize_text_field($_POST['fe_woo_activity_code']));
            }

            // Ubicación — persist codes only; names are resolved from the catalog
            // when rendering. Mirror to user_meta for autocomplete on next purchase.
            self::save_ubicacion_meta($order, $_POST);

            // Copy billing data to FE fields as fallback
            self::copy_billing_to_fe_fields($order);
        }
    }

    /**
     * Display editable factura electrónica fields in order admin
     *
     * Uses billing fields for common data, only shows FE-specific fields
     *
     * @param WC_Order $order Order object
     */
    public static function display_factura_electronica_admin_fields($order) {
        $require_factura = $order->get_meta('_fe_woo_require_factura');
        $id_type = $order->get_meta('_fe_woo_id_type');
        $id_number = $order->get_meta('_fe_woo_id_number');
        $activity_code = $order->get_meta('_fe_woo_activity_code');
        $full_name     = $order->get_meta('_fe_woo_full_name');
        $invoice_email = $order->get_meta('_fe_woo_invoice_email');
        $phone         = $order->get_meta('_fe_woo_phone');
        $provincia     = (string) $order->get_meta('_fe_woo_provincia');
        $canton        = (string) $order->get_meta('_fe_woo_canton');
        $distrito      = (string) $order->get_meta('_fe_woo_distrito');
        $otras_senas   = (string) $order->get_meta('_fe_woo_otras_senas');

        ?>
        <div class="fe-woo-order-factura-fields" style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #e5e5e5;">
            <h3><?php esc_html_e('Factura Electrónica', 'fe-woo'); ?></h3>

            <div class="fe-woo-checkbox-wrapper">
                <label for="fe_woo_require_factura">
                    <input type="checkbox" name="fe_woo_require_factura" id="fe_woo_require_factura" value="yes" <?php checked($require_factura, 'yes'); ?>>
                    <?php esc_html_e('Requiere Factura Electrónica', 'fe-woo'); ?>
                </label>
            </div>

            <div id="fe_woo_factura_admin_fields" class="fe-woo-admin-fields-container" style="<?php echo ($require_factura !== 'yes') ? 'display: none;' : ''; ?>"><?php /* Factura fields container */ ?>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_id_type"><?php esc_html_e('Tipo de Identificación:', 'fe-woo'); ?> <span class="required">*</span></label>
                    <select name="fe_woo_id_type" id="fe_woo_id_type" class="select short" style="width: 100%;">
                        <option value=""><?php esc_html_e('Seleccione un tipo', 'fe-woo'); ?></option>
                        <option value="<?php echo esc_attr(self::ID_TYPE_CEDULA_FISICA); ?>" <?php selected($id_type, self::ID_TYPE_CEDULA_FISICA); ?>>
                            <?php esc_html_e('Cédula Física', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(self::ID_TYPE_CEDULA_JURIDICA); ?>" <?php selected($id_type, self::ID_TYPE_CEDULA_JURIDICA); ?>>
                            <?php esc_html_e('Cédula Jurídica', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(self::ID_TYPE_DIMEX); ?>" <?php selected($id_type, self::ID_TYPE_DIMEX); ?>>
                            <?php esc_html_e('DIMEX', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(self::ID_TYPE_PASAPORTE); ?>" <?php selected($id_type, self::ID_TYPE_PASAPORTE); ?>>
                            <?php esc_html_e('Pasaporte', 'fe-woo'); ?>
                        </option>
                    </select>
                </p>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_id_number"><?php esc_html_e('Número de Identificación:', 'fe-woo'); ?> <span class="required">*</span></label>
                    <input type="text" name="fe_woo_id_number" id="fe_woo_id_number" value="<?php echo esc_attr($id_number); ?>" maxlength="20" style="width: 100%;" placeholder="<?php esc_attr_e('Ej: 123456789', 'fe-woo'); ?>">
                </p>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_full_name"><?php esc_html_e('Nombre Completo o Razón Social:', 'fe-woo'); ?> <span class="required">*</span></label>
                    <input type="text" name="fe_woo_full_name" id="fe_woo_full_name" value="<?php echo esc_attr($full_name); ?>" style="width: 100%;" placeholder="<?php esc_attr_e('Ej: Juan Pérez o Empresa S.A.', 'fe-woo'); ?>">
                </p>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_invoice_email"><?php esc_html_e('Correo Electrónico para Factura:', 'fe-woo'); ?> <span class="required">*</span></label>
                    <input type="email" name="fe_woo_invoice_email" id="fe_woo_invoice_email" value="<?php echo esc_attr($invoice_email); ?>" style="width: 100%;" placeholder="<?php esc_attr_e('correo@ejemplo.com', 'fe-woo'); ?>">
                </p>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_phone"><?php esc_html_e('Teléfono:', 'fe-woo'); ?></label>
                    <input type="tel" name="fe_woo_phone" id="fe_woo_phone" value="<?php echo esc_attr($phone); ?>" pattern="[0-9]{8,15}" style="width: 100%;" placeholder="<?php esc_attr_e('88888888', 'fe-woo'); ?>">
                    <span class="description"><?php esc_html_e('8-15 dígitos numéricos (opcional)', 'fe-woo'); ?></span>
                </p>

                <p class="form-field form-field-wide">
                    <label for="fe_woo_activity_code"><?php esc_html_e('Código de actividad económica:', 'fe-woo'); ?></label>
                    <input type="text" name="fe_woo_activity_code" id="fe_woo_activity_code" value="<?php echo esc_attr($activity_code); ?>" pattern="\d{4}\.\d{1}" maxlength="6" style="width: 100%;" placeholder="<?php esc_attr_e('Ej: 1234.5', 'fe-woo'); ?>" title="<?php esc_attr_e('Formato requerido: 4 dígitos, punto, 1 dígito. Ejemplo: 1234.5', 'fe-woo'); ?>">
                    <span class="description"><?php esc_html_e('Formato: 4 dígitos, punto, 1 dígito (Ej: 1234.5)', 'fe-woo'); ?></span>
                </p>

                <div class="fe-woo-ubicacion-block"
                     data-fe-cr-locations="1"
                     data-initial-provincia="<?php echo esc_attr($provincia); ?>"
                     data-initial-canton="<?php echo esc_attr($canton); ?>"
                     data-initial-distrito="<?php echo esc_attr($distrito); ?>">

                    <p class="form-field form-field-wide">
                        <label for="fe_woo_provincia"><?php esc_html_e('Provincia:', 'fe-woo'); ?> <span class="required">*</span></label>
                        <select name="fe_woo_provincia" id="fe_woo_provincia" data-fe-cr-role="provincia" style="width: 100%;"></select>
                    </p>

                    <p class="form-field form-field-wide">
                        <label for="fe_woo_canton"><?php esc_html_e('Cantón:', 'fe-woo'); ?> <span class="required">*</span></label>
                        <select name="fe_woo_canton" id="fe_woo_canton" data-fe-cr-role="canton" style="width: 100%;" disabled></select>
                    </p>

                    <p class="form-field form-field-wide">
                        <label for="fe_woo_distrito"><?php esc_html_e('Distrito:', 'fe-woo'); ?> <span class="required">*</span></label>
                        <select name="fe_woo_distrito" id="fe_woo_distrito" data-fe-cr-role="distrito" style="width: 100%;" disabled></select>
                    </p>

                    <p class="form-field form-field-wide">
                        <label for="fe_woo_otras_senas"><?php esc_html_e('Otras Señas:', 'fe-woo'); ?></label>
                        <textarea name="fe_woo_otras_senas" id="fe_woo_otras_senas" rows="3" maxlength="250" style="width: 100%;" placeholder="<?php esc_attr_e('Ej: 200m sur del parque, casa color blanco', 'fe-woo'); ?>"><?php echo esc_textarea($otras_senas); ?></textarea>
                        <span class="description"><?php esc_html_e('Opcional. Si lo dejas vacío, completaremos genéricamente para Hacienda. Máximo 250 caracteres.', 'fe-woo'); ?></span>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save factura electrónica fields from admin order edit
     *
     * @param int $order_id Order ID
     */
    public static function save_factura_electronica_admin_fields($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if factura checkbox is checked
        if (isset($_POST['fe_woo_require_factura']) && $_POST['fe_woo_require_factura'] === 'yes') {
            $order->update_meta_data('_fe_woo_require_factura', 'yes');

            // Save FE-specific fields
            if (isset($_POST['fe_woo_id_type'])) {
                $order->update_meta_data('_fe_woo_id_type', sanitize_text_field($_POST['fe_woo_id_type']));
            }

            if (isset($_POST['fe_woo_id_number'])) {
                $order->update_meta_data('_fe_woo_id_number', sanitize_text_field($_POST['fe_woo_id_number']));
            }

            if (isset($_POST['fe_woo_activity_code'])) {
                $order->update_meta_data('_fe_woo_activity_code', sanitize_text_field($_POST['fe_woo_activity_code']));
            }

            if (isset($_POST['fe_woo_full_name'])) {
                $order->update_meta_data('_fe_woo_full_name', sanitize_text_field($_POST['fe_woo_full_name']));
            }

            if (isset($_POST['fe_woo_invoice_email'])) {
                $order->update_meta_data('_fe_woo_invoice_email', sanitize_email($_POST['fe_woo_invoice_email']));
            }

            if (isset($_POST['fe_woo_phone'])) {
                $order->update_meta_data('_fe_woo_phone', sanitize_text_field($_POST['fe_woo_phone']));
            }

            // Ubicación
            self::save_ubicacion_meta($order, $_POST);

            // Mantener paridad con el flujo de checkout: rellenar desde facturación
            // cualquier campo FE que quedó vacío.
            self::copy_billing_to_fe_fields($order);

            $order->save();
        } else {
            // Checkbox not checked - remove factura requirement
            $order->delete_meta_data('_fe_woo_require_factura');
            $order->save();
        }
    }

    /**
     * Copy billing data to FE fields as fallback
     *
     * Only fills FE fields that are currently empty — never overwrites values
     * the customer entered in the factura electrónica form.
     *
     * @param WC_Order $order Order object
     */
    private static function copy_billing_to_fe_fields($order) {
        // Full name: Combine first name, last name, and company — only if empty
        if (empty($order->get_meta('_fe_woo_full_name'))) {
            $full_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            if (!empty($order->get_billing_company())) {
                $full_name = $order->get_billing_company() . ' (' . $full_name . ')';
            }
            $order->update_meta_data('_fe_woo_full_name', $full_name);
        }

        // Email — only if empty
        if (empty($order->get_meta('_fe_woo_invoice_email'))) {
            $order->update_meta_data('_fe_woo_invoice_email', $order->get_billing_email());
        }

        // Phone — only if empty
        if (empty($order->get_meta('_fe_woo_phone'))) {
            $order->update_meta_data('_fe_woo_phone', $order->get_billing_phone());
        }
    }

    /**
     * Persist ubicacion fields (provincia/canton/distrito/otras_senas) to order
     * meta and mirror to user_meta when there's a logged-in customer.
     *
     * Codes are normalized to the catalog padding format. Names are NOT stored
     * — they are resolved from FE_Woo_CR_Locations whenever rendered.
     *
     * @param WC_Order $order
     * @param array    $source POSTed data ($_POST or admin POST).
     */
    private static function save_ubicacion_meta($order, $source) {
        if (!isset($source['fe_woo_provincia'], $source['fe_woo_canton'], $source['fe_woo_distrito'])) {
            return;
        }

        $provincia = FE_Woo_CR_Locations::pad(sanitize_text_field(wp_unslash($source['fe_woo_provincia'])), 1);
        $canton    = FE_Woo_CR_Locations::pad(sanitize_text_field(wp_unslash($source['fe_woo_canton'])),    2);
        $distrito  = FE_Woo_CR_Locations::pad(sanitize_text_field(wp_unslash($source['fe_woo_distrito'])),  2);

        if ($provincia === '' || $canton === '' || $distrito === '') {
            return;
        }

        if (!FE_Woo_CR_Locations::validate($provincia, $canton, $distrito)) {
            return;
        }

        $order->update_meta_data('_fe_woo_provincia', $provincia);
        $order->update_meta_data('_fe_woo_canton',    $canton);
        $order->update_meta_data('_fe_woo_distrito',  $distrito);

        if (isset($source['fe_woo_otras_senas'])) {
            $otras = trim((string) wp_unslash($source['fe_woo_otras_senas']));
            $otras = function_exists('mb_substr') ? mb_substr($otras, 0, 250) : substr($otras, 0, 250);
            $otras = sanitize_textarea_field($otras);
            if ($otras !== '') {
                $order->update_meta_data('_fe_woo_otras_senas', $otras);
            }
        }

        $customer_id = $order->get_customer_id();
        if ($customer_id) {
            update_user_meta($customer_id, 'fe_woo_provincia', $provincia);
            update_user_meta($customer_id, 'fe_woo_canton',    $canton);
            update_user_meta($customer_id, 'fe_woo_distrito',  $distrito);
            $stored_otras = (string) $order->get_meta('_fe_woo_otras_senas');
            if ($stored_otras !== '') {
                update_user_meta($customer_id, 'fe_woo_otras_senas', $stored_otras);
            }
        }
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Page hook
     */
    public static function enqueue_admin_scripts($hook) {
        // Only load on order edit pages
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders'])) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders'])) {
            return;
        }

        // CR ubicacion cascade for the order admin metabox.
        wp_enqueue_script(
            'fe-woo-cr-locations',
            FE_WOO_PLUGIN_URL . 'assets/js/cr-locations.js',
            ['jquery'],
            FE_WOO_VERSION,
            true
        );
        wp_localize_script('fe-woo-cr-locations', 'feWooCrLocations', FE_Woo_CR_Locations::get_localize_payload());

        // Add inline styles for factura electrónica fields
        wp_add_inline_style('wp-admin', '
            .fe-woo-order-factura-fields {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #e5e5e5;
            }

            .fe-woo-order-factura-fields h3 {
                margin-bottom: 12px;
                margin-top: 0;
            }

            .fe-woo-admin-fields-container {
                margin-top: 15px;
            }

            .fe-woo-info-box {
                padding: 12px 15px;
                margin: 15px 0 20px 0;
                background: #f9f9f9;
                border-left: 4px solid #ddd;
                font-size: 12px;
                line-height: 1.6;
                border-radius: 0 3px 3px 0;
                clear: both;
                display: block;
                width: 100%;
            }

            .fe-woo-info-box strong {
                display: block;
                margin-bottom: 6px;
                font-size: 13px;
                color: #333;
            }

            .fe-woo-order-factura-fields .form-field {
                margin-bottom: 15px;
            }

            .fe-woo-order-factura-fields .form-field label {
                display: block;
                margin-bottom: 5px;
                font-weight: 600;
            }

            .fe-woo-order-factura-fields .description {
                display: block;
                margin-top: 5px;
                font-style: italic;
                color: #666;
            }

            .fe-woo-checkbox-wrapper {
                margin-bottom: 15px;
            }

            .fe-woo-checkbox-wrapper label {
                display: inline-flex;
                align-items: center;
                gap: 8px;
                font-weight: normal;
                cursor: pointer;
            }

            .fe-woo-checkbox-wrapper input[type="checkbox"] {
                width: 16px;
                height: 16px;
                margin: 0;
                cursor: pointer;
            }
        ');

        // Add inline script to handle checkbox toggle, customer selection, and live Hacienda lookup
        wp_add_inline_script('jquery', "
            jQuery(document).ready(function($) {
                // Track what was loaded from DB/API so we know when admin edits manually
                var loadedIdNumber = $('#fe_woo_id_number').val();
                var haciendaTimer  = null;

                // --- helpers ---------------------------------------------------

                function setOpacity(val) {
                    $('#fe_woo_factura_admin_fields').css('opacity', val);
                }

                function showNotice(message) {
                    var notice = $('<div class=\"notice notice-success inline\" style=\"margin: 10px 0;\"><p>' + message + '</p></div>');
                    $('#fe_woo_factura_admin_fields').prepend(notice);
                    setTimeout(function() { notice.fadeOut(function() { $(this).remove(); }); }, 4000);
                }

                // Write all 6 fields from a data object
                function populateFacturaFields(data) {
                    if (data.id_type)        { $('#fe_woo_id_type').val(data.id_type); }
                    if (data.id_number)      { $('#fe_woo_id_number').val(data.id_number); loadedIdNumber = data.id_number; }
                    if (data.full_name)      { $('#fe_woo_full_name').val(data.full_name); }
                    if (data.invoice_email)  { $('#fe_woo_invoice_email').val(data.invoice_email); }
                    if (data.phone)          { $('#fe_woo_phone').val(data.phone); }
                    if (data.activity_code)  { $('#fe_woo_activity_code').val(data.activity_code); }
                }

                // --- toggle factura section --------------------------------------

                $('#fe_woo_require_factura').on('change', function() {
                    if ($(this).is(':checked')) {
                        $('#fe_woo_factura_admin_fields').slideDown(300);
                    } else {
                        $('#fe_woo_factura_admin_fields').slideUp(300);
                    }
                });

                // --- load from DB, fallback to Hacienda API (existing flow) ------

                function loadUserFacturaData(userId) {
                    if (!userId || userId === '0' || userId === '') { return; }

                    setOpacity('0.5');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fe_woo_get_user_factura_data',
                            user_id: userId,
                            nonce: '" . wp_create_nonce('fe_woo_admin_autocomplete') . "'
                        },
                        success: function(response) {
                            setOpacity('1');
                            if (response.success && response.data) {
                                populateFacturaFields(response.data);
                                showNotice('Datos de factura cargados automáticamente desde el perfil del cliente.');
                            }
                        },
                        error: function() { setOpacity('1'); }
                    });
                }

                // --- live Hacienda lookup when admin edits id_number manually ----

                function lookupHacienda(idNumber, idType) {
                    if (!idNumber || !idType) { return; }
                    var cleanNumber = idNumber.replace(/[^0-9A-Za-z]/g, '');
                    if (cleanNumber.length < 6) { return; }

                    setOpacity('0.7');

                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'fe_woo_admin_hacienda_lookup',
                            id_number: cleanNumber,
                            id_type:   idType,
                            nonce:     '" . wp_create_nonce('fe_woo_admin_hacienda_lookup') . "'
                        },
                        success: function(response) {
                            setOpacity('1');
                            if (response.success && response.data) {
                                if (response.data.nombre)        { $('#fe_woo_full_name').val(response.data.nombre); }
                                if (response.data.activity_code) { $('#fe_woo_activity_code').val(response.data.activity_code); }
                                loadedIdNumber = $('#fe_woo_id_number').val();
                                showNotice('Datos actualizados desde Hacienda.');
                            }
                        },
                        error: function() { setOpacity('1'); }
                    });
                }

                // Debounced input: only fires when value diverges from what was loaded
                $('#fe_woo_id_number').on('input', function() {
                    var currentValue = $(this).val();
                    var idType       = $('#fe_woo_id_type').val();

                    if (haciendaTimer) { clearTimeout(haciendaTimer); haciendaTimer = null; }

                    if (currentValue && currentValue !== loadedIdNumber && idType) {
                        haciendaTimer = setTimeout(function() {
                            lookupHacienda(currentValue, idType);
                        }, 800);
                    }
                });

                // --- customer selection events ----------------------------------

                $(document).on('change', '#customer_user, select[name=\"customer_user\"]', function() {
                    if ($('#fe_woo_require_factura').is(':checked')) {
                        loadUserFacturaData($(this).val());
                    }
                });

                // Also fire when checkbox is checked and customer already selected
                $('#fe_woo_require_factura').on('change', function() {
                    if ($(this).is(':checked')) {
                        var userId = $('#customer_user, select[name=\"customer_user\"]').val();
                        if (userId && userId !== '0' && userId !== '') {
                            loadUserFacturaData(userId);
                        }
                    }
                });
            });
        ");
    }

    /**
     * Get ID type label from code
     *
     * @param string $id_type ID type code
     * @return string ID type label
     */
    private static function get_id_type_label($id_type) {
        $types = [
            self::ID_TYPE_CEDULA_FISICA => __('Cédula Física', 'fe-woo'),
            self::ID_TYPE_CEDULA_JURIDICA => __('Cédula Jurídica', 'fe-woo'),
            self::ID_TYPE_DIMEX => __('DIMEX', 'fe-woo'),
            self::ID_TYPE_PASAPORTE => __('Pasaporte', 'fe-woo'),
        ];

        return isset($types[$id_type]) ? $types[$id_type] : $id_type;
    }

    /**
     * AJAX handler for checkout autocomplete
     * Retrieves data from Hacienda API based on user's parent theme cédula
     */
    public static function ajax_checkout_autocomplete() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fe_woo_autocomplete')) {
            wp_send_json_error([
                'message' => __('Error de seguridad. Recargue la página e intente nuevamente.', 'fe-woo')
            ]);
        }

        // Check if user is logged in
        $user_id = get_current_user_id();
        if (!$user_id) {
            wp_send_json_error([
                'message' => __('Debe estar autenticado para usar esta función.', 'fe-woo')
            ]);
        }

        // Get parent theme cédula
        $cedula = get_user_meta($user_id, 'id_number', true);
        $id_type = get_user_meta($user_id, 'id_type', true);

        if (empty($cedula)) {
            wp_send_json_error([
                'message' => __('No se encontró número de cédula en su perfil.', 'fe-woo')
            ]);
        }

        // Query Hacienda API
        $data = self::query_hacienda_api($cedula);

        if (self::is_hacienda_api_error($data)) {
            wp_send_json_error([
                'message' => __('No se encontraron datos en el sistema de Hacienda para este número de identificación.', 'fe-woo')
            ]);
        }

        // Add id_type and cedula to response
        $data['id_type'] = $id_type;
        $data['cedula'] = $cedula;

        wp_send_json_success($data);
    }

    /**
     * Determine whether a query_hacienda_api() result is an error envelope.
     *
     * @param mixed $result
     * @return bool
     */
    private static function is_hacienda_api_error($result) {
        return !is_array($result) || isset($result['error']);
    }

    /**
     * AJAX handler for the checkout "Cargar" button.
     *
     * Always queries Hacienda with the identification number entered in the form
     * so the nombre/activity_code match the cedula the user just typed (not a stale
     * copy from DB). For logged-in users, saved email/phone are still layered on
     * top because the Hacienda API does not return those fields.
     *
     * Accessible to both logged-in users and guests.
     */
    public static function ajax_load_factura() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fe_woo_load_factura')) {
            wp_send_json_error([
                'message' => __('Error de seguridad. Recargue la página e intente nuevamente.', 'fe-woo'),
            ]);
        }

        $id_type        = isset($_POST['id_type'])   ? sanitize_text_field($_POST['id_type'])   : '';
        $id_number      = isset($_POST['id_number']) ? sanitize_text_field($_POST['id_number']) : '';
        $id_number_clean = preg_replace('/[^0-9A-Za-z]/', '', $id_number);

        if (empty($id_type) || empty($id_number_clean)) {
            wp_send_json_error([
                'message' => __('Por favor ingrese el tipo y número de identificación.', 'fe-woo'),
            ]);
        }

        // Layer in saved email/phone from user meta when available — Hacienda's
        // API only returns name + activity_code, so without this returning users
        // would have to re-enter contact info on every purchase.
        $saved_email = '';
        $saved_phone = '';
        $user_id = get_current_user_id();
        if ($user_id) {
            $saved_id_number = get_user_meta($user_id, 'fe_woo_id_number', true);
            if (!empty($saved_id_number) && preg_replace('/[^0-9A-Za-z]/', '', $saved_id_number) === $id_number_clean) {
                $saved_email = (string) get_user_meta($user_id, 'fe_woo_invoice_email', true);
                $saved_phone = (string) get_user_meta($user_id, 'fe_woo_phone', true);
            }
        }

        $api_data = self::query_hacienda_api($id_number_clean);

        if (!empty($api_data['nombre'])) {
            wp_send_json_success([
                'source' => 'hacienda',
                'data'   => [
                    'nombre'        => $api_data['nombre'],
                    'activity_code' => $api_data['activity_code'],
                    'invoice_email' => $saved_email,
                    'phone'         => $saved_phone,
                ],
            ]);
        }

        if (isset($api_data['error']) && $api_data['error'] === 'service_unavailable') {
            wp_send_json_error([
                'message' => __('El servicio de Hacienda no está disponible en este momento. Complete los datos manualmente o intente más tarde.', 'fe-woo'),
                'source'  => 'service_unavailable',
            ]);
        }

        wp_send_json_error([
            'message' => __('No se encontró información disponible para esta identificación.', 'fe-woo'),
            'source'  => 'not_found',
        ]);
    }

    /**
     * Query Hacienda API for contributor data
     *
     * @param string $cedula Identification number
     * @return array On success: ['nombre' => ..., 'activity_code' => ...].
     *               On transport/server failure: ['error' => 'service_unavailable'].
     *               On not-found or empty payload: ['error' => 'not_found'].
     */
    private static function query_hacienda_api($cedula) {
        $url = 'https://api.hacienda.go.cr/fe/ae?identificacion=' . urlencode($cedula);

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return ['error' => 'service_unavailable'];
        }

        $http_code = (int) wp_remote_retrieve_response_code($response);

        if ($http_code >= 500 || $http_code === 0) {
            return ['error' => 'service_unavailable'];
        }

        if ($http_code === 404) {
            return ['error' => 'not_found'];
        }

        // Treat rate-limits / auth failures / unexpected 4xx (except 404) as a
        // transient service problem so the UX can distinguish "retry later"
        // from a genuine not-found.
        if ($http_code >= 400 && $http_code < 500) {
            return ['error' => 'service_unavailable'];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (empty($data) || !isset($data['nombre'])) {
            return ['error' => 'not_found'];
        }

        // Extract activity code from CIIU3 if available
        $activity_code = '';
        if (isset($data['actividades']) && is_array($data['actividades'])) {
            foreach ($data['actividades'] as $actividad) {
                // Try to get CIIU3 code first (6 digits) - this is the priority
                if (isset($actividad['ciiu3']) && is_array($actividad['ciiu3'])) {
                    $ciiu3 = $actividad['ciiu3'][0];
                    if (isset($ciiu3['codigo'])) {
                        // Keep only numbers and period (.), remove other characters
                        $codigo = preg_replace('/[^0-9.]/', '', $ciiu3['codigo']);
                        // Validate format and length
                        if (strlen($codigo) <= 6 && FE_Woo_Hacienda_Config::validate_activity_code_format($codigo)) {
                            $activity_code = $codigo;
                            break; // Found CIIU3 code, exit loop
                        } else {
                            // Remove period and use numeric only if format is invalid
                            $codigo_numeric = preg_replace('/[^0-9]/', '', $ciiu3['codigo']);
                            if (strlen($codigo_numeric) >= 6) {
                                $activity_code = substr($codigo_numeric, 0, 6);
                                break;
                            }
                        }
                    }
                }

                // Fallback: Use main activity code if no CIIU3 found
                if (empty($activity_code) && isset($actividad['codigo'])) {
                    // Keep only numbers and period (.), remove other characters
                    $codigo = preg_replace('/[^0-9.]/', '', $actividad['codigo']);
                    // Validate format and length
                    if (strlen($codigo) <= 6 && FE_Woo_Hacienda_Config::validate_activity_code_format($codigo)) {
                        $activity_code = $codigo;
                    } else {
                        // Remove period and use numeric only if format is invalid
                        $codigo_numeric = preg_replace('/[^0-9]/', '', $actividad['codigo']);
                        $activity_code = str_pad($codigo_numeric, 6, '0', STR_PAD_RIGHT);
                    }
                    break;
                }
            }
        }

        return [
            'nombre' => $data['nombre'],
            'activity_code' => $activity_code,
        ];
    }

    /**
     * AJAX handler to get user's saved factura data for admin order creation
     */
    public static function ajax_get_user_factura_data() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fe_woo_admin_autocomplete')) {
            wp_send_json_error([
                'message' => __('Error de seguridad.', 'fe-woo')
            ]);
        }

        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error([
                'message' => __('No tiene permisos para realizar esta acción.', 'fe-woo')
            ]);
        }

        // Get user ID
        $user_id = isset($_POST['user_id']) ? intval($_POST['user_id']) : 0;
        if (!$user_id) {
            wp_send_json_error([
                'message' => __('ID de usuario no válido.', 'fe-woo')
            ]);
        }

        // Get user's saved factura data
        $factura_data = [
            'id_type' => get_user_meta($user_id, 'fe_woo_id_type', true),
            'id_number' => get_user_meta($user_id, 'fe_woo_id_number', true),
            'full_name' => get_user_meta($user_id, 'fe_woo_full_name', true),
            'invoice_email' => get_user_meta($user_id, 'fe_woo_invoice_email', true),
            'phone' => get_user_meta($user_id, 'fe_woo_phone', true),
            'activity_code' => get_user_meta($user_id, 'fe_woo_activity_code', true),
        ];

        // Check if user has saved data
        $has_saved_data = !empty($factura_data['id_number']);

        if (!$has_saved_data) {
            // No saved data - try to get from parent theme and Hacienda API
            $parent_cedula = get_user_meta($user_id, 'id_number', true);
            $parent_id_type = get_user_meta($user_id, 'id_type', true);

            if (!empty($parent_cedula)) {
                // Query Hacienda API
                $api_data = self::query_hacienda_api($parent_cedula);

                if (!self::is_hacienda_api_error($api_data)) {
                    $factura_data = [
                        'id_type' => $parent_id_type,
                        'id_number' => $parent_cedula,
                        'full_name' => $api_data['nombre'],
                        'invoice_email' => '', // Will be filled from user email
                        'phone' => '', // Will be filled from user phone if available
                        'activity_code' => $api_data['activity_code'],
                    ];
                    $has_saved_data = true;
                }
            }
        }

        if (!$has_saved_data) {
            wp_send_json_error([
                'message' => __('Este usuario no tiene datos de factura guardados.', 'fe-woo')
            ]);
        }

        wp_send_json_success($factura_data);
    }

    /**
     * AJAX handler for live Hacienda API lookup by ID number.
     *
     * Called when an admin manually edits the identification number on an order.
     * Returns nombre and activity_code so the form can auto-fill without a full page reload.
     */
    public static function ajax_admin_hacienda_lookup() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fe_woo_admin_hacienda_lookup')) {
            wp_send_json_error([
                'message' => __('Error de seguridad.', 'fe-woo'),
            ]);
        }

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error([
                'message' => __('No tiene permisos para realizar esta acción.', 'fe-woo'),
            ]);
        }

        $id_number = isset($_POST['id_number']) ? sanitize_text_field($_POST['id_number']) : '';
        if (empty($id_number)) {
            wp_send_json_error([
                'message' => __('Número de identificación no proporcionado.', 'fe-woo'),
            ]);
        }

        $data = self::query_hacienda_api($id_number);

        if (self::is_hacienda_api_error($data)) {
            wp_send_json_error([
                'message' => __('No se encontraron datos en Hacienda para este número de identificación.', 'fe-woo'),
            ]);
        }

        wp_send_json_success($data);
    }
}
