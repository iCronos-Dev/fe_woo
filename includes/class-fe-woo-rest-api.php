<?php
/**
 * REST API Mock Endpoints for Local Testing
 *
 * Provides mock Hacienda API endpoints for local DDEV development
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_REST_API
 *
 * Mock REST API endpoints for local testing
 */
class FE_Woo_REST_API {

    /**
     * API namespace
     */
    const NAMESPACE = 'fe-woo/v1';

    /**
     * Initialize REST API
     */
    public static function init() {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    /**
     * Register REST API routes
     */
    public static function register_routes() {
        // Reception endpoint - POST
        register_rest_route(self::NAMESPACE, '/recepcion', [
            'methods' => 'POST',
            'callback' => [__CLASS__, 'handle_reception'],
            'permission_callback' => '__return_true',
        ]);

        // Consultation endpoint - GET
        register_rest_route(self::NAMESPACE, '/consulta/(?P<key>[a-zA-Z0-9]+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_consultation'],
            'permission_callback' => '__return_true',
        ]);

        // Status endpoint - GET
        register_rest_route(self::NAMESPACE, '/estado', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'handle_status'],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Handle invoice reception
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public static function handle_reception($request) {
        self::log_request('Reception', $request);

        $params = $request->get_json_params();

        // Validate required fields
        if (empty($params['clave']) || empty($params['comprobanteXml'])) {
            return new WP_REST_Response([
                'error' => 'Missing required fields',
                'message' => 'clave and comprobanteXml are required',
            ], 400);
        }

        // Mock successful response
        $response_data = [
            'clave' => $params['clave'],
            'fecha' => current_time('c'),
            'ind-estado' => 'recibido',
            'respuesta-xml' => base64_encode(self::generate_mock_xml_response($params['clave'])),
        ];

        // Store for later consultation
        self::store_invoice($params['clave'], $response_data);

        return new WP_REST_Response($response_data, 201);
    }

    /**
     * Handle invoice consultation
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public static function handle_consultation($request) {
        self::log_request('Consultation', $request);

        $invoice_key = $request->get_param('key');

        // Retrieve stored invoice
        $invoice = self::get_invoice($invoice_key);

        if (!$invoice) {
            return new WP_REST_Response([
                'error' => 'Invoice not found',
                'message' => 'No invoice found with key: ' . $invoice_key,
            ], 404);
        }

        // Update status to accepted (mock progression)
        $invoice['ind-estado'] = 'aceptado';

        return new WP_REST_Response($invoice, 200);
    }

    /**
     * Handle status check
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Response object
     */
    public static function handle_status($request) {
        self::log_request('Status', $request);

        return new WP_REST_Response([
            'status' => 'ok',
            'message' => 'Mock API is running on ' . get_site_url(),
            'environment' => 'local-development',
            'version' => FE_WOO_VERSION,
            'timestamp' => current_time('c'),
        ], 200);
    }

    /**
     * Generate mock XML response
     *
     * @param string $invoice_key Invoice key
     * @return string Mock XML response
     */
    private static function generate_mock_xml_response($invoice_key) {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>';
        $xml .= '<MensajeHacienda xmlns="https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.3/mensajeHacienda">';
        $xml .= '<Clave>' . esc_xml($invoice_key) . '</Clave>';
        $xml .= '<Fecha>' . current_time('c') . '</Fecha>';
        $xml .= '<Mensaje>Comprobante recibido satisfactoriamente (MOCK)</Mensaje>';
        $xml .= '<DetalleMensaje>Mock response from local development environment</DetalleMensaje>';
        $xml .= '</MensajeHacienda>';

        return $xml;
    }

    /**
     * Store invoice for later consultation
     *
     * @param string $invoice_key Invoice key
     * @param array  $data Invoice data
     */
    private static function store_invoice($invoice_key, $data) {
        $invoices = get_option('fe_woo_mock_invoices', []);
        $invoices[$invoice_key] = $data;
        update_option('fe_woo_mock_invoices', $invoices);
    }

    /**
     * Get stored invoice
     *
     * @param string $invoice_key Invoice key
     * @return array|null Invoice data or null if not found
     */
    private static function get_invoice($invoice_key) {
        $invoices = get_option('fe_woo_mock_invoices', []);
        return isset($invoices[$invoice_key]) ? $invoices[$invoice_key] : null;
    }

    /**
     * Log API request (for debugging)
     *
     * @param string          $endpoint Endpoint name
     * @param WP_REST_Request $request Request object
     */
    private static function log_request($endpoint, $request) {
        if (!FE_Woo_Hacienda_Config::is_debug_enabled()) {
            return;
        }

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-mock-api'];

            $log_data = [
                'endpoint' => $endpoint,
                'method' => $request->get_method(),
                'params' => $request->get_params(),
                'headers' => $request->get_headers(),
            ];

            $logger->debug(
                'Mock API Request: ' . wp_json_encode($log_data, JSON_PRETTY_PRINT),
                $context
            );
        }
    }

    /**
     * Clear all stored mock invoices
     */
    public static function clear_mock_invoices() {
        delete_option('fe_woo_mock_invoices');
    }

    /**
     * Get all stored mock invoices
     *
     * @return array All stored invoices
     */
    public static function get_all_mock_invoices() {
        return get_option('fe_woo_mock_invoices', []);
    }
}
