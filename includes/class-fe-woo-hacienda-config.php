<?php
/**
 * Hacienda API Configuration Class
 *
 * Manages all configuration settings for Costa Rica's Hacienda electronic invoicing API
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Hacienda_Config
 *
 * Centralized configuration management for Hacienda API integration
 */
class FE_Woo_Hacienda_Config {

    /**
     * Environment constants
     */
    const ENV_PRODUCTION = 'production';
    const ENV_SANDBOX = 'sandbox';
    const ENV_LOCAL = 'local';

    /**
     * Default API base URLs
     */
    private static $default_base_urls = [
        'production' => 'https://api.comprobanteselectronicos.go.cr',
        'sandbox' => 'https://api-sandbox.comprobanteselectronicos.go.cr',
    ];

    /**
     * API route paths
     */
    private static $api_routes = [
        'reception' => '/recepcion/v1',
        'consultation' => '/consulta/v1',
    ];

    /**
     * Configuration option keys
     */
    const OPTION_ENVIRONMENT = 'fe_woo_environment';
    const OPTION_CEDULA_JURIDICA = 'fe_woo_cedula_juridica';
    const OPTION_COMPANY_NAME = 'fe_woo_company_name';
    const OPTION_API_USERNAME = 'fe_woo_api_username';
    const OPTION_API_PASSWORD = 'fe_woo_api_password';
    const OPTION_CERTIFICATE_PATH = 'fe_woo_certificate_path';
    const OPTION_CERTIFICATE_PIN = 'fe_woo_certificate_pin';
    const OPTION_ECONOMIC_ACTIVITY = 'fe_woo_economic_activity';
    const OPTION_PROVINCE_CODE = 'fe_woo_province_code';
    const OPTION_CANTON_CODE = 'fe_woo_canton_code';
    const OPTION_DISTRICT_CODE = 'fe_woo_district_code';
    const OPTION_NEIGHBORHOOD_CODE = 'fe_woo_neighborhood_code';
    const OPTION_ADDRESS = 'fe_woo_address';
    const OPTION_PHONE = 'fe_woo_phone';
    const OPTION_EMAIL = 'fe_woo_email';
    const OPTION_ENABLE_DEBUG = 'fe_woo_enable_debug';
    const OPTION_ENABLE_CHECKOUT_FORM = 'fe_woo_enable_checkout_form';
    const OPTION_PAUSE_PROCESSING = 'fe_woo_pause_processing';

    // Base URL Configuration Options
    const OPTION_PRODUCTION_BASE_URL = 'fe_woo_production_base_url';
    const OPTION_SANDBOX_BASE_URL = 'fe_woo_sandbox_base_url';
    const OPTION_CABYS_API_ENDPOINT = 'fe_woo_cabys_api_endpoint';

    /**
     * Get current environment
     *
     * @return string Current environment (production, sandbox, or test)
     */
    public static function get_environment() {
        return get_option(self::OPTION_ENVIRONMENT, self::ENV_SANDBOX);
    }

    /**
     * Set environment
     *
     * @param string $environment Environment to set
     * @return bool True on success
     */
    public static function set_environment($environment) {
        $allowed_envs = [self::ENV_PRODUCTION, self::ENV_SANDBOX, self::ENV_LOCAL];
        if (!in_array($environment, $allowed_envs, true)) {
            return false;
        }
        return update_option(self::OPTION_ENVIRONMENT, $environment);
    }

    /**
     * Get API endpoint URL
     *
     * @param string $type Type of endpoint (reception or consultation)
     * @return string|null Full endpoint URL or null if not found
     */
    public function get_api_endpoint($type = 'reception') {
        $environment = self::get_environment();
        $base_url = self::get_base_url($environment);

        // Get the route for this endpoint type
        $route = isset(self::$api_routes[$type]) ? self::$api_routes[$type] : '';

        if (empty($base_url) || empty($route)) {
            return null;
        }

        // Build full URL: base_url + route
        return rtrim($base_url, '/') . $route;
    }

    /**
     * Get base URL for environment
     *
     * @param string $environment Environment name (production, sandbox, local)
     * @return string Base URL
     */
    public static function get_base_url($environment) {
        // For local environment, always use site URL
        if ($environment === self::ENV_LOCAL) {
            return get_site_url() . '/wp-json/fe-woo/v1';
        }

        // Get custom base URL from options
        $option_key = self::get_base_url_option_key($environment);
        if ($option_key) {
            $url = get_option($option_key, '');

            // If no URL is saved, use default
            if (empty($url) && isset(self::$default_base_urls[$environment])) {
                return self::$default_base_urls[$environment];
            }

            return $url;
        }

        return '';
    }

    /**
     * Get option key for environment base URL
     *
     * @param string $environment Environment name
     * @return string|null Option key or null
     */
    private static function get_base_url_option_key($environment) {
        $map = [
            'production' => self::OPTION_PRODUCTION_BASE_URL,
            'sandbox' => self::OPTION_SANDBOX_BASE_URL,
        ];

        return isset($map[$environment]) ? $map[$environment] : null;
    }

    /**
     * Get site URL
     *
     * @return string Current site URL
     */
    public static function get_site_url() {
        return get_site_url();
    }

    /**
     * Get local API base URL
     *
     * @return string Local API base URL
     */
    public static function get_local_api_base() {
        return get_site_url() . '/wp-json/fe-woo/v1';
    }

    /**
     * Get company identification (Cédula Jurídica)
     *
     * @return string Company legal ID
     */
    public static function get_cedula_juridica() {
        return get_option(self::OPTION_CEDULA_JURIDICA, '');
    }

    /**
     * Get company name
     *
     * @return string Company name
     */
    public static function get_company_name() {
        return get_option(self::OPTION_COMPANY_NAME, '');
    }

    /**
     * Get API username
     *
     * @return string API username
     */
    public static function get_api_username() {
        return get_option(self::OPTION_API_USERNAME, '');
    }

    /**
     * Get API password
     *
     * @return string API password
     */
    public static function get_api_password() {
        return get_option(self::OPTION_API_PASSWORD, '');
    }

    /**
     * Get certificate path
     *
     * @return string Path to certificate file
     */
    public static function get_certificate_path() {
        return get_option(self::OPTION_CERTIFICATE_PATH, '');
    }

    /**
     * Get certificate PIN
     *
     * @return string Certificate PIN/password
     */
    public static function get_certificate_pin() {
        return get_option(self::OPTION_CERTIFICATE_PIN, '');
    }

    /**
     * Get economic activity code
     *
     * @return string Economic activity code
     */
    public static function get_economic_activity() {
        return get_option(self::OPTION_ECONOMIC_ACTIVITY, '');
    }

    /**
     * Get location codes
     *
     * @return array Array with province, canton, district, and neighborhood codes
     */
    public static function get_location_codes() {
        return [
            'province' => get_option(self::OPTION_PROVINCE_CODE, ''),
            'canton' => get_option(self::OPTION_CANTON_CODE, ''),
            'district' => get_option(self::OPTION_DISTRICT_CODE, ''),
            'neighborhood' => get_option(self::OPTION_NEIGHBORHOOD_CODE, ''),
        ];
    }

    /**
     * Get company address
     *
     * @return string Complete address
     */
    public static function get_address() {
        return get_option(self::OPTION_ADDRESS, '');
    }

    /**
     * Get company phone
     *
     * @return string Phone number
     */
    public static function get_phone() {
        return get_option(self::OPTION_PHONE, '');
    }

    /**
     * Get company email
     *
     * @return string Email address
     */
    public static function get_email() {
        return get_option(self::OPTION_EMAIL, '');
    }

    /**
     * Check if debug mode is enabled
     *
     * @return bool True if debug is enabled
     */
    public static function is_debug_enabled() {
        return get_option(self::OPTION_ENABLE_DEBUG, 'no') === 'yes';
    }

    /**
     * Check if processing is paused
     *
     * @return bool True if processing is paused
     */
    public static function is_processing_paused() {
        return get_option(self::OPTION_PAUSE_PROCESSING, 'no') === 'yes';
    }

    /**
     * Get CABYS API endpoint
     *
     * @return string CABYS API endpoint URL
     */
    public static function get_cabys_api_endpoint() {
        $endpoint = get_option(self::OPTION_CABYS_API_ENDPOINT, '');

        // Return custom endpoint if set, otherwise use default
        if (empty($endpoint)) {
            return 'https://api.hacienda.go.cr/fe/cabys';
        }

        return $endpoint;
    }

    /**
     * Validate configuration
     *
     * @return array Array of validation errors (empty if valid)
     */
    public static function validate_configuration() {
        $errors = [];
        $environment = self::get_environment();

        // Validate environment is set
        if (empty($environment)) {
            $errors[] = __('El ambiente de API no está configurado', 'fe-woo');
        }

        // Note: Company info, API credentials, certificate, etc. are now validated per-emisor
        // in is_ready_for_processing(). This method only validates general settings.

        return $errors;
    }

    /**
     * Check if configuration is complete
     *
     * @return bool True if all required fields are configured
     */
    public static function is_configured() {
        return empty(self::validate_configuration());
    }

    /**
     * Validate economic activity code format
     *
     * Validates that the code matches the EXACT format: 1234.5
     * - Exactly 4 digits
     * - One period (.)
     * - Exactly 1 digit
     *
     * @param string $code Economic activity code to validate
     * @return bool True if format is valid
     */
    public static function validate_activity_code_format($code) {
        // Check if empty
        if ($code === '' || $code === null) {
            return false;
        }

        // Strict format validation: exactly 4 digits, period, 1 digit (e.g., 1234.5)
        // Pattern: ^\d{4}\.\d{1}$
        if (!preg_match('/^\d{4}\.\d{1}$/', $code)) {
            return false;
        }

        return true;
    }

    /**
     * Check if system is ready to process electronic invoices
     *
     * This checks both configuration completeness and connection test status
     *
     * @return array Array with 'ready' boolean and 'message' explaining why not ready
     */
    public static function is_ready_for_processing() {
        // CRITICAL: Check if a parent emisor (default) exists
        // Without a parent emisor, no invoices can be generated
        if (class_exists('FE_Woo_Emisor_Manager')) {
            $parent_emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
            if (!$parent_emisor) {
                return [
                    'ready' => false,
                    'message' => __('No hay emisor por defecto configurado. Las facturas electrónicas NO se pueden procesar. Por favor configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                ];
            }

            // Also check if parent emisor is active
            if (!$parent_emisor->active) {
                return [
                    'ready' => false,
                    'message' => __('El emisor por defecto está inactivo. Por favor active el emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                ];
            }

            // Check if parent emisor has required fields
            if (empty($parent_emisor->certificate_path) || !file_exists($parent_emisor->certificate_path)) {
                return [
                    'ready' => false,
                    'message' => __('El emisor por defecto no tiene certificado cargado. Por favor cargue el certificado en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                ];
            }

            if (empty($parent_emisor->certificate_pin)) {
                return [
                    'ready' => false,
                    'message' => __('El emisor por defecto no tiene PIN de certificado configurado. Por favor configure el PIN en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                ];
            }

            if (empty($parent_emisor->api_username) || empty($parent_emisor->api_password)) {
                return [
                    'ready' => false,
                    'message' => __('El emisor por defecto no tiene credenciales de API configuradas. Por favor configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                ];
            }

            // Validate all active child emisors have required credentials
            $all_emisores = FE_Woo_Emisor_Manager::get_all_emisores(true);
            foreach ($all_emisores as $emisor) {
                if ($emisor->is_parent) {
                    continue; // Already validated above
                }

                $missing = [];
                if (empty($emisor->api_username) || empty($emisor->api_password)) {
                    $missing[] = __('credenciales de API', 'fe-woo');
                }
                if (empty($emisor->certificate_path) || !file_exists($emisor->certificate_path)) {
                    $missing[] = __('certificado', 'fe-woo');
                }
                if (empty($emisor->certificate_pin)) {
                    $missing[] = __('PIN de certificado', 'fe-woo');
                }

                if (!empty($missing)) {
                    return [
                        'ready' => false,
                        'message' => sprintf(
                            __('El emisor "%s" no tiene configurado: %s. Por favor complete la configuración en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                            $emisor->nombre_legal,
                            implode(', ', $missing)
                        ),
                    ];
                }
            }
        }

        // Check if basic configuration is complete (environment, URLs, etc.)
        if (!self::is_configured()) {
            return [
                'ready' => false,
                'message' => __('La configuración de Factura Electrónica está incompleta. Por favor configure todos los campos requeridos en WooCommerce > Ajustes > Configuración de FE.', 'fe-woo'),
            ];
        }

        // Check if connection test was successful
        $last_test_status = get_option('fe_woo_last_connection_test_status', 'never');
        $last_test_time = get_option('fe_woo_last_connection_test_time', 0);

        if ($last_test_status !== 'success') {
            return [
                'ready' => false,
                'message' => __('La prueba de conexión no se ha completado exitosamente. Por favor pruebe la conexión en WooCommerce > Ajustes > Configuración de FE para verificar su configuración.', 'fe-woo'),
            ];
        }

        // Optional: Check if test is too old (more than 7 days)
        $test_age_days = (time() - $last_test_time) / DAY_IN_SECONDS;
        if ($test_age_days > 7) {
            return [
                'ready' => false,
                'message' => sprintf(
                    __('La prueba de conexión está desactualizada (última prueba hace %d días). Por favor vuelva a probar la conexión en WooCommerce > Ajustes > Configuración de FE.', 'fe-woo'),
                    round($test_age_days)
                ),
            ];
        }

        return [
            'ready' => true,
            'message' => __('Sistema listo para procesar facturas electrónicas.', 'fe-woo'),
        ];
    }

    /**
     * Get all configuration as array
     *
     * @return array Complete configuration
     */
    public static function get_all_config() {
        return [
            'environment' => self::get_environment(),
            'cedula_juridica' => self::get_cedula_juridica(),
            'company_name' => self::get_company_name(),
            'api_username' => self::get_api_username(),
            'api_password' => self::get_api_password(),
            'certificate_path' => self::get_certificate_path(),
            'certificate_pin' => self::get_certificate_pin(),
            'economic_activity' => self::get_economic_activity(),
            'location' => self::get_location_codes(),
            'address' => self::get_address(),
            'phone' => self::get_phone(),
            'email' => self::get_email(),
            'debug_enabled' => self::is_debug_enabled(),
        ];
    }

    /**
     * Export configuration (for backup/migration)
     *
     * @return string JSON encoded configuration
     */
    public static function export_config() {
        $config = self::get_all_config();
        // Remove sensitive data from export
        unset($config['api_password']);
        unset($config['certificate_pin']);

        return wp_json_encode($config, JSON_PRETTY_PRINT);
    }

    /**
     * Reset all configuration
     *
     * @return bool True on success
     */
    public static function reset_config() {
        $options = [
            self::OPTION_ENVIRONMENT,
            self::OPTION_CEDULA_JURIDICA,
            self::OPTION_COMPANY_NAME,
            self::OPTION_API_USERNAME,
            self::OPTION_API_PASSWORD,
            self::OPTION_CERTIFICATE_PATH,
            self::OPTION_CERTIFICATE_PIN,
            self::OPTION_ECONOMIC_ACTIVITY,
            self::OPTION_PROVINCE_CODE,
            self::OPTION_CANTON_CODE,
            self::OPTION_DISTRICT_CODE,
            self::OPTION_NEIGHBORHOOD_CODE,
            self::OPTION_ADDRESS,
            self::OPTION_PHONE,
            self::OPTION_EMAIL,
            self::OPTION_ENABLE_DEBUG,
            self::OPTION_PAUSE_PROCESSING,
            self::OPTION_PRODUCTION_BASE_URL,
            self::OPTION_SANDBOX_BASE_URL,
            self::OPTION_CABYS_API_ENDPOINT,
        ];

        foreach ($options as $option) {
            delete_option($option);
        }

        return true;
    }
}
