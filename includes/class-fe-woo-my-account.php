<?php
/**
 * FE WooCommerce My Account
 *
 * Handles the "Facturación electrónica" section in WooCommerce My Account
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_My_Account Class
 *
 * Adds a "Facturación electrónica" endpoint to WooCommerce My Account
 * where users can save their electronic invoice data
 */
class FE_Woo_My_Account {

    /**
     * Endpoint slug (default)
     */
    const ENDPOINT = 'facturacion-electronica';

    /**
     * Get the endpoint slug from settings
     *
     * @return string Endpoint slug
     */
    public static function get_endpoint() {
        return get_option('woocommerce_myaccount_facturacion_electronica_endpoint', self::ENDPOINT);
    }

    /**
     * Initialize the My Account endpoint
     */
    public static function init() {
        // Register the endpoint
        add_action('init', [__CLASS__, 'add_endpoint']);

        // Add menu item to My Account
        add_filter('woocommerce_account_menu_items', [__CLASS__, 'add_menu_item']);

        // Display endpoint content
        add_action('woocommerce_account_facturacion-electronica_endpoint', [__CLASS__, 'endpoint_content']);

        // Handle form submission
        add_action('template_redirect', [__CLASS__, 'save_factura_data']);

        // Enqueue scripts and styles
        add_action('wp_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);

        // Add settings to WooCommerce > Settings > Advanced > Account endpoints
        add_filter('woocommerce_get_settings_advanced', [__CLASS__, 'add_account_endpoint_setting'], 10, 2);

        // Register query var
        add_filter('query_vars', [__CLASS__, 'add_query_vars'], 0);

        // AJAX handler for autocomplete from Hacienda
        add_action('wp_ajax_fe_woo_autocomplete_hacienda', [__CLASS__, 'ajax_autocomplete_hacienda']);
    }

    /**
     * Register custom endpoint for WooCommerce My Account
     */
    public static function add_endpoint() {
        $endpoint = self::get_endpoint();

        // Only register endpoint if it's not empty
        if (!empty($endpoint)) {
            add_rewrite_endpoint($endpoint, EP_ROOT | EP_PAGES);
        }
    }

    /**
     * Add query vars for the endpoint
     *
     * @param array $vars Query vars
     * @return array Modified query vars
     */
    public static function add_query_vars($vars) {
        $endpoint = self::get_endpoint();

        // Only add query var if endpoint is not empty
        if (!empty($endpoint)) {
            $vars[] = $endpoint;
        }

        return $vars;
    }

    /**
     * Add endpoint setting to WooCommerce Account settings
     *
     * @param array $settings Existing settings
     * @param string $current_section Current section being displayed
     * @return array Modified settings
     */
    public static function add_account_endpoint_setting($settings, $current_section) {
        // Only add our setting in the main advanced section (empty section)
        if ('' !== $current_section) {
            return $settings;
        }

        $updated_settings = [];

        foreach ($settings as $setting) {
            $updated_settings[] = $setting;

            // Add our endpoint setting after the "Logout" endpoint setting
            if (isset($setting['id']) && 'woocommerce_logout_endpoint' === $setting['id']) {
                $updated_settings[] = [
                    'title'    => __('Facturación electrónica', 'fe-woo'),
                    'desc'     => __('Endpoint para la página de facturación electrónica.', 'fe-woo'),
                    'id'       => 'woocommerce_myaccount_facturacion_electronica_endpoint',
                    'type'     => 'text',
                    'default'  => 'facturacion-electronica',
                    'desc_tip' => true,
                ];
            }
        }

        return $updated_settings;
    }

    /**
     * Add menu item to WooCommerce My Account menu
     *
     * @param array $items Existing menu items
     * @return array Modified menu items
     */
    public static function add_menu_item($items) {
        $endpoint = self::get_endpoint();

        // Only add menu item if endpoint is not empty
        if (empty($endpoint)) {
            return $items;
        }

        // Insert "Facturación electrónica" before logout
        $logout = $items['customer-logout'];
        unset($items['customer-logout']);

        $items[$endpoint] = __('Facturación electrónica', 'fe-woo');
        $items['customer-logout'] = $logout;

        return $items;
    }

    /**
     * Display endpoint content
     */
    public static function endpoint_content() {
        // Check if endpoint is enabled
        if (empty(self::get_endpoint())) {
            return;
        }

        // Check if checkout form is enabled in settings
        if (get_option(FE_Woo_Hacienda_Config::OPTION_ENABLE_CHECKOUT_FORM, 'yes') !== 'yes') {
            echo '<div class="woocommerce-info">';
            echo esc_html__('La facturación electrónica no está habilitada actualmente.', 'fe-woo');
            echo '</div>';
            return;
        }

        // Get current user data
        $user_id = get_current_user_id();

        // Get saved factura data
        $id_type = get_user_meta($user_id, 'fe_woo_id_type', true);
        $id_number = get_user_meta($user_id, 'fe_woo_id_number', true);
        $full_name = get_user_meta($user_id, 'fe_woo_full_name', true);
        $invoice_email = get_user_meta($user_id, 'fe_woo_invoice_email', true);
        $phone = get_user_meta($user_id, 'fe_woo_phone', true);
        $activity_code = get_user_meta($user_id, 'fe_woo_activity_code', true);

        // Get user's cédula from theme parent registration
        $user_cedula = get_user_meta($user_id, 'id_number', true);
        $user_id_type = get_user_meta($user_id, 'id_type', true);

        // Check if factura form is empty (no saved data)
        $form_is_empty = empty($id_number) && empty($full_name);

        // Only show autocomplete button if:
        // 1. Form is empty (no saved factura data)
        // 2. User has a cedula number from registration
        $show_autocomplete_button = $form_is_empty && !empty($user_cedula);

        // Display success message if data was saved
        if (isset($_GET['factura-saved']) && $_GET['factura-saved'] === 'true') {
            wc_print_notice(__('Sus datos de facturación electrónica han sido guardados exitosamente.', 'fe-woo'), 'success');
        }

        ?>
        <div class="fe-woo-my-account-factura">
            <h3><?php esc_html_e('Datos de Facturación Electrónica', 'fe-woo'); ?></h3>

            <p class="fe-woo-description">
                <?php esc_html_e('Guarde sus datos de facturación electrónica aquí para que se autocompleten automáticamente en sus futuras compras.', 'fe-woo'); ?>
            </p>

            <?php if ($show_autocomplete_button) : ?>
                <div class="fe-woo-autocomplete-notice">
                    <p class="fe-woo-autocomplete-loading">
                        <span class="spinner is-active" style="float: none; margin: 0;"></span>
                        <?php esc_html_e('Consultando sus datos en el sistema de Hacienda...', 'fe-woo'); ?>
                    </p>
                </div>
                <!-- Hidden data for JavaScript -->
                <input type="hidden" id="fe-woo-user-cedula" value="<?php echo esc_attr($user_cedula); ?>" />
                <input type="hidden" id="fe-woo-user-id-type" value="<?php echo esc_attr($user_id_type); ?>" />
            <?php endif; ?>

            <form method="post" class="fe-woo-factura-form">
                <?php wp_nonce_field('fe_woo_save_factura_data', 'fe_woo_factura_nonce'); ?>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_id_type">
                        <?php esc_html_e('Tipo de Identificación', 'fe-woo'); ?>
                        <span class="required">*</span>
                    </label>
                    <select name="fe_woo_id_type" id="fe_woo_id_type" class="input-text">
                        <option value=""><?php esc_html_e('Seleccione un tipo', 'fe-woo'); ?></option>
                        <option value="<?php echo esc_attr(FE_Woo_Checkout::ID_TYPE_CEDULA_FISICA); ?>" <?php selected($id_type, FE_Woo_Checkout::ID_TYPE_CEDULA_FISICA); ?>>
                            <?php esc_html_e('Cédula Física', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(FE_Woo_Checkout::ID_TYPE_CEDULA_JURIDICA); ?>" <?php selected($id_type, FE_Woo_Checkout::ID_TYPE_CEDULA_JURIDICA); ?>>
                            <?php esc_html_e('Cédula Jurídica', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(FE_Woo_Checkout::ID_TYPE_DIMEX); ?>" <?php selected($id_type, FE_Woo_Checkout::ID_TYPE_DIMEX); ?>>
                            <?php esc_html_e('DIMEX', 'fe-woo'); ?>
                        </option>
                        <option value="<?php echo esc_attr(FE_Woo_Checkout::ID_TYPE_PASAPORTE); ?>" <?php selected($id_type, FE_Woo_Checkout::ID_TYPE_PASAPORTE); ?>>
                            <?php esc_html_e('Pasaporte', 'fe-woo'); ?>
                        </option>
                    </select>
                </p>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_id_number">
                        <?php esc_html_e('Número de Identificación', 'fe-woo'); ?>
                        <span class="required">*</span>
                    </label>
                    <input type="text" class="input-text" name="fe_woo_id_number" id="fe_woo_id_number"
                           value="<?php echo esc_attr($id_number); ?>"
                           placeholder="<?php esc_attr_e('Ingrese su número de identificación', 'fe-woo'); ?>"
                           maxlength="20">
                </p>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_full_name">
                        <?php esc_html_e('Nombre Completo o Razón Social', 'fe-woo'); ?>
                        <span class="required">*</span>
                    </label>
                    <input type="text" class="input-text" name="fe_woo_full_name" id="fe_woo_full_name"
                           value="<?php echo esc_attr($full_name); ?>"
                           placeholder="<?php esc_attr_e('Ingrese el nombre completo o razón social', 'fe-woo'); ?>">
                </p>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_invoice_email">
                        <?php esc_html_e('Correo Electrónico para Factura', 'fe-woo'); ?>
                        <span class="required">*</span>
                    </label>
                    <input type="email" class="input-text" name="fe_woo_invoice_email" id="fe_woo_invoice_email"
                           value="<?php echo esc_attr($invoice_email); ?>"
                           placeholder="<?php esc_attr_e('correo@ejemplo.com', 'fe-woo'); ?>">
                </p>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_phone">
                        <?php esc_html_e('Teléfono', 'fe-woo'); ?>
                    </label>
                    <input type="tel" class="input-text" name="fe_woo_phone" id="fe_woo_phone"
                           value="<?php echo esc_attr($phone); ?>"
                           placeholder="<?php esc_attr_e('88888888', 'fe-woo'); ?>"
                           pattern="[0-9]{8,15}">
                </p>

                <p class="form-row form-row-wide">
                    <label for="fe_woo_activity_code">
                        <?php esc_html_e('Código de actividad económica', 'fe-woo'); ?>
                    </label>
                    <input type="text" class="input-text" name="fe_woo_activity_code" id="fe_woo_activity_code"
                           value="<?php echo esc_attr($activity_code); ?>"
                           placeholder="<?php esc_attr_e('Ej: 1234.5', 'fe-woo'); ?>"
                           pattern="\d{4}\.\d{1}"
                           maxlength="6"
                           title="<?php esc_attr_e('Formato requerido: 4 dígitos, punto, 1 dígito. Ejemplo: 1234.5', 'fe-woo'); ?>">
                    <span class="description"><?php esc_html_e('Opcional - Formato: 4 dígitos, punto (.), 1 dígito. Ejemplo: 1234.5', 'fe-woo'); ?></span>
                </p>

                <p>
                    <button type="submit" class="button" name="fe_woo_save_factura" value="1">
                        <?php esc_html_e('Guardar datos de facturación', 'fe-woo'); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Save factura data from My Account form
     */
    public static function save_factura_data() {
        // Check if we're on the correct endpoint
        if (!is_account_page() || !isset($_POST['fe_woo_save_factura'])) {
            return;
        }

        // Verify nonce
        if (!isset($_POST['fe_woo_factura_nonce']) || !wp_verify_nonce($_POST['fe_woo_factura_nonce'], 'fe_woo_save_factura_data')) {
            wc_add_notice(__('Error de seguridad. Por favor, intente nuevamente.', 'fe-woo'), 'error');
            return;
        }

        // Get current user ID
        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        // Validate required fields
        $errors = [];

        if (empty($_POST['fe_woo_id_type'])) {
            $errors[] = __('Por favor seleccione el tipo de identificación.', 'fe-woo');
        }

        if (empty($_POST['fe_woo_id_number'])) {
            $errors[] = __('Por favor ingrese el número de identificación.', 'fe-woo');
        }

        if (empty($_POST['fe_woo_full_name'])) {
            $errors[] = __('Por favor ingrese el nombre completo o razón social.', 'fe-woo');
        }

        if (empty($_POST['fe_woo_invoice_email'])) {
            $errors[] = __('Por favor ingrese el correo electrónico para factura.', 'fe-woo');
        } elseif (!is_email($_POST['fe_woo_invoice_email'])) {
            $errors[] = __('Por favor ingrese un correo electrónico válido.', 'fe-woo');
        }

        // Validate phone format if provided
        if (!empty($_POST['fe_woo_phone'])) {
            $phone = sanitize_text_field($_POST['fe_woo_phone']);
            if (!preg_match('/^[0-9]{8,15}$/', $phone)) {
                $errors[] = __('Por favor ingrese un número de teléfono válido (8-15 dígitos).', 'fe-woo');
            }
        }

        // Validate activity code format if provided
        if (!empty($_POST['fe_woo_activity_code'])) {
            $activity_code = sanitize_text_field($_POST['fe_woo_activity_code']);
            if (!FE_Woo_Hacienda_Config::validate_activity_code_format($activity_code)) {
                $errors[] = __('El código de actividad económica es inválido. Formato requerido: 4 dígitos, punto, 1 dígito. Ejemplo: 1234.5', 'fe-woo');
            }
        }

        // Display errors if any
        if (!empty($errors)) {
            foreach ($errors as $error) {
                wc_add_notice($error, 'error');
            }
            return;
        }

        // Save user meta
        update_user_meta($user_id, 'fe_woo_id_type', sanitize_text_field($_POST['fe_woo_id_type']));
        update_user_meta($user_id, 'fe_woo_id_number', sanitize_text_field($_POST['fe_woo_id_number']));
        update_user_meta($user_id, 'fe_woo_full_name', sanitize_text_field($_POST['fe_woo_full_name']));
        update_user_meta($user_id, 'fe_woo_invoice_email', sanitize_email($_POST['fe_woo_invoice_email']));
        update_user_meta($user_id, 'fe_woo_phone', sanitize_text_field($_POST['fe_woo_phone']));
        update_user_meta($user_id, 'fe_woo_activity_code', sanitize_text_field($_POST['fe_woo_activity_code']));

        // Redirect with success message
        wp_safe_redirect(add_query_arg('factura-saved', 'true', wc_get_account_endpoint_url(self::get_endpoint())));
        exit;
    }

    /**
     * Enqueue scripts and styles for the My Account page
     */
    public static function enqueue_scripts() {
        if (!is_account_page() && !is_checkout()) {
            return;
        }

        wp_enqueue_style(
            'fe-woo-my-account',
            FE_WOO_PLUGIN_URL . 'assets/css/my-account.css',
            [],
            FE_WOO_VERSION
        );

        // Enqueue JavaScript for autocomplete functionality
        if (is_account_page()) {
            wp_enqueue_script(
                'fe-woo-my-account',
                FE_WOO_PLUGIN_URL . 'assets/js/my-account.js',
                ['jquery'],
                FE_WOO_VERSION,
                true
            );

            // Pass AJAX URL to JavaScript
            wp_localize_script('fe-woo-my-account', 'fe_woo_my_account', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('fe_woo_autocomplete'),
            ]);
        }
    }

    /**
     * AJAX handler for autocompleting from Hacienda API
     */
    public static function ajax_autocomplete_hacienda() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'fe_woo_autocomplete')) {
            wp_send_json_error(['message' => __('Error de seguridad.', 'fe-woo')]);
        }

        // Check user is logged in
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Debe iniciar sesión.', 'fe-woo')]);
        }

        // Get cédula from request
        $cedula = isset($_POST['cedula']) ? sanitize_text_field($_POST['cedula']) : '';

        if (empty($cedula)) {
            wp_send_json_error(['message' => __('Número de cédula no proporcionado.', 'fe-woo')]);
        }

        // Query Hacienda API
        $response = self::query_hacienda_api($cedula);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => $response->get_error_message()]);
        }

        wp_send_json_success($response);
    }

    /**
     * Query Hacienda API for contributor data
     *
     * @param string $cedula Cédula number
     * @return array|WP_Error API response data or error
     */
    protected static function query_hacienda_api($cedula) {
        $api_url = 'https://api.hacienda.go.cr/fe/ae?identificacion=' . urlencode($cedula);

        $response = wp_remote_get($api_url, [
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);

        if (is_wp_error($response)) {
            return new WP_Error('api_error', __('Error al conectar con el servicio de Hacienda.', 'fe-woo'));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new WP_Error('api_error', __('No se encontraron datos para esta cédula en el sistema de Hacienda.', 'fe-woo'));
        }

        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('parse_error', __('Error al procesar la respuesta de Hacienda.', 'fe-woo'));
        }

        // Extract relevant data
        $nombre = isset($data['nombre']) ? sanitize_text_field($data['nombre']) : '';
        $actividades = isset($data['actividades']) && is_array($data['actividades']) ? $data['actividades'] : [];

        // Extract valid activity code (6 digits)
        $activity_code = '';
        if (!empty($actividades)) {
            foreach ($actividades as $actividad) {
                // Try to get CIIU3 code first (6 digits)
                if (isset($actividad['ciiu3']) && is_array($actividad['ciiu3']) && !empty($actividad['ciiu3'])) {
                    $ciiu3 = $actividad['ciiu3'][0];
                    if (isset($ciiu3['codigo'])) {
                        // Extract only numeric characters
                        $codigo = preg_replace('/[^0-9]/', '', $ciiu3['codigo']);

                        if (strlen($codigo) >= 6) {
                            $activity_code = substr($codigo, 0, 6);
                            break;
                        }
                    }
                }

                // Fallback: Try the main activity code
                if (empty($activity_code) && isset($actividad['codigo'])) {
                    // Remove decimal point and extract only numeric characters
                    $codigo = str_replace('.', '', $actividad['codigo']);
                    $codigo = preg_replace('/[^0-9]/', '', $codigo);

                    // Pad with zeros if needed to get 6 digits
                    if (strlen($codigo) > 0) {
                        $activity_code = str_pad($codigo, 6, '0', STR_PAD_RIGHT);
                        $activity_code = substr($activity_code, 0, 6);
                        break;
                    }
                }
            }
        }

        return [
            'nombre' => $nombre,
            'activity_code' => $activity_code,
        ];
    }

    /**
     * Flush rewrite rules on activation
     */
    public static function flush_rewrite_rules() {
        self::add_endpoint();
        flush_rewrite_rules();
    }
}
