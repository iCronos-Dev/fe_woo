<?php
/**
 * FE WooCommerce Settings Tab
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Settings Class
 *
 * Adds a custom settings tab to WooCommerce settings for Hacienda API configuration
 */
class FE_Woo_Settings {

    /**
     * Initialize the settings
     */
    public static function init() {
        add_filter('woocommerce_settings_tabs_array', [__CLASS__, 'add_settings_tab'], 50);
        add_action('woocommerce_settings_tabs_fe', [__CLASS__, 'settings_tab_content']);
        add_action('woocommerce_settings_save_fe', [__CLASS__, 'maybe_handle_certificate_upload'], 5); // Before settings save
        add_action('woocommerce_update_options_fe', [__CLASS__, 'update_settings']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);

        // Handle emisor form submissions
        add_action('admin_init', [__CLASS__, 'handle_emisor_form_submission']);

        // AJAX handler for testing emisor connection
        add_action('wp_ajax_fe_woo_test_emisor_connection', [__CLASS__, 'ajax_test_emisor_connection']);

        // Admin notice para cert SINPE próximo a expirar / expirado (T-3 v1.19.0).
        add_action('admin_notices', [__CLASS__, 'maybe_render_cert_expiration_notice']);
    }

    /**
     * Render an admin notice when the SINPE certificate is close to expiring
     * or has already expired. Shown only to users with the manage_woocommerce
     * capability, on every wp-admin page.
     *
     * Tres umbrales:
     *   ≤ 30 días → notice-warning amarillo.
     *   ≤ 7 días  → notice-error rojo (mismo cuerpo, urgencia mayor).
     *   expirado  → notice-error con texto distinto.
     */
    public static function maybe_render_cert_expiration_notice() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        if (!class_exists('FE_Woo_Certificate_Handler')) {
            return;
        }
        $status = FE_Woo_Certificate_Handler::get_cached_status();
        $code   = isset($status['status']) ? $status['status'] : '';

        $settings_url = admin_url('admin.php?page=wc-settings&tab=fe');
        $upload_link  = sprintf(
            '<a href="%s">%s</a>',
            esc_url($settings_url),
            esc_html__('Subir nuevo certificado →', 'fe-woo')
        );

        if ($code === 'invalid'
            && isset($status['message'])
            && stripos($status['message'], 'expirado') !== false) {
            $msg = sprintf(
                '<strong>%s</strong> %s &nbsp; %s',
                esc_html__('Certificado SINPE EXPIRADO.', 'fe-woo'),
                esc_html__('Los comprobantes electrónicos están fallando.', 'fe-woo'),
                $upload_link
            );
            printf('<div class="notice notice-error"><p>%s</p></div>', $msg);
            return;
        }

        if ($code === 'expiring' && isset($status['days_until_expiry'])) {
            $days  = (int) $status['days_until_expiry'];
            $level = ($days <= 7) ? 'notice-error' : 'notice-warning';
            $msg   = sprintf(
                '<strong>%s</strong> &nbsp; %s',
                sprintf(
                    /* translators: %d days remaining until cert expiration */
                    esc_html__('El certificado SINPE expira en %d días.', 'fe-woo'),
                    $days
                ),
                $upload_link
            );
            printf('<div class="notice %s"><p>%s</p></div>', esc_attr($level), $msg);
        }
    }

    /**
     * Enqueue admin scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_admin_scripts($hook) {
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        if (!isset($_GET['tab']) || $_GET['tab'] !== 'fe') {
            return;
        }

        wp_enqueue_style(
            'fe-woo-admin',
            FE_WOO_PLUGIN_URL . 'assets/css/admin.css',
            [],
            FE_WOO_VERSION
        );

        // Also load emisores CSS for the emisores management section
        wp_enqueue_style(
            'fe-woo-emisores-admin',
            FE_WOO_PLUGIN_URL . 'assets/css/emisores-admin.css',
            [],
            FE_WOO_VERSION
        );

        wp_enqueue_script(
            'fe-woo-admin',
            FE_WOO_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            FE_WOO_VERSION,
            true
        );

        wp_localize_script('fe-woo-admin', 'feWooAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fe_woo_admin'),
            'strings' => [
                'testing' => __('Probando conexión...', 'fe-woo'),
                'success' => __('¡Conexión exitosa!', 'fe-woo'),
                'error' => __('¡Error de conexión!', 'fe-woo'),
            ],
        ]);

        // Hide WooCommerce default save button when showing emisor form
        $is_emisor_form = (isset($_GET['fe_action']) && in_array($_GET['fe_action'], ['new_emisor', 'edit_emisor']))
            || (isset($_GET['edit_emisor']) && absint($_GET['edit_emisor']) > 0);

        if ($is_emisor_form) {
            wp_add_inline_style('fe-woo-emisores-admin', '
                body.woocommerce_page_wc-settings p.submit:not(.fe-woo-submit) { display: none !important; }
            ');
        }
    }

    /**
     * Add a new settings tab to the WooCommerce settings tabs array
     *
     * @param array $settings_tabs Array of WooCommerce setting tabs.
     * @return array Modified array with new tab
     */
    public static function add_settings_tab($settings_tabs) {
        $settings_tabs['fe'] = __('Configuración FE', 'fe-woo');
        return $settings_tabs;
    }

    /**
     * Uses the WooCommerce options API to save settings
     */
    public static function update_settings() {
        // Check if critical settings are being changed
        $critical_settings = [
            FE_Woo_Hacienda_Config::OPTION_ENVIRONMENT,
            FE_Woo_Hacienda_Config::OPTION_CEDULA_JURIDICA,
            FE_Woo_Hacienda_Config::OPTION_API_USERNAME,
            FE_Woo_Hacienda_Config::OPTION_API_PASSWORD,
            FE_Woo_Hacienda_Config::OPTION_CERTIFICATE_PIN,
            FE_Woo_Hacienda_Config::OPTION_PRODUCTION_BASE_URL,
            FE_Woo_Hacienda_Config::OPTION_SANDBOX_BASE_URL,
        ];

        $settings_changed = false;
        foreach ($critical_settings as $setting) {
            if (isset($_POST[$setting])) {
                $old_value = get_option($setting, '');
                $new_value = $_POST[$setting];
                if ($old_value !== $new_value) {
                    $settings_changed = true;
                    break;
                }
            }
        }

        // Save settings
        woocommerce_update_options(self::get_settings());

        // If critical settings changed, clear test status to force re-testing
        if ($settings_changed) {
            update_option('fe_woo_last_connection_test_status', 'pending');
            delete_option('fe_woo_last_connection_test_time');
            WC_Admin_Settings::add_message(__('Configuración actualizada. Por favor pruebe la conexión para habilitar el procesamiento de facturas.', 'fe-woo'));
        }
    }

    /**
     * Get all the settings for this tab
     *
     * @return array Settings array
     */
    public static function get_settings() {
        $settings = [
            // Environment Section
            [
                'name' => __('Configuración del Ambiente', 'fe-woo'),
                'type' => 'title',
                'desc' => self::get_environment_info_html(),
                'id'   => 'fe_woo_environment_section_title',
            ],
            [
                'name'    => __('Ambiente', 'fe-woo'),
                'type'    => 'select',
                'desc'    => __('Seleccione el ambiente de API. Use Sandbox para pruebas de Hacienda, Producción para facturas en vivo.', 'fe-woo'),
                'desc_tip' => true,
                'id'      => FE_Woo_Hacienda_Config::OPTION_ENVIRONMENT,
                'options' => [
                    FE_Woo_Hacienda_Config::ENV_SANDBOX    => __('Sandbox (Pruebas Hacienda)', 'fe-woo'),
                    FE_Woo_Hacienda_Config::ENV_PRODUCTION => __('Producción (En Vivo)', 'fe-woo'),
                ],
                'default' => FE_Woo_Hacienda_Config::ENV_SANDBOX,
            ],
            [
                'name'    => __('Habilitar Registro de Depuración', 'fe-woo'),
                'type'    => 'checkbox',
                'desc'    => __('Habilitar registro detallado de solicitudes y respuestas de API', 'fe-woo'),
                'id'      => FE_Woo_Hacienda_Config::OPTION_ENABLE_DEBUG,
                'default' => 'no',
            ],
            [
                'name'    => __('Habilitar Formulario de Factura en Checkout', 'fe-woo'),
                'type'    => 'checkbox',
                'desc'    => __('Mostrar el formulario de Factura Electrónica en la página de pago para permitir que los clientes soliciten facturas', 'fe-woo'),
                'id'      => FE_Woo_Hacienda_Config::OPTION_ENABLE_CHECKOUT_FORM,
                'default' => 'yes',
            ],
            [
                'name'    => __('Pausar Procesamiento de Facturas', 'fe-woo'),
                'type'    => 'checkbox',
                'desc'    => __('Pausar el procesamiento automático de facturas y el envío a Hacienda. Las órdenes se seguirán agregando a la cola, pero no se enviarán hasta que se reanude el procesamiento.', 'fe-woo'),
                'id'      => FE_Woo_Hacienda_Config::OPTION_PAUSE_PROCESSING,
                'default' => 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_environment_section_end',
            ],

            // API URL Configuration Section
            [
                'name' => __('Configuración de URL Base de API', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Configure las URL base de API para cada ambiente. Las rutas (/recepcion/v1, /consulta/v1) se agregarán automáticamente. Deje vacío para usar las URL predeterminadas de Hacienda. Las URL del ambiente local se generan automáticamente.', 'fe-woo'),
                'id'   => 'fe_woo_api_urls_section_title',
            ],

            // Production Base URL
            [
                'name'    => __('URL Base de Producción', 'fe-woo'),
                'type'    => 'text',
                'desc'    => __('URL base de API de Hacienda en vivo (predeterminado: https://api.comprobanteselectronicos.go.cr)', 'fe-woo'),
                'desc_tip' => true,
                'id'      => FE_Woo_Hacienda_Config::OPTION_PRODUCTION_BASE_URL,
                'placeholder' => 'https://api.comprobanteselectronicos.go.cr',
                'css'     => 'width: 500px;',
            ],

            // Sandbox Base URL
            [
                'name'    => __('URL Base de Sandbox', 'fe-woo'),
                'type'    => 'text',
                'desc'    => __('URL base de API de prueba de Hacienda (predeterminado: https://api-sandbox.comprobanteselectronicos.go.cr)', 'fe-woo'),
                'desc_tip' => true,
                'id'      => FE_Woo_Hacienda_Config::OPTION_SANDBOX_BASE_URL,
                'placeholder' => 'https://api-sandbox.comprobanteselectronicos.go.cr',
                'css'     => 'width: 500px;',
            ],

            // CABYS API Endpoint
            [
                'name'    => __('Endpoint de API CABYS', 'fe-woo'),
                'type'    => 'text',
                'desc'    => __('Endpoint de API de búsqueda de códigos CABYS de Hacienda (predeterminado: https://api.hacienda.go.cr/fe/cabys)', 'fe-woo'),
                'desc_tip' => true,
                'id'      => FE_Woo_Hacienda_Config::OPTION_CABYS_API_ENDPOINT,
                'placeholder' => 'https://api.hacienda.go.cr/fe/cabys',
                'css'     => 'width: 500px;',
            ],

            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_api_urls_section_end',
            ],
        ];

        return apply_filters('fe_woo_settings', $settings);
    }

    /**
     * Get environment information HTML
     *
     * @return string HTML for environment information
     */
    private static function get_environment_info_html() {
        $current_env = FE_Woo_Hacienda_Config::get_environment();
        $site_url = get_site_url();
        $config = new FE_Woo_Hacienda_Config();

        $html = '<div style="background: #f6f7f7; padding: 15px; border-left: 3px solid #007cba; margin-bottom: 15px;">';
        $html .= '<strong>' . __('URL del Sitio Actual:', 'fe-woo') . '</strong> <code>' . esc_html($site_url) . '</code><br>';
        $html .= '<strong>' . __('Ambiente Seleccionado:', 'fe-woo') . '</strong> ';

        switch ($current_env) {
            case FE_Woo_Hacienda_Config::ENV_SANDBOX:
                $html .= '<span style="color: #dba617;">⚠ ' . __('Sandbox (Pruebas Hacienda)', 'fe-woo') . '</span><br>';
                $html .= '<strong>' . __('API de Recepción:', 'fe-woo') . '</strong> <code>' . esc_html($config->get_api_endpoint('reception')) . '</code><br>';
                $html .= '<strong>' . __('API de Consulta:', 'fe-woo') . '</strong> <code>' . esc_html($config->get_api_endpoint('consultation')) . '</code><br>';
                $html .= '<em style="color: #646970;">' . __('Usando ambiente de sandbox de Hacienda para pruebas.', 'fe-woo') . '</em>';
                break;

            case FE_Woo_Hacienda_Config::ENV_PRODUCTION:
                $html .= '<span style="color: #d63638;">⚡ ' . __('Producción (En Vivo)', 'fe-woo') . '</span><br>';
                $html .= '<strong>' . __('API de Recepción:', 'fe-woo') . '</strong> <code>' . esc_html($config->get_api_endpoint('reception')) . '</code><br>';
                $html .= '<strong>' . __('API de Consulta:', 'fe-woo') . '</strong> <code>' . esc_html($config->get_api_endpoint('consultation')) . '</code><br>';
                $html .= '<em style="color: #d63638; font-weight: 600;">' . __('⚠️ ADVERTENCIA: Usando API de Hacienda en vivo. Las facturas serán legalmente válidas.', 'fe-woo') . '</em>';
                break;
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Handle certificate upload (no longer used - certificates are per emisor)
     */
    public static function maybe_handle_certificate_upload() {
        // No longer needed - certificates are now managed per emisor
    }

    /**
     * AJAX handler for testing API connection
     */
    /**
     * AJAX handler for testing emisor connection
     */
    public static function ajax_test_emisor_connection() {
        // Clean any output buffer to prevent HTML from breaking JSON response
        while (ob_get_level()) {
            ob_end_clean();
        }
        ob_start();

        check_ajax_referer('fe_woo_test_emisor_connection', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Permisos insuficientes', 'fe-woo')]);
        }

        // Get emisor ID from request
        $emisor_id = isset($_POST['emisor_id']) ? absint($_POST['emisor_id']) : 0;
        if (!$emisor_id) {
            ob_end_clean();
            wp_send_json_error(['message' => __('ID de emisor no proporcionado', 'fe-woo')]);
        }

        // Get emisor
        $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        if (!$emisor) {
            ob_end_clean();
            wp_send_json_error(['message' => __('Emisor no encontrado', 'fe-woo')]);
        }

        // Verify emisor has required fields
        if (empty($emisor->certificate_path) || !file_exists($emisor->certificate_path)) {
            ob_end_clean();
            wp_send_json_error(['message' => __('El emisor no tiene certificado cargado.', 'fe-woo')]);
        }

        if (empty($emisor->certificate_pin)) {
            ob_end_clean();
            wp_send_json_error(['message' => __('El emisor no tiene PIN de certificado.', 'fe-woo')]);
        }

        if (empty($emisor->api_username) || empty($emisor->api_password)) {
            ob_end_clean();
            wp_send_json_error(['message' => __('El emisor no tiene credenciales de API configuradas.', 'fe-woo')]);
        }

        // Verify certificate
        $cert_verification = FE_Woo_Certificate_Handler::verify_certificate(
            $emisor->certificate_path,
            $emisor->certificate_pin
        );

        if (!$cert_verification['valid']) {
            ob_end_clean();
            wp_send_json_error([
                'message' => __('Verificación de certificado falló', 'fe-woo'),
                'error' => $cert_verification['error'] ?? '',
            ]);
        }

        // Test connection using emisor credentials
        $api_client = new FE_Woo_API_Client();
        $result = $api_client->test_connection_with_emisor($emisor);

        // Store test result status and timestamp per emisor
        if ($result['success']) {
            update_option('fe_woo_emisor_' . $emisor_id . '_connection_test_status', 'success');
            update_option('fe_woo_emisor_' . $emisor_id . '_connection_test_time', time());

            // If this is the parent emisor, also update the global status
            if ($emisor->is_parent) {
                update_option('fe_woo_last_connection_test_status', 'success');
                update_option('fe_woo_last_connection_test_time', time());
            }
        } else {
            update_option('fe_woo_emisor_' . $emisor_id . '_connection_test_status', 'failed');
            update_option('fe_woo_emisor_' . $emisor_id . '_connection_test_time', time());
        }

        // Discard any captured output
        ob_end_clean();

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * Override settings tab content to include emisores management
     */
    public static function settings_tab_content() {
        // Check if we're showing an emisor form
        $fe_action = isset($_GET['fe_action']) ? sanitize_text_field($_GET['fe_action']) : '';
        $edit_emisor_id = isset($_GET['edit_emisor']) ? absint($_GET['edit_emisor']) : 0;

        // Show any admin notices from current request (e.g., validation errors)
        settings_errors('fe_woo_emisores');

        // Show notices stored in transient (persisted across redirects)
        $transient_key = 'fe_woo_emisor_admin_notice_' . get_current_user_id();
        $transient_notice = get_transient($transient_key);
        if ($transient_notice) {
            delete_transient($transient_key);
            $notice_class = $transient_notice['type'] === 'success' ? 'notice-success' : 'notice-error';
            echo '<div class="notice ' . esc_attr($notice_class) . ' is-dismissible"><p>' . wp_kses_post($transient_notice['message']) . '</p></div>';
        }

        if ($fe_action === 'new_emisor' || $edit_emisor_id > 0 || isset($_POST['fe_woo_save_emisor'])) {
            // Show emisor form - use POST data if available (form re-render after validation error)
            $emisor_id = $edit_emisor_id ?: (!empty($_POST['emisor_id']) ? absint($_POST['emisor_id']) : 0);
            $emisor = $emisor_id ? FE_Woo_Emisor_Manager::get_emisor($emisor_id) : null;

            // If there's POST data (validation error re-render), re-validate nonce before using POST data
            if (isset($_POST['fe_woo_save_emisor']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'fe_woo_emisor_form')) {
                $post_emisor = new stdClass();
                $post_emisor->id = $emisor_id ?: '';
                $post_emisor->is_parent = !empty($_POST['is_parent']);
                $post_emisor->tipo_identificacion = sanitize_text_field($_POST['tipo_identificacion'] ?? '02');
                $post_emisor->nombre_legal = sanitize_text_field($_POST['nombre_legal'] ?? '');
                $post_emisor->cedula_juridica = sanitize_text_field($_POST['cedula_juridica'] ?? '');
                $post_emisor->nombre_comercial = sanitize_text_field($_POST['nombre_comercial'] ?? '');
                $post_emisor->api_username = sanitize_text_field($_POST['api_username'] ?? '');
                $post_emisor->api_password = $emisor ? $emisor->api_password : '';
                $post_emisor->certificate_path = $emisor ? $emisor->certificate_path : '';
                $post_emisor->certificate_pin = $emisor ? $emisor->certificate_pin : '';
                $post_emisor->actividad_economica = sanitize_text_field($_POST['actividad_economica'] ?? '');
                $post_emisor->codigo_provincia = sanitize_text_field($_POST['codigo_provincia'] ?? '');
                $post_emisor->codigo_canton = sanitize_text_field($_POST['codigo_canton'] ?? '');
                $post_emisor->codigo_distrito = sanitize_text_field($_POST['codigo_distrito'] ?? '');
                $post_emisor->codigo_barrio = sanitize_text_field($_POST['codigo_barrio'] ?? '');
                $post_emisor->direccion = sanitize_textarea_field($_POST['direccion'] ?? '');
                $post_emisor->telefono = sanitize_text_field($_POST['telefono'] ?? '');
                $post_emisor->email = sanitize_email($_POST['email'] ?? '');
                $post_emisor->active = !empty($_POST['active']);
                $emisor = $post_emisor;
            }

            self::render_emisor_form($emisor);
        } else {
            // Show normal settings with emisores table
            self::render_settings_with_emisores();
        }
    }

    /**
     * Render settings page with emisores management
     */
    private static function render_settings_with_emisores() {
        // Add enctype to form for file upload support
        echo '<script>
            jQuery(document).ready(function($) {
                $("form.woocommerce-settings").attr("enctype", "multipart/form-data");
            });
        </script>';

        // First render the standard settings (API configuration)
        woocommerce_admin_fields(self::get_settings());

        // Then render the emisores management section below
        self::render_emisores_section();
    }

    /**
     * Render emisores management section
     */
    private static function render_emisores_section() {
        $emisores = FE_Woo_Emisor_Manager::get_all_emisores(false); // Include inactive
        $has_parent = FE_Woo_Emisor_Manager::get_parent_emisor() !== null;

        ?>
        <div class="fe-woo-emisores-section" style="margin-bottom: 30px;">
            <h2><?php esc_html_e('Gestión de Emisores', 'fe-woo'); ?></h2>

            <?php if (!$has_parent) : ?>
                <div class="notice notice-error inline" style="margin: 15px 0;">
                    <p>
                        <strong><?php esc_html_e('⚠️ IMPORTANTE:', 'fe-woo'); ?></strong>
                        <?php esc_html_e('No hay emisor por defecto configurado. Las facturas electrónicas NO se procesarán hasta que configure un emisor por defecto.', 'fe-woo'); ?>
                    </p>
                </div>
            <?php endif; ?>

            <p class="description" style="margin-bottom: 15px;">
                <?php esc_html_e('Configure los emisores para generar facturas electrónicas. El emisor por defecto (⭐) se usará para productos sin emisor asignado.', 'fe-woo'); ?>
            </p>

            <div class="fe-woo-emisores-header" style="margin: 15px 0;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=fe&fe_action=new_emisor')); ?>" class="button button-primary">
                    <span class="dashicons dashicons-plus-alt" style="vertical-align: middle;"></span>
                    <?php esc_html_e('Agregar Nuevo Emisor', 'fe-woo'); ?>
                </a>
            </div>

            <?php if (empty($emisores)) : ?>
                <div class="notice notice-warning inline">
                    <p>
                        <?php esc_html_e('No hay emisores configurados. Cree el emisor por defecto para comenzar a generar facturas electrónicas.', 'fe-woo'); ?>
                    </p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped fe-woo-emisores-table">
                    <thead>
                        <tr>
                            <th width="5%"><?php esc_html_e('Tipo', 'fe-woo'); ?></th>
                            <th width="18%"><?php esc_html_e('Nombre Legal', 'fe-woo'); ?></th>
                            <th width="12%"><?php esc_html_e('Cédula Jurídica', 'fe-woo'); ?></th>
                            <th width="8%"><?php esc_html_e('Actividad', 'fe-woo'); ?></th>
                            <th width="8%"><?php esc_html_e('Cert.', 'fe-woo'); ?></th>
                            <th width="8%"><?php esc_html_e('Estado', 'fe-woo'); ?></th>
                            <th width="12%"><?php esc_html_e('Conexión', 'fe-woo'); ?></th>
                            <th width="29%"><?php esc_html_e('Acciones', 'fe-woo'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($emisores as $emisor) : ?>
                            <tr data-emisor-id="<?php echo esc_attr($emisor->id); ?>">
                                <td>
                                    <?php if ($emisor->is_parent) : ?>
                                        <span class="fe-woo-badge fe-woo-badge-parent" title="<?php esc_attr_e('Emisor por Defecto', 'fe-woo'); ?>">
                                            <span class="dashicons dashicons-star-filled"></span>
                                        </span>
                                    <?php else : ?>
                                        <span class="fe-woo-badge fe-woo-badge-child" title="<?php esc_attr_e('Emisor Hijo', 'fe-woo'); ?>">
                                            <span class="dashicons dashicons-businessman"></span>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo esc_html($emisor->nombre_legal); ?></strong>
                                    <?php if ($emisor->nombre_comercial) : ?>
                                        <br><small><?php echo esc_html($emisor->nombre_comercial); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($emisor->cedula_juridica); ?></td>
                                <td><?php echo esc_html($emisor->actividad_economica); ?></td>
                                <td>
                                    <?php echo self::render_certificate_status_badge($emisor); ?>
                                </td>
                                <td>
                                    <?php if ($emisor->active) : ?>
                                        <span class="fe-woo-status fe-woo-status-active"><?php esc_html_e('Activo', 'fe-woo'); ?></span>
                                    <?php else : ?>
                                        <span class="fe-woo-status fe-woo-status-inactive"><?php esc_html_e('Inactivo', 'fe-woo'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fe-woo-connection-status" id="connection-status-<?php echo esc_attr($emisor->id); ?>"></span>
                                </td>
                                <td>
                                    <button type="button" class="button button-small fe-woo-test-emisor-connection" data-emisor-id="<?php echo esc_attr($emisor->id); ?>">
                                        <?php esc_html_e('Probar', 'fe-woo'); ?>
                                    </button>
                                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=fe&edit_emisor=' . $emisor->id)); ?>" class="button button-small">
                                        <?php esc_html_e('Editar', 'fe-woo'); ?>
                                    </a>
                                    <?php if (!$emisor->is_parent) : ?>
                                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=wc-settings&tab=fe&fe_action=delete_emisor&emisor_id=' . $emisor->id), 'fe_woo_delete_emisor_' . $emisor->id)); ?>" class="button button-small button-link-delete" onclick="return confirm('<?php esc_attr_e('¿Está seguro que desea eliminar este emisor?', 'fe-woo'); ?>');">
                                            <?php esc_html_e('Eliminar', 'fe-woo'); ?>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <script type="text/javascript">
                jQuery(document).ready(function($) {
                    $('.fe-woo-test-emisor-connection').on('click', function() {
                        var $button = $(this);
                        var emisorId = $button.data('emisor-id');
                        var $status = $('#connection-status-' + emisorId);

                        $button.prop('disabled', true).text('<?php esc_html_e('Probando...', 'fe-woo'); ?>');
                        $status.html('<span style="color: #666;"><?php esc_html_e('Conectando...', 'fe-woo'); ?></span>');

                        $.ajax({
                            url: ajaxurl,
                            type: 'POST',
                            data: {
                                action: 'fe_woo_test_emisor_connection',
                                emisor_id: emisorId,
                                nonce: '<?php echo wp_create_nonce('fe_woo_test_emisor_connection'); ?>'
                            },
                            success: function(response) {
                                if (response.success) {
                                    $status.html('<span style="color: #46b450;"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e('OK', 'fe-woo'); ?></span>');
                                } else {
                                    var errorMsg = response.data && response.data.message ? response.data.message : '<?php esc_html_e('Error', 'fe-woo'); ?>';
                                    var errorDetail = response.data && response.data.error ? response.data.error : '';
                                    var displayError = errorDetail ? errorDetail : errorMsg;
                                    var $errorSpan = $('<span style="color: #dc3232;"><span class="dashicons dashicons-dismiss"></span> </span>');
                                    $errorSpan.append($('<span>').text(displayError));
                                    $status.empty().append($errorSpan);
                                }
                            },
                            error: function() {
                                $status.html('<span style="color: #dc3232;"><span class="dashicons dashicons-dismiss"></span> <?php esc_html_e('Error', 'fe-woo'); ?></span>');
                            },
                            complete: function() {
                                $button.prop('disabled', false).text('<?php esc_html_e('Probar', 'fe-woo'); ?>');
                            }
                        });
                    });
                });
                </script>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Render certificate status badge for list view
     *
     * @param object $emisor Emisor object
     * @return string HTML for certificate status badge
     */
    private static function render_certificate_status_badge($emisor) {
        if (empty($emisor->certificate_path) || !file_exists($emisor->certificate_path)) {
            return '<span class="dashicons dashicons-warning" style="color: #dc3232;" title="' . esc_attr__('Sin certificado', 'fe-woo') . '"></span>';
        }

        if (empty($emisor->certificate_pin)) {
            return '<span class="dashicons dashicons-yes-alt" style="color: #dba617;" title="' . esc_attr__('Certificado cargado (sin PIN para verificar)', 'fe-woo') . '"></span>';
        }

        $verification = FE_Woo_Certificate_Handler::verify_certificate($emisor->certificate_path, $emisor->certificate_pin);

        if (!$verification['valid']) {
            return '<span class="dashicons dashicons-dismiss" style="color: #dc3232;" title="' . esc_attr($verification['error'] ?? __('Certificado inválido', 'fe-woo')) . '"></span>';
        }

        $cert_info = isset($verification['cert_info']) ? $verification['cert_info'] : null;
        $valid_to = isset($cert_info['validTo_time_t']) ? $cert_info['validTo_time_t'] : null;

        if ($valid_to) {
            $days_until_expiry = ceil(($valid_to - time()) / 86400);
            $expiry_date = date_i18n('d/m/Y', $valid_to);

            if ($days_until_expiry <= 0) {
                return '<span class="dashicons dashicons-dismiss" style="color: #dc3232;" title="' . esc_attr__('Certificado EXPIRADO', 'fe-woo') . '"></span>';
            } elseif ($days_until_expiry <= 30) {
                return '<span class="dashicons dashicons-warning" style="color: #dba617;" title="' . esc_attr(sprintf(__('Expira: %s (%d días)', 'fe-woo'), $expiry_date, $days_until_expiry)) . '"></span>';
            } else {
                return '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . esc_attr(sprintf(__('Válido hasta: %s (%d días)', 'fe-woo'), $expiry_date, $days_until_expiry)) . '"></span>';
            }
        }

        return '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' . esc_attr__('Certificado válido', 'fe-woo') . '"></span>';
    }

    /**
     * Handle emisor form submission
     */
    public static function handle_emisor_form_submission() {
        // Check if we're on the right page
        if (!isset($_GET['page']) || $_GET['page'] !== 'wc-settings') {
            return;
        }
        if (!isset($_GET['tab']) || $_GET['tab'] !== 'fe') {
            return;
        }

        // Handle save emisor
        if (isset($_POST['fe_woo_save_emisor']) && check_admin_referer('fe_woo_emisor_form')) {
            self::process_save_emisor();
        }

        // Handle delete emisor
        if (isset($_GET['fe_action']) && $_GET['fe_action'] === 'delete_emisor' && isset($_GET['emisor_id'])) {
            if (check_admin_referer('fe_woo_delete_emisor_' . $_GET['emisor_id'])) {
                self::process_delete_emisor(absint($_GET['emisor_id']));
            }
        }
    }

    /**
     * Process save emisor form
     */
    private static function process_save_emisor() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'fe-woo'));
        }

        $emisor_id = !empty($_POST['emisor_id']) ? absint($_POST['emisor_id']) : null;

        // Prepare data
        $data = [
            'is_parent' => !empty($_POST['is_parent']),
            'nombre_legal' => sanitize_text_field($_POST['nombre_legal']),
            'cedula_juridica' => sanitize_text_field($_POST['cedula_juridica']),
            'nombre_comercial' => !empty($_POST['nombre_comercial']) ? sanitize_text_field($_POST['nombre_comercial']) : null,
            'api_username' => !empty($_POST['api_username']) ? sanitize_text_field($_POST['api_username']) : null,
            'api_password' => !empty($_POST['api_password']) ? sanitize_text_field($_POST['api_password']) : null,
            'certificate_pin' => !empty($_POST['certificate_pin']) ? sanitize_text_field($_POST['certificate_pin']) : null,
            'actividad_economica' => sanitize_text_field($_POST['actividad_economica']),
            'codigo_provincia' => sanitize_text_field($_POST['codigo_provincia']),
            'codigo_canton' => sanitize_text_field($_POST['codigo_canton']),
            'codigo_distrito' => sanitize_text_field($_POST['codigo_distrito']),
            'codigo_barrio' => !empty($_POST['codigo_barrio']) ? sanitize_text_field($_POST['codigo_barrio']) : null,
            'direccion' => sanitize_textarea_field($_POST['direccion']),
            'telefono' => !empty($_POST['telefono']) ? sanitize_text_field($_POST['telefono']) : null,
            'email' => !empty($_POST['email']) ? sanitize_email($_POST['email']) : null,
            'active' => !empty($_POST['active']),
        ];

        // Handle certificate file upload if present
        if (!empty($_FILES['certificate_file']) && $_FILES['certificate_file']['error'] === UPLOAD_ERR_OK) {
            $upload_result = FE_Woo_Certificate_Handler::upload_certificate($_FILES['certificate_file']);
            if ($upload_result['success']) {
                $data['certificate_path'] = $upload_result['file_path'];
            } else {
                add_settings_error(
                    'fe_woo_emisores',
                    'certificate_error',
                    $upload_result['message'],
                    'error'
                );
                return; // Don't save emisor if certificate upload failed
            }
        }

        // Create or update
        if ($emisor_id) {
            $result = FE_Woo_Emisor_Manager::update_emisor($emisor_id, $data);
        } else {
            $result = FE_Woo_Emisor_Manager::create_emisor($data);
        }

        if ($result['success']) {
            // Store success message in transient so it persists across redirect
            set_transient('fe_woo_emisor_admin_notice_' . get_current_user_id(), [
                'type' => 'success',
                'message' => $emisor_id ? __('Emisor actualizado correctamente.', 'fe-woo') : __('Emisor creado correctamente.', 'fe-woo'),
            ], 30);

            // Redirect back to FE settings list on success
            wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=fe'));
            exit;
        }

        // On error, add error messages and don't redirect - let the form re-render with errors visible
        $error_message = isset($result['errors']) ? implode('<br>', $result['errors']) : ($result['message'] ?? __('Error al guardar el emisor.', 'fe-woo'));
        add_settings_error(
            'fe_woo_emisores',
            'emisor_error',
            $error_message,
            'error'
        );
    }

    /**
     * Process delete emisor
     *
     * @param int $emisor_id Emisor ID to delete
     */
    private static function process_delete_emisor($emisor_id) {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('No tienes permisos para realizar esta acción.', 'fe-woo'));
        }

        $result = FE_Woo_Emisor_Manager::delete_emisor($emisor_id);

        if ($result['success']) {
            set_transient('fe_woo_emisor_admin_notice_' . get_current_user_id(), [
                'type' => 'success',
                'message' => __('Emisor eliminado correctamente.', 'fe-woo'),
            ], 30);
        } else {
            $error_message = !empty($result['errors']) ? implode(' ', $result['errors']) : __('Error al eliminar el emisor.', 'fe-woo');
            set_transient('fe_woo_emisor_admin_notice_' . get_current_user_id(), [
                'type' => 'error',
                'message' => $error_message,
            ], 30);
        }

        wp_safe_redirect(admin_url('admin.php?page=wc-settings&tab=fe'));
        exit;
    }

    /**
     * Render emisor form
     *
     * @param object|null $emisor Emisor object for editing, null for new
     */
    private static function render_emisor_form($emisor = null) {
        $is_edit = $emisor !== null && !empty($emisor->id);
        $page_title = $is_edit ? __('Editar Emisor', 'fe-woo') : __('Agregar Nuevo Emisor', 'fe-woo');

        // Build settings array
        $settings = self::get_emisor_form_settings($emisor);

        ?>
        <div class="wrap woocommerce">
            <h1>
                <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=fe')); ?>" class="page-title-action" style="margin-right: 10px;">
                    &larr; <?php esc_html_e('Volver', 'fe-woo'); ?>
                </a>
                <?php echo esc_html($page_title); ?>
            </h1>

            <form method="post" action="" enctype="multipart/form-data" class="woocommerce-settings">
                <?php wp_nonce_field('fe_woo_emisor_form'); ?>
                <input type="hidden" name="emisor_id" value="<?php echo esc_attr($is_edit ? $emisor->id : ''); ?>">

                <?php
                // Add custom field type handlers
                add_action('woocommerce_admin_field_emisor_certificate_upload', [__CLASS__, 'output_certificate_upload_field']);

                // Output fields using WooCommerce API
                woocommerce_admin_fields($settings);
                ?>

                <p class="submit fe-woo-submit">
                    <button type="submit" name="fe_woo_save_emisor" class="button button-primary button-large">
                        <?php echo $is_edit ? esc_html__('Actualizar Emisor', 'fe-woo') : esc_html__('Guardar Emisor', 'fe-woo'); ?>
                    </button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=fe')); ?>" class="button button-large">
                        <?php esc_html_e('Cancelar', 'fe-woo'); ?>
                    </a>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Get emisor form settings array
     *
     * @param object|null $emisor Emisor object for editing
     * @return array Settings array
     */
    private static function get_emisor_form_settings($emisor) {
        $is_edit = $emisor !== null && !empty($emisor->id);
        $has_data = $emisor !== null; // true when editing OR re-rendering with POST data after validation error

        return [
            // Legal Information Section
            [
                'name' => __('Información Legal', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Información legal del emisor para facturas electrónicas.', 'fe-woo'),
                'id'   => 'fe_woo_emisor_legal_section',
            ],
            [
                'name'    => __('Emisor por Defecto', 'fe-woo'),
                'type'    => 'checkbox',
                'desc'    => __('Marcar si este es el emisor por defecto. Solo puede haber uno. Se usa para productos sin emisor específico asignado.', 'fe-woo'),
                'id'      => 'is_parent',
                'value'   => $has_data && $emisor->is_parent ? 'yes' : 'no',
            ],
            [
                'name'              => __('Nombre Legal', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Nombre legal registrado de la empresa.', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'nombre_legal',
                'value'             => $has_data ? $emisor->nombre_legal : '',
                'custom_attributes' => ['required' => 'required'],
            ],
            [
                'name'              => __('Cédula Jurídica', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Número de identificación legal (solo números).', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'cedula_juridica',
                'value'             => $has_data ? $emisor->cedula_juridica : '',
                'custom_attributes' => ['required' => 'required', 'pattern' => '[0-9]+'],
            ],
            [
                'name'              => __('Nombre Comercial', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Nombre comercial de la empresa (máx 80 caracteres).', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'nombre_comercial',
                'value'             => $has_data && $emisor->nombre_comercial ? $emisor->nombre_comercial : '',
                'custom_attributes' => ['required' => 'required', 'maxlength' => '80'],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_legal_section_end',
            ],

            // API Credentials Section
            [
                'name' => __('Credenciales de API', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Credenciales de API de Hacienda para este emisor.', 'fe-woo'),
                'id'   => 'fe_woo_emisor_api_section',
            ],
            [
                'name'     => __('Usuario de API', 'fe-woo'),
                'type'     => 'text',
                'desc'     => __('Nombre de usuario de API ATV.', 'fe-woo'),
                'desc_tip' => true,
                'id'       => 'api_username',
                'value'    => $has_data && $emisor->api_username ? $emisor->api_username : '',
            ],
            [
                'name'              => __('Contraseña de API', 'fe-woo'),
                'type'              => 'password',
                'desc'              => $is_edit && !empty($emisor->api_password)
                    ? __('Deje vacío para mantener la contraseña actual.', 'fe-woo')
                    : __('Contraseña de API ATV.', 'fe-woo'),
                'desc_tip'          => !($is_edit && !empty($emisor->api_password)),
                'id'                => 'api_password',
                'value'             => '',
                'placeholder'       => $is_edit && !empty($emisor->api_password) ? '********' : '',
                'custom_attributes' => ['autocomplete' => 'new-password'],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_api_section_end',
            ],

            // Certificate Section
            [
                'name' => __('Certificado Criptográfico', 'fe-woo'),
                'type' => 'title',
                'desc' => self::get_emisor_certificate_section_desc($emisor),
                'id'   => 'fe_woo_emisor_certificate_section',
            ],
            [
                'type'   => 'emisor_certificate_upload',
                'id'     => 'certificate_file',
                'emisor' => $emisor,
            ],
            [
                'name'              => __('PIN del Certificado', 'fe-woo'),
                'type'              => 'password',
                'desc'              => $is_edit && !empty($emisor->certificate_pin)
                    ? __('Deje vacío para mantener el PIN actual.', 'fe-woo')
                    : __('PIN/contraseña de su certificado criptográfico.', 'fe-woo'),
                'desc_tip'          => !($is_edit && !empty($emisor->certificate_pin)),
                'id'                => 'certificate_pin',
                'value'             => '',
                'placeholder'       => $is_edit && !empty($emisor->certificate_pin) ? '********' : '',
                'custom_attributes' => ['autocomplete' => 'new-password'],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_certificate_section_end',
            ],

            // Fiscal Information Section
            [
                'name' => __('Información Fiscal', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Información fiscal requerida para facturas electrónicas.', 'fe-woo'),
                'id'   => 'fe_woo_emisor_fiscal_section',
            ],
            [
                'name'              => __('Actividad Económica', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Código de actividad económica registrado en Hacienda.', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'actividad_economica',
                'value'             => $has_data ? $emisor->actividad_economica : '',
                'custom_attributes' => ['required' => 'required'],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_fiscal_section_end',
            ],

            // Location Section
            [
                'name' => __('Ubicación', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Códigos de ubicación según los estándares de Hacienda.', 'fe-woo'),
                'id'   => 'fe_woo_emisor_location_section',
            ],
            [
                'name'              => __('Código de Provincia', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Código de provincia (ej., 1 para San José).', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'codigo_provincia',
                'value'             => $has_data ? $emisor->codigo_provincia : '',
                'custom_attributes' => ['required' => 'required', 'maxlength' => '2'],
                'css'               => 'width: 80px;',
            ],
            [
                'name'              => __('Código de Cantón', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Código de cantón dentro de la provincia.', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'codigo_canton',
                'value'             => $has_data ? $emisor->codigo_canton : '',
                'custom_attributes' => ['required' => 'required', 'maxlength' => '2'],
                'css'               => 'width: 80px;',
            ],
            [
                'name'              => __('Código de Distrito', 'fe-woo'),
                'type'              => 'text',
                'desc'              => __('Código de distrito dentro del cantón.', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'codigo_distrito',
                'value'             => $has_data ? $emisor->codigo_distrito : '',
                'custom_attributes' => ['required' => 'required', 'maxlength' => '2'],
                'css'               => 'width: 80px;',
            ],
            [
                'name'     => __('Código de Barrio', 'fe-woo'),
                'type'     => 'text',
                'desc'     => __('Código de barrio (opcional).', 'fe-woo'),
                'desc_tip' => true,
                'id'       => 'codigo_barrio',
                'value'    => $has_data && $emisor->codigo_barrio ? $emisor->codigo_barrio : '',
                'css'      => 'width: 80px;',
            ],
            [
                'name'              => __('Dirección', 'fe-woo'),
                'type'              => 'textarea',
                'desc'              => __('Dirección completa de la empresa.', 'fe-woo'),
                'desc_tip'          => true,
                'id'                => 'direccion',
                'value'             => $has_data ? $emisor->direccion : '',
                'custom_attributes' => ['required' => 'required'],
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_location_section_end',
            ],

            // Contact Section
            [
                'name' => __('Información de Contacto', 'fe-woo'),
                'type' => 'title',
                'desc' => __('Información de contacto del emisor.', 'fe-woo'),
                'id'   => 'fe_woo_emisor_contact_section',
            ],
            [
                'name'     => __('Teléfono', 'fe-woo'),
                'type'     => 'text',
                'desc'     => __('Número de teléfono de contacto.', 'fe-woo'),
                'desc_tip' => true,
                'id'       => 'telefono',
                'value'    => $has_data && $emisor->telefono ? $emisor->telefono : '',
            ],
            [
                'name'     => __('Email', 'fe-woo'),
                'type'     => 'email',
                'desc'     => __('Correo electrónico de contacto.', 'fe-woo'),
                'desc_tip' => true,
                'id'       => 'email',
                'value'    => $has_data && $emisor->email ? $emisor->email : '',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_contact_section_end',
            ],

            // Status Section
            [
                'name' => __('Estado', 'fe-woo'),
                'type' => 'title',
                'id'   => 'fe_woo_emisor_status_section',
            ],
            [
                'name'  => __('Emisor Activo', 'fe-woo'),
                'type'  => 'checkbox',
                'desc'  => __('Marcar para activar este emisor.', 'fe-woo'),
                'id'    => 'active',
                'value' => !$has_data || $emisor->active ? 'yes' : 'no',
            ],
            [
                'type' => 'sectionend',
                'id'   => 'fe_woo_emisor_status_section_end',
            ],
        ];
    }

    /**
     * Get certificate section description for emisor form
     *
     * @param object|null $emisor Emisor object
     * @return string HTML description
     */
    private static function get_emisor_certificate_section_desc($emisor) {
        if (!$emisor) {
            return '<div class="notice notice-info inline"><p><span class="dashicons dashicons-info"></span> ' .
                   esc_html__('Cargue su archivo de certificado .p12 o .pfx', 'fe-woo') . '</p></div>';
        }

        if (empty($emisor->certificate_path) || !file_exists($emisor->certificate_path)) {
            return '<div class="notice notice-warning inline"><p><span class="dashicons dashicons-warning"></span> ' .
                   esc_html__('No hay certificado cargado. Suba un archivo .p12 o .pfx.', 'fe-woo') . '</p></div>';
        }

        if (empty($emisor->certificate_pin)) {
            return '<div class="notice notice-warning inline"><p><span class="dashicons dashicons-warning"></span> ' .
                   esc_html__('Certificado cargado pero falta el PIN para verificar su validez.', 'fe-woo') . '</p></div>';
        }

        // Verify certificate and show status
        return self::render_certificate_status($emisor->certificate_path, $emisor->certificate_pin);
    }

    /**
     * Output certificate upload field for emisor form
     *
     * @param array $value Field settings
     */
    public static function output_certificate_upload_field($value) {
        $emisor = isset($value['emisor']) ? $value['emisor'] : null;
        $has_cert = $emisor && !empty($emisor->certificate_path) && file_exists($emisor->certificate_path);
        ?>
        <tr valign="top">
            <th scope="row" class="titledesc">
                <label for="certificate_file"><?php esc_html_e('Archivo de Certificado', 'fe-woo'); ?></label>
            </th>
            <td class="forminp">
                <input
                    type="file"
                    name="certificate_file"
                    id="certificate_file"
                    accept=".p12,.pfx"
                />
                <p class="description">
                    <?php esc_html_e('Archivo .p12 o .pfx de su certificado criptográfico.', 'fe-woo'); ?>
                </p>
                <?php if ($has_cert) : ?>
                    <p style="color: #46b450; margin-top: 5px;">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Certificado cargado. Suba uno nuevo para reemplazarlo.', 'fe-woo'); ?>
                    </p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    /**
     * Render certificate status HTML
     *
     * @param string $cert_path Path to certificate file
     * @param string $pin Certificate PIN
     * @return string HTML for certificate status
     */
    private static function render_certificate_status($cert_path, $pin) {
        $verification = FE_Woo_Certificate_Handler::verify_certificate($cert_path, $pin);

        $html = '<div style="margin: 10px 0; padding: 15px; border-radius: 4px; ';

        if ($verification['valid']) {
            $cert_info = isset($verification['cert_info']) ? $verification['cert_info'] : null;
            $valid_to = isset($cert_info['validTo_time_t']) ? $cert_info['validTo_time_t'] : null;

            if ($valid_to) {
                $days_until_expiry = ceil(($valid_to - time()) / 86400);

                if ($days_until_expiry <= 0) {
                    // Expired
                    $html .= 'background: #fcf0f0; border-left: 4px solid #dc3232;">';
                    $html .= '<p style="margin: 0; color: #dc3232; font-weight: 600;">';
                    $html .= '<span class="dashicons dashicons-dismiss" style="vertical-align: middle;"></span> ';
                    $html .= esc_html__('Certificado EXPIRADO', 'fe-woo');
                    $html .= '</p>';
                } elseif ($days_until_expiry <= 30) {
                    // Expiring soon
                    $html .= 'background: #fff8e5; border-left: 4px solid #dba617;">';
                    $html .= '<p style="margin: 0; color: #826200; font-weight: 600;">';
                    $html .= '<span class="dashicons dashicons-warning" style="vertical-align: middle;"></span> ';
                    $html .= sprintf(
                        esc_html__('Certificado expira en %d días', 'fe-woo'),
                        $days_until_expiry
                    );
                    $html .= '</p>';
                } else {
                    // Valid
                    $html .= 'background: #ecf7ed; border-left: 4px solid #46b450;">';
                    $html .= '<p style="margin: 0; color: #1e4620; font-weight: 600;">';
                    $html .= '<span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span> ';
                    $html .= esc_html__('Certificado válido', 'fe-woo');
                    $html .= '</p>';
                }

                // Certificate details
                $html .= '<div style="margin-top: 10px; font-size: 13px;">';
                $html .= '<table style="border-collapse: collapse;">';

                // Valid from
                if (isset($cert_info['validFrom_time_t'])) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 3px 10px 3px 0; font-weight: 600;">' . esc_html__('Válido desde:', 'fe-woo') . '</td>';
                    $html .= '<td style="padding: 3px 0;">' . esc_html(date_i18n('d/m/Y', $cert_info['validFrom_time_t'])) . '</td>';
                    $html .= '</tr>';
                }

                // Valid to
                $html .= '<tr>';
                $html .= '<td style="padding: 3px 10px 3px 0; font-weight: 600;">' . esc_html__('Válido hasta:', 'fe-woo') . '</td>';
                $html .= '<td style="padding: 3px 0;">' . esc_html(date_i18n('d/m/Y', $valid_to));
                if ($days_until_expiry > 0) {
                    $html .= ' <span style="color: #666;">(' . sprintf(esc_html__('%d días restantes', 'fe-woo'), $days_until_expiry) . ')</span>';
                }
                $html .= '</td>';
                $html .= '</tr>';

                // Subject (CN)
                if (isset($cert_info['subject']['CN'])) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 3px 10px 3px 0; font-weight: 600;">' . esc_html__('Sujeto:', 'fe-woo') . '</td>';
                    $html .= '<td style="padding: 3px 0;">' . esc_html($cert_info['subject']['CN']) . '</td>';
                    $html .= '</tr>';
                }

                // Serial number
                if (isset($cert_info['serialNumber'])) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 3px 10px 3px 0; font-weight: 600;">' . esc_html__('Número de serie:', 'fe-woo') . '</td>';
                    $html .= '<td style="padding: 3px 0;"><code style="font-size: 11px;">' . esc_html($cert_info['serialNumber']) . '</code></td>';
                    $html .= '</tr>';
                }

                // Issuer
                if (isset($cert_info['issuer']['CN'])) {
                    $html .= '<tr>';
                    $html .= '<td style="padding: 3px 10px 3px 0; font-weight: 600;">' . esc_html__('Emisor:', 'fe-woo') . '</td>';
                    $html .= '<td style="padding: 3px 0;">' . esc_html($cert_info['issuer']['CN']) . '</td>';
                    $html .= '</tr>';
                }

                $html .= '</table>';
                $html .= '</div>';
            } else {
                $html .= 'background: #ecf7ed; border-left: 4px solid #46b450;">';
                $html .= '<p style="margin: 0; color: #1e4620;">';
                $html .= '<span class="dashicons dashicons-yes-alt" style="vertical-align: middle;"></span> ';
                $html .= esc_html__('Certificado válido', 'fe-woo');
                $html .= '</p>';
            }
        } else {
            // Invalid certificate
            $html .= 'background: #fcf0f0; border-left: 4px solid #dc3232;">';
            $html .= '<p style="margin: 0; color: #dc3232; font-weight: 600;">';
            $html .= '<span class="dashicons dashicons-dismiss" style="vertical-align: middle;"></span> ';
            $html .= esc_html__('Certificado inválido', 'fe-woo');
            $html .= '</p>';
            if (isset($verification['error'])) {
                $html .= '<p style="margin: 5px 0 0 0; color: #8b0000;">';
                $html .= esc_html($verification['error']);
                $html .= '</p>';
            }
        }

        $html .= '</div>';

        return $html;
    }
}
