<?php
/**
 * Hacienda API Client Class
 *
 * Handles all API communications with Costa Rica's Hacienda electronic invoicing system
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_API_Client
 *
 * Manages API requests to Hacienda for electronic invoice operations
 */
class FE_Woo_API_Client {

    /**
     * Hacienda configuration instance
     *
     * @var FE_Woo_Hacienda_Config
     */
    private $config;

    /**
     * Request timeout in seconds
     *
     * @var int
     */
    private $timeout = 30;

    /**
     * OAuth access token
     *
     * @var string|null
     */
    private $access_token = null;

    /**
     * OAuth refresh token
     *
     * @var string|null
     */
    private $refresh_token = null;

    /**
     * Token expiration timestamp
     *
     * @var int|null
     */
    private $token_expires_at = null;

    /**
     * Constructor
     */
    public function __construct() {
        $this->config = new FE_Woo_Hacienda_Config();
        $this->load_tokens();
    }

    /**
     * Test API connection
     *
     * @return array Result with 'success' boolean and 'message'
     */
    public function test_connection() {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        // Skip validation for local environment
        if ($environment !== FE_Woo_Hacienda_Config::ENV_LOCAL) {
            // Validate configuration
            $validation_errors = FE_Woo_Hacienda_Config::validate_configuration();
            if (!empty($validation_errors)) {
                return [
                    'success' => false,
                    'message' => __('Configuration incomplete', 'fe-woo'),
                    'errors' => $validation_errors,
                ];
            }

            // Verify certificate
            $cert_path = FE_Woo_Hacienda_Config::get_certificate_path();
            $cert_pin = FE_Woo_Hacienda_Config::get_certificate_pin();

            $cert_verification = FE_Woo_Certificate_Handler::verify_certificate($cert_path, $cert_pin);
            if (!$cert_verification['valid']) {
                return [
                    'success' => false,
                    'message' => __('Certificate validation failed', 'fe-woo'),
                    'error' => $cert_verification['error'],
                ];
            }
        }

        // Test OAuth token acquisition
        $token_result = $this->ensure_valid_token();

        if (!$token_result['success']) {
            return [
                'success' => false,
                'message' => __('Failed to authenticate with Hacienda IdP', 'fe-woo'),
                'error' => $token_result['message'] ?? __('Unknown authentication error', 'fe-woo'),
            ];
        }

        // If we got here, we successfully obtained an OAuth token
        // That means authentication is working correctly
        return [
            'success' => true,
            'message' => __('Connection and authentication successful! OAuth token obtained from Hacienda IdP.', 'fe-woo'),
        ];
    }

    /**
     * Test connection with specific emisor credentials
     *
     * @param object $emisor Emisor object with api_username, api_password, etc.
     * @return array Result with 'success' boolean and 'message'
     */
    public function test_connection_with_emisor($emisor) {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        // Test OAuth token acquisition using emisor credentials
        $token_result = $this->obtain_access_token_with_emisor($emisor);

        if (!$token_result['success']) {
            return [
                'success' => false,
                'message' => __('Failed to authenticate with Hacienda IdP', 'fe-woo'),
                'error' => $token_result['message'] ?? __('Unknown authentication error', 'fe-woo'),
            ];
        }

        // If we got here, we successfully obtained an OAuth token
        return [
            'success' => true,
            'message' => sprintf(
                __('Conexión exitosa para emisor: %s. Token OAuth obtenido correctamente.', 'fe-woo'),
                $emisor->nombre_legal
            ),
        ];
    }

    /**
     * Obtain OAuth access token using emisor credentials
     *
     * @param object $emisor Emisor object with api_username, api_password
     * @return array Result with 'success' boolean and 'message'
     */
    private function obtain_access_token_with_emisor($emisor) {
        // Get IdP token endpoint
        $token_url = $this->get_token_endpoint_url();

        if (empty($token_url)) {
            return [
                'success' => false,
                'message' => __('Token endpoint not configured', 'fe-woo'),
            ];
        }

        // Get credentials from emisor
        $username = $emisor->api_username;
        $password = $emisor->api_password;

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => __('API credentials not configured for this emisor', 'fe-woo'),
            ];
        }

        // Get client_id based on environment
        $client_id = $this->get_client_id();

        // Prepare form data
        $body = [
            'grant_type' => 'password',
            'client_id' => $client_id,
            'username' => $username,
            'password' => $password,
        ];

        // Log request if debug is enabled
        if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
            $this->log('Obtaining OAuth token for emisor: ' . $emisor->nombre_legal);
            $this->log('Token endpoint: ' . $token_url);
        }

        // Make request
        $response = wp_remote_post($token_url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => http_build_query($body),
            'sslverify' => FE_Woo_Hacienda_Config::get_environment() === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => $response->get_error_message(),
            ];
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($response_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = isset($error_data['error_description'])
                ? $error_data['error_description']
                : (isset($error_data['error']) ? $error_data['error'] : __('Authentication failed', 'fe-woo'));

            return [
                'success' => false,
                'message' => $error_message,
                'code' => $response_code,
            ];
        }

        // Parse token response
        $token_data = json_decode($response_body, true);

        if (empty($token_data['access_token'])) {
            return [
                'success' => false,
                'message' => __('Invalid token response', 'fe-woo'),
            ];
        }

        // Store tokens (temporarily for this test)
        $this->access_token = $token_data['access_token'];
        $this->refresh_token = isset($token_data['refresh_token']) ? $token_data['refresh_token'] : null;
        $this->token_expires = time() + (isset($token_data['expires_in']) ? (int) $token_data['expires_in'] : 3600);

        return ['success' => true];
    }

    /**
     * Send electronic invoice to Hacienda
     *
     * @param string $xml_data XML invoice data
     * @return array Result with 'success' boolean and response data
     */
    public function send_invoice($xml_data) {
        $endpoint = $this->config->get_api_endpoint('reception');

        if (empty($endpoint)) {
            return [
                'success' => false,
                'message' => __('Reception endpoint not configured', 'fe-woo'),
            ];
        }

        $endpoint .= '/recepcion';

        // Extract clave from XML (must match the clave in JSON body)
        $clave = $this->extract_clave_from_xml($xml_data);
        if (empty($clave)) {
            return [
                'success' => false,
                'message' => __('Failed to extract clave from XML', 'fe-woo'),
            ];
        }

        // Prepare request body
        $body = [
            'clave' => $clave,
            'fecha' => current_time('c'),
            'emisor' => [
                'tipoIdentificacion' => '02', // Cédula Jurídica
                'numeroIdentificacion' => FE_Woo_Hacienda_Config::get_cedula_juridica(),
            ],
            'comprobanteXml' => base64_encode($xml_data),
        ];

        $response = $this->make_request('POST', $endpoint, $body);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to send invoice', 'fe-woo'),
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Accept 200 (OK), 201 (Created), and 202 (Accepted) as success
        if ($status_code === 200 || $status_code === 201 || $status_code === 202) {
            return [
                'success' => true,
                'message' => __('Invoice sent successfully', 'fe-woo'),
                'data' => $response_data,
            ];
        }

        return [
            'success' => false,
            'message' => __('Failed to send invoice', 'fe-woo'),
            'status_code' => $status_code,
            'data' => $response_data,
        ];
    }

    /**
     * Send electronic invoice to Hacienda using specific emisor credentials
     *
     * This method authenticates with the emisor's specific credentials before sending.
     * If authentication fails, the invoice is NOT sent and an error is returned.
     *
     * @param string $xml_data XML invoice data
     * @param object $emisor   Emisor object with api_username, api_password, cedula_juridica
     * @return array Result with 'success' boolean and response data
     */
    public function send_invoice_with_emisor($xml_data, $emisor) {
        // First, validate emisor has required credentials
        if (empty($emisor->api_username) || empty($emisor->api_password)) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                    $emisor->nombre_legal
                ),
                'error_type' => 'missing_credentials',
            ];
        }

        // Authenticate with emisor's credentials
        $token_result = $this->obtain_access_token_with_emisor($emisor);

        if (!$token_result['success']) {
            $error_message = isset($token_result['message']) ? $token_result['message'] : __('Error de autenticación desconocido', 'fe-woo');
            return [
                'success' => false,
                'message' => sprintf(
                    __('Error de conexión con Hacienda para el emisor "%s": %s', 'fe-woo'),
                    $emisor->nombre_legal,
                    $error_message
                ),
                'error_type' => 'authentication_failed',
                'error_detail' => $error_message,
            ];
        }

        // Now proceed to send the invoice with the obtained token
        $endpoint = $this->config->get_api_endpoint('reception');

        if (empty($endpoint)) {
            return [
                'success' => false,
                'message' => __('Reception endpoint not configured', 'fe-woo'),
            ];
        }

        $endpoint .= '/recepcion';

        // Extract clave from XML
        $clave = $this->extract_clave_from_xml($xml_data);
        if (empty($clave)) {
            return [
                'success' => false,
                'message' => __('Failed to extract clave from XML', 'fe-woo'),
            ];
        }

        // Prepare request body using emisor's cedula
        $body = [
            'clave' => $clave,
            'fecha' => current_time('c'),
            'emisor' => [
                'tipoIdentificacion' => $emisor->tipo_identificacion ?? '02',
                'numeroIdentificacion' => $emisor->cedula_juridica,
            ],
            'comprobanteXml' => base64_encode($xml_data),
        ];

        // Make request with emisor's token (already set by obtain_access_token_with_emisor)
        $response = $this->make_request_with_token('POST', $endpoint, $body);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to send invoice', 'fe-woo'),
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        // Accept 200 (OK), 201 (Created), and 202 (Accepted) as success
        if ($status_code === 200 || $status_code === 201 || $status_code === 202) {
            return [
                'success' => true,
                'message' => __('Invoice sent successfully', 'fe-woo'),
                'data' => $response_data,
            ];
        }

        return [
            'success' => false,
            'message' => __('Failed to send invoice', 'fe-woo'),
            'status_code' => $status_code,
            'data' => $response_data,
        ];
    }

    /**
     * Make authenticated request using the currently stored token
     *
     * This is similar to make_request but doesn't call ensure_valid_token(),
     * assuming the token was already obtained via obtain_access_token_with_emisor().
     *
     * @param string $method HTTP method
     * @param string $endpoint Full endpoint URL
     * @param array  $body Request body (for POST/PUT)
     * @return array|WP_Error Response or WP_Error on failure
     */
    private function make_request_with_token($method, $endpoint, $body = null) {
        // Verify we have a token
        if (empty($this->access_token)) {
            return new WP_Error('no_token', __('No access token available', 'fe-woo'));
        }

        // Prepare headers with Bearer token
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'bearer ' . $this->access_token,
        ];

        // Prepare arguments
        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => $headers,
            'sslverify' => FE_Woo_Hacienda_Config::get_environment() === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ];

        // Add body for POST/PUT requests
        if ($body !== null && in_array($method, ['POST', 'PUT'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        // Log request if debug is enabled
        if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
            $this->log_request($method, $endpoint, $args);
        }

        // Make request
        $response = wp_remote_request($endpoint, $args);

        // Log response if debug is enabled
        if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
            $this->log_response($response);
        }

        return $response;
    }

    /**
     * Resolve the emisor whose OAuth credentials should be used to query a
     * given clave. v1.29.4: necesario porque a partir de multi-emisor cada
     * factura puede haber sido enviada con credenciales distintas, y la
     * config global puede estar vacía. Estrategia:
     *   1. Extraer la cédula del clave (posiciones 9..20, padded a 12).
     *   2. Buscar emisor activo por cédula (matching agnóstico de padding).
     *   3. Fallback al parent emisor.
     *
     * @param string $clave 50-digit Hacienda clave.
     * @return object|null Emisor object o null si no se puede resolver.
     */
    public static function resolve_emisor_for_clave($clave) {
        $clave = (string) $clave;
        if (strlen($clave) >= 21) {
            $cedula = substr($clave, 9, 12);
            $emisor = FE_Woo_Emisor_Manager::get_emisor_by_cedula($cedula);
            if ($emisor) {
                return $emisor;
            }
        }
        return FE_Woo_Emisor_Manager::get_parent_emisor();
    }

    /**
     * Query invoice status from Hacienda using an emisor's specific OAuth
     * credentials. Análogo simétrico de `send_invoice_with_emisor()`. Antes
     * de v1.29.4, todas las consultas GET pasaban por `obtain_access_token()`
     * que lee la config global. En sitios multi-emisor donde la config global
     * está vacía y las credenciales viven en la fila del emisor padre, el
     * GET fallaba con "API credentials not configured" — incluyendo el botón
     * admin "volver a consultar a Hacienda" y el polling cron del acuse.
     *
     * @param string $invoice_key 50-digit clave
     * @param object $emisor      Emisor object con api_username/api_password
     * @return array Result with invoice status
     */
    public function query_invoice_status_with_emisor($invoice_key, $emisor) {
        if (empty($emisor) || empty($emisor->api_username) || empty($emisor->api_password)) {
            return [
                'success' => false,
                'message' => __('El emisor no tiene credenciales de API configuradas.', 'fe-woo'),
                'error'   => 'missing_credentials',
            ];
        }

        $token_result = $this->obtain_access_token_with_emisor($emisor);
        if (empty($token_result['success'])) {
            $error = isset($token_result['message']) ? $token_result['message'] : __('Authentication failed', 'fe-woo');
            return [
                'success' => false,
                'message' => __('Failed to query invoice status', 'fe-woo'),
                'error'   => $error,
            ];
        }

        $endpoint = $this->config->get_api_endpoint('reception');
        if (empty($endpoint)) {
            return [
                'success' => false,
                'message' => __('Reception endpoint not configured', 'fe-woo'),
            ];
        }
        $endpoint = rtrim($endpoint, '/') . '/recepcion/' . $invoice_key;

        $response = $this->make_request_with_token('GET', $endpoint);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to query invoice status', 'fe-woo'),
                'error'   => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($status_code === 200) {
            return [
                'success' => true,
                'status'  => isset($response_data['ind-estado']) ? $response_data['ind-estado'] : 'unknown',
                'data'    => $response_data,
            ];
        }

        $result = [
            'success'     => false,
            'message'     => __('Failed to query invoice status', 'fe-woo'),
            'status_code' => $status_code,
            'data'        => $response_data,
        ];

        if ($status_code === 404) {
            $result['not_found'] = true;
            $result['message']   = __('Clave no encontrada en Hacienda (no fue recibida).', 'fe-woo');
        }

        return $result;
    }

    /**
     * Query invoice status from Hacienda (v4.4).
     *
     * Uses GET {base}/recepcion/v1/recepcion/{clave} with the same OAuth
     * bearer as the POST reception endpoint. When Hacienda has finished
     * processing, the JSON body includes `ind-estado` (aceptado/rechazado)
     * and `respuesta-xml`, a base64-encoded signed MensajeHacienda — the
     * AHC-{clave}.xml file. Before processing completes, the same endpoint
     * returns only `ind-estado: recibido` and no XML, so callers must poll.
     *
     * v1.29.4: prefiere `query_invoice_status_with_emisor()` resolviendo el
     * emisor a partir del clave. Si la resolución falla, cae al flujo legacy
     * que usa la config global (preserva el comportamiento previo en sitios
     * single-emisor con global config presente).
     *
     * @param string $invoice_key 50-digit clave
     * @return array Result with invoice status
     */
    public function query_invoice_status($invoice_key) {
        $emisor = self::resolve_emisor_for_clave($invoice_key);
        if ($emisor && !empty($emisor->api_username) && !empty($emisor->api_password)) {
            return $this->query_invoice_status_with_emisor($invoice_key, $emisor);
        }

        // Consultation lives at GET {base}/recepcion/v1/recepcion/{clave} on
        // the same OAuth-protected host as the POST reception endpoint. The
        // separate /consulta/v1 host sits behind AWS API Gateway with IAM
        // auth (SigV4) — we don't use it.
        $endpoint = $this->config->get_api_endpoint('reception');

        if (empty($endpoint)) {
            return [
                'success' => false,
                'message' => __('Reception endpoint not configured', 'fe-woo'),
            ];
        }

        // Path is {base}/recepcion/v1/recepcion/{clave}. get_api_endpoint
        // already returns up to `/recepcion/v1`; we append `/recepcion/{clave}`.
        $endpoint = rtrim($endpoint, '/') . '/recepcion/' . $invoice_key;

        $response = $this->make_request('GET', $endpoint);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => __('Failed to query invoice status', 'fe-woo'),
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_data = json_decode($response_body, true);

        if ($status_code === 200) {
            return [
                'success' => true,
                'status' => isset($response_data['ind-estado']) ? $response_data['ind-estado'] : 'unknown',
                'data' => $response_data,
            ];
        }

        $result = [
            'success' => false,
            'message' => __('Failed to query invoice status', 'fe-woo'),
            'status_code' => $status_code,
            'data' => $response_data,
        ];

        // 404 = clave no recibida por Hacienda. Diferenciado del resto de
        // errores para que callers (ej. retry de cola) puedan distinguir
        // "no llegó nunca → safe re-POST" de "Hacienda devolvió error".
        if ($status_code === 404) {
            $result['not_found'] = true;
            $result['message'] = __('Clave no encontrada en Hacienda (no fue recibida).', 'fe-woo');
        }

        return $result;
    }

    /**
     * Make HTTP request to Hacienda API
     *
     * @param string $method HTTP method (GET, POST, etc.)
     * @param string $endpoint Full endpoint URL
     * @param array  $body Request body (for POST/PUT)
     * @return array|WP_Error Response or WP_Error on failure
     */
    private function make_request($method, $endpoint, $body = null) {
        // Ensure we have a valid access token
        $token_result = $this->ensure_valid_token();
        if (!$token_result['success']) {
            return new WP_Error('auth_failed', $token_result['message']);
        }

        // Prepare headers with Bearer token
        $headers = [
            'Content-Type' => 'application/json',
            'Authorization' => 'bearer ' . $this->access_token,
        ];

        // Prepare arguments
        $args = [
            'method' => $method,
            'timeout' => $this->timeout,
            'headers' => $headers,
            'sslverify' => FE_Woo_Hacienda_Config::get_environment() === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ];

        // Add certificate if available
        $cert_path = FE_Woo_Hacienda_Config::get_certificate_path();
        if (!empty($cert_path) && file_exists($cert_path)) {
            $args['sslcertificate'] = $cert_path;
        }

        // Add body for POST/PUT requests
        if ($body !== null && in_array($method, ['POST', 'PUT'], true)) {
            $args['body'] = wp_json_encode($body);
        }

        // Log request if debug is enabled
        if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
            $this->log_request($method, $endpoint, $args);
        }

        // Make request
        $response = wp_remote_request($endpoint, $args);

        // Log response if debug is enabled
        if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
            $this->log_response($response);
        }

        return $response;
    }

    /**
     * Extract clave from XML document
     *
     * @param string $xml_data XML invoice data
     * @return string|null Clave extracted from XML, or null if not found
     */
    private function extract_clave_from_xml($xml_data) {
        // Use DOMDocument to parse XML and extract Clave element
        $dom = new DOMDocument();

        // Suppress warnings for malformed XML
        $prev_use_errors = libxml_use_internal_errors(true);

        if (!$dom->loadXML($xml_data)) {
            libxml_use_internal_errors($prev_use_errors);
            return null;
        }

        libxml_use_internal_errors($prev_use_errors);

        // Get Clave element (works for both FacturaElectronica and TiqueteElectronico)
        $clave_elements = $dom->getElementsByTagName('Clave');

        if ($clave_elements->length > 0) {
            return $clave_elements->item(0)->nodeValue;
        }

        return null;
    }

    /**
     * Generate unique invoice key
     *
     * Format: [Country][Day][Month][Year][ID][Consecutive][Situation][Security Code]
     * Total: 3 + 2 + 2 + 2 + 12 + 20 + 1 + 8 = 50 characters
     *
     * @return string 50-character invoice key
     */
    private function generate_invoice_key() {
        $country = '506'; // Costa Rica (3 chars)
        $date = current_time('dmy'); // DDMMYY (6 chars) - 2-digit year required by Hacienda
        $id = str_pad(FE_Woo_Hacienda_Config::get_cedula_juridica(), 12, '0', STR_PAD_LEFT); // 12 chars
        $consecutive = str_pad($this->get_next_consecutive(), 20, '0', STR_PAD_LEFT); // 20 chars
        $situation = '1'; // Normal (1 char)
        $security_code = str_pad(wp_rand(1, 99999999), 8, '0', STR_PAD_LEFT); // 8 chars

        return $country . $date . $id . $consecutive . $situation . $security_code;
    }

    /**
     * Get next consecutive number for invoices
     *
     * @return int Next consecutive number
     */
    private function get_next_consecutive() {
        $consecutive = get_option('fe_woo_invoice_consecutive', 0);
        $consecutive++;
        update_option('fe_woo_invoice_consecutive', $consecutive);
        return $consecutive;
    }

    /**
     * Log API request
     *
     * @param string $method HTTP method
     * @param string $endpoint Endpoint URL
     * @param array  $args Request arguments
     */
    private function log_request($method, $endpoint, $args) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-api'];

            $log_data = [
                'method' => $method,
                'endpoint' => $endpoint,
                'headers' => isset($args['headers']) ? $args['headers'] : [],
                'body' => isset($args['body']) ? $args['body'] : null,
            ];

            // Parse body if it's JSON
            if (isset($log_data['body']) && is_string($log_data['body'])) {
                $decoded_body = json_decode($log_data['body'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $log_data['body_decoded'] = $decoded_body;
                }
            }

            // Show partial auth token for debugging (last 20 chars)
            if (isset($log_data['headers']['Authorization'])) {
                $auth_header = $log_data['headers']['Authorization'];
                if (strlen($auth_header) > 30) {
                    $log_data['headers']['Authorization'] = '[REDACTED]...' . substr($auth_header, -20);
                } else {
                    $log_data['headers']['Authorization'] = '[REDACTED]';
                }
            }

            $logger->debug(
                'API Request: ' . wp_json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                $context
            );
        }
    }

    /**
     * Log API response
     *
     * @param array|WP_Error $response API response
     */
    private function log_response($response) {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-api'];

            if (is_wp_error($response)) {
                $error_data = [
                    'error_code' => $response->get_error_code(),
                    'error_message' => $response->get_error_message(),
                    'error_data' => $response->get_error_data(),
                ];
                $logger->error(
                    'API Error: ' . wp_json_encode($error_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    $context
                );
            } else {
                $status_code = wp_remote_retrieve_response_code($response);
                $response_body = wp_remote_retrieve_body($response);
                $response_headers = wp_remote_retrieve_headers($response);

                $log_data = [
                    'status_code' => $status_code,
                    'status_text' => wp_remote_retrieve_response_message($response),
                    'headers' => $response_headers->getAll(),
                    'body' => $response_body,
                ];

                // Parse body if it's JSON
                $decoded_body = json_decode($response_body, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $log_data['body_decoded'] = $decoded_body;
                }

                // Determine log level based on status code
                if ($status_code >= 200 && $status_code < 300) {
                    $logger->debug(
                        'API Response (Success): ' . wp_json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        $context
                    );
                } elseif ($status_code >= 400 && $status_code < 500) {
                    $logger->warning(
                        'API Response (Client Error): ' . wp_json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        $context
                    );
                } elseif ($status_code >= 500) {
                    $logger->error(
                        'API Response (Server Error): ' . wp_json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        $context
                    );
                } else {
                    $logger->debug(
                        'API Response: ' . wp_json_encode($log_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                        $context
                    );
                }
            }
        }
    }

    /**
     * Set request timeout
     *
     * @param int $timeout Timeout in seconds
     */
    public function set_timeout($timeout) {
        $this->timeout = absint($timeout);
    }

    /**
     * Get request timeout
     *
     * @return int Timeout in seconds
     */
    public function get_timeout() {
        return $this->timeout;
    }

    /**
     * Load saved OAuth tokens from database
     */
    private function load_tokens() {
        $token_data = get_transient('fe_woo_oauth_tokens');

        if ($token_data && is_array($token_data)) {
            $this->access_token = $token_data['access_token'] ?? null;
            $this->refresh_token = $token_data['refresh_token'] ?? null;
            $this->token_expires_at = $token_data['expires_at'] ?? null;
        }
    }

    /**
     * Save OAuth tokens to database
     */
    private function save_tokens() {
        $token_data = [
            'access_token' => $this->access_token,
            'refresh_token' => $this->refresh_token,
            'expires_at' => $this->token_expires_at,
        ];

        // Store tokens with expiration (10 hours as per guide)
        set_transient('fe_woo_oauth_tokens', $token_data, 10 * HOUR_IN_SECONDS);
    }

    /**
     * Clear saved OAuth tokens
     */
    private function clear_tokens() {
        $this->access_token = null;
        $this->refresh_token = null;
        $this->token_expires_at = null;
        delete_transient('fe_woo_oauth_tokens');
    }

    /**
     * Check if access token is expired or about to expire
     *
     * @return bool True if token is expired or will expire in next 30 seconds
     */
    private function is_token_expired() {
        if (empty($this->access_token) || empty($this->token_expires_at)) {
            return true;
        }

        // Consider expired if within 30 seconds of expiration
        return time() >= ($this->token_expires_at - 30);
    }

    /**
     * Ensure we have a valid OAuth access token
     *
     * @return array Result with 'success' boolean and 'message'
     */
    private function ensure_valid_token() {
        // If token is still valid, return success
        if (!$this->is_token_expired()) {
            return ['success' => true];
        }

        // Try to refresh token if we have one
        if (!empty($this->refresh_token)) {
            $result = $this->refresh_access_token();
            if ($result['success']) {
                return ['success' => true];
            }
        }

        // If refresh failed or no refresh token, obtain new token
        return $this->obtain_access_token();
    }

    /**
     * Obtain OAuth access token from IdP
     *
     * @return array Result with 'success' boolean and 'message'
     */
    private function obtain_access_token() {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        // Get IdP token endpoint
        $token_url = $this->get_token_endpoint_url();

        if (empty($token_url)) {
            return [
                'success' => false,
                'message' => __('Token endpoint not configured', 'fe-woo'),
            ];
        }

        // Get credentials
        $username = FE_Woo_Hacienda_Config::get_api_username();
        $password = FE_Woo_Hacienda_Config::get_api_password();

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => __('API credentials not configured', 'fe-woo'),
            ];
        }

        // Get client_id based on environment
        $client_id = $this->get_client_id();

        // Prepare form data (application/x-www-form-urlencoded)
        $body = [
            'grant_type' => 'password',
            'client_id' => $client_id,
            'username' => $username,
            'password' => $password,
        ];

        // Make request to IdP
        $response = wp_remote_post($token_url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            ],
            'body' => $body,
            'sslverify' => $environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ]);

        if (is_wp_error($response)) {
            $this->log('OAuth token request failed: ' . $response->get_error_message(), 'error');
            return [
                'success' => false,
                'message' => __('Failed to obtain access token', 'fe-woo'),
                'error' => $response->get_error_message(),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code !== 200 || empty($data['access_token'])) {
            $error_msg = isset($data['error_description']) ? $data['error_description'] : __('Unknown error', 'fe-woo');
            $this->log('OAuth token request failed with status ' . $status_code . ': ' . $error_msg, 'error');
            return [
                'success' => false,
                'message' => $error_msg,
                'status_code' => $status_code,
            ];
        }

        // Store tokens
        $this->access_token = $data['access_token'];
        $this->refresh_token = $data['refresh_token'] ?? null;
        $this->token_expires_at = time() + ($data['expires_in'] ?? 300);

        $this->save_tokens();

        $this->log('OAuth access token obtained successfully', 'debug');

        return ['success' => true];
    }

    /**
     * Refresh OAuth access token using refresh token
     *
     * @return array Result with 'success' boolean and 'message'
     */
    private function refresh_access_token() {
        if (empty($this->refresh_token)) {
            return [
                'success' => false,
                'message' => __('No refresh token available', 'fe-woo'),
            ];
        }

        $environment = FE_Woo_Hacienda_Config::get_environment();
        $token_url = $this->get_token_endpoint_url();

        if (empty($token_url)) {
            return [
                'success' => false,
                'message' => __('Token endpoint not configured', 'fe-woo'),
            ];
        }

        $client_id = $this->get_client_id();

        // Prepare form data
        $body = [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'refresh_token' => $this->refresh_token,
        ];

        // Make request to IdP
        $response = wp_remote_post($token_url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            ],
            'body' => $body,
            'sslverify' => $environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ]);

        if (is_wp_error($response)) {
            $this->log('OAuth token refresh failed: ' . $response->get_error_message(), 'error');
            return [
                'success' => false,
                'message' => __('Failed to refresh access token', 'fe-woo'),
            ];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $data = json_decode($response_body, true);

        if ($status_code !== 200 || empty($data['access_token'])) {
            $this->log('OAuth token refresh failed with status ' . $status_code, 'error');
            // Clear invalid tokens
            $this->clear_tokens();
            return [
                'success' => false,
                'message' => __('Failed to refresh access token', 'fe-woo'),
            ];
        }

        // Store new tokens
        $this->access_token = $data['access_token'];
        $this->refresh_token = $data['refresh_token'] ?? $this->refresh_token;
        $this->token_expires_at = time() + ($data['expires_in'] ?? 300);

        $this->save_tokens();

        $this->log('OAuth access token refreshed successfully', 'debug');

        return ['success' => true];
    }

    /**
     * Logout and invalidate current OAuth session
     *
     * @return array Result with 'success' boolean
     */
    public function logout() {
        if (empty($this->refresh_token)) {
            $this->clear_tokens();
            return ['success' => true];
        }

        $environment = FE_Woo_Hacienda_Config::get_environment();
        $logout_url = $this->get_logout_endpoint_url();

        if (empty($logout_url)) {
            $this->clear_tokens();
            return ['success' => true];
        }

        $client_id = $this->get_client_id();

        // Prepare form data
        $body = [
            'client_id' => $client_id,
            'refresh_token' => $this->refresh_token,
        ];

        // Make request to IdP
        $response = wp_remote_post($logout_url, [
            'timeout' => $this->timeout,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded; charset=utf-8',
            ],
            'body' => $body,
            'sslverify' => $environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION,
        ]);

        // Clear tokens regardless of response
        $this->clear_tokens();

        if (is_wp_error($response)) {
            $this->log('OAuth logout request failed: ' . $response->get_error_message(), 'error');
        } else {
            $this->log('OAuth session logged out successfully', 'debug');
        }

        return ['success' => true];
    }

    /**
     * Get IdP token endpoint URL based on environment
     *
     * @return string Token endpoint URL
     */
    private function get_token_endpoint_url() {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        if ($environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION) {
            return 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/token';
        } elseif ($environment === FE_Woo_Hacienda_Config::ENV_SANDBOX) {
            return 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/token';
        }

        return ''; // No IdP for local environment
    }

    /**
     * Get IdP logout endpoint URL based on environment
     *
     * @return string Logout endpoint URL
     */
    private function get_logout_endpoint_url() {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        if ($environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION) {
            return 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut/protocol/openid-connect/logout';
        } elseif ($environment === FE_Woo_Hacienda_Config::ENV_SANDBOX) {
            return 'https://idp.comprobanteselectronicos.go.cr/auth/realms/rut-stag/protocol/openid-connect/logout';
        }

        return ''; // No IdP for local environment
    }

    /**
     * Get OAuth client_id based on environment
     *
     * @return string Client ID
     */
    private function get_client_id() {
        $environment = FE_Woo_Hacienda_Config::get_environment();

        if ($environment === FE_Woo_Hacienda_Config::ENV_PRODUCTION) {
            return 'api-prod';
        } else {
            return 'api-stag';
        }
    }

    /**
     * Log message with context
     *
     * @param string $message Message to log
     * @param string $level Log level (debug, info, error)
     */
    private function log($message, $level = 'info') {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-api'];

            switch ($level) {
                case 'error':
                    $logger->error($message, $context);
                    break;
                case 'debug':
                    if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
                        $logger->debug($message, $context);
                    }
                    break;
                default:
                    $logger->info($message, $context);
                    break;
            }
        }
    }
}
