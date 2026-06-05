<?php
/**
 * Certificate Handler Class
 *
 * Manages cryptographic certificate operations for Hacienda API
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Certificate_Handler
 *
 * Handles certificate upload, validation, and storage for Hacienda integration
 */
class FE_Woo_Certificate_Handler {

    /**
     * Upload directory for certificates
     *
     * @var string
     */
    private static $upload_dir = 'fe-woo/certificates';

    /**
     * Allowed certificate file extensions
     *
     * @var array
     */
    private static $allowed_extensions = ['p12', 'pfx'];

    /**
     * Initialize certificate handler
     */
    public static function init() {
        // Create upload directory if it doesn't exist
        self::create_upload_directory();

        // Add custom upload directory filter
        add_filter('upload_dir', [__CLASS__, 'custom_upload_dir']);
    }

    /**
     * Create secure upload directory for certificates
     *
     * @return bool True on success, false on failure
     */
    private static function create_upload_directory() {
        $upload_dir = self::get_upload_path();

        // Directory already exists and is writable
        if (file_exists($upload_dir) && is_writable($upload_dir)) {
            return true;
        }

        // Try to create directory
        if (!file_exists($upload_dir)) {
            if (!wp_mkdir_p($upload_dir)) {
                // Log error for debugging
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->error('Failed to create certificate directory', [
                        'source' => 'fe-woo-certificate',
                        'path' => $upload_dir,
                        'wp_upload_dir' => wp_upload_dir()['basedir'],
                    ]);
                }
                return false;
            }

            // Create .htaccess to prevent direct access
            $htaccess_content = "Order deny,allow\nDeny from all";
            @file_put_contents($upload_dir . '/.htaccess', $htaccess_content);

            // Create index.php to prevent directory listing
            @file_put_contents($upload_dir . '/index.php', '<?php // Silence is golden');
        }

        // Check if directory is writable
        return is_writable($upload_dir);
    }

    /**
     * Get full path to certificate upload directory
     *
     * @return string Upload directory path
     */
    public static function get_upload_path() {
        $wp_upload_dir = wp_upload_dir();
        return $wp_upload_dir['basedir'] . '/' . self::$upload_dir;
    }

    /**
     * Get URL to certificate upload directory
     *
     * @return string Upload directory URL
     */
    public static function get_upload_url() {
        $wp_upload_dir = wp_upload_dir();
        return $wp_upload_dir['baseurl'] . '/' . self::$upload_dir;
    }

    /**
     * Custom upload directory filter
     *
     * @param array $param Upload directory parameters
     * @return array Modified parameters
     */
    public static function custom_upload_dir($param) {
        // Only modify for our certificate uploads
        if (isset($_POST['fe_woo_certificate_upload'])) {
            $param['path'] = self::get_upload_path();
            $param['url'] = self::get_upload_url();
            $param['subdir'] = '';
        }
        return $param;
    }

    /**
     * Handle certificate file upload
     *
     * @param array $file $_FILES array element
     * @return array Result with 'success' boolean and 'message' or 'file_path'
     */
    public static function upload_certificate($file) {
        // Validate file
        $validation = self::validate_certificate_file($file);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'message' => $validation['error'],
            ];
        }

        // Create upload directory
        if (!self::create_upload_directory()) {
            return [
                'success' => false,
                'message' => __('Error al crear el directorio de carga', 'fe-woo'),
            ];
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'cert_' . wp_generate_password(12, false) . '.' . $extension;
        $upload_path = self::get_upload_path() . '/' . $filename;

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $upload_path)) {
            return [
                'success' => false,
                'message' => __('Error al mover el archivo cargado', 'fe-woo'),
            ];
        }

        // Set secure permissions
        chmod($upload_path, 0600);

        // Delete old certificate if exists
        $old_cert = FE_Woo_Hacienda_Config::get_certificate_path();
        if (!empty($old_cert) && file_exists($old_cert)) {
            @unlink($old_cert);
        }

        // Invalidate cached cert status — cert just changed.
        self::bust_cached_status();

        return [
            'success' => true,
            'file_path' => $upload_path,
            'message' => __('Certificado cargado exitosamente', 'fe-woo'),
        ];
    }

    /**
     * Validate certificate file
     *
     * @param array $file $_FILES array element
     * @return array Validation result
     */
    private static function validate_certificate_file($file) {
        // Check for upload errors
        if (isset($file['error']) && $file['error'] !== UPLOAD_ERR_OK) {
            return [
                'valid' => false,
                'error' => self::get_upload_error_message($file['error']),
            ];
        }

        // Check file extension
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($extension, self::$allowed_extensions, true)) {
            return [
                'valid' => false,
                'error' => sprintf(
                    __('Tipo de archivo inválido. Tipos permitidos: %s', 'fe-woo'),
                    implode(', ', self::$allowed_extensions)
                ),
            ];
        }

        // Check file size (max 5MB)
        $max_size = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $max_size) {
            return [
                'valid' => false,
                'error' => __('El tamaño del archivo excede el máximo permitido (5MB)', 'fe-woo'),
            ];
        }

        // Check MIME type
        $allowed_mimes = [
            'application/x-pkcs12',
            'application/pkcs12',
            'application/octet-stream',
        ];

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime_type, $allowed_mimes, true)) {
            return [
                'valid' => false,
                'error' => __('Formato de archivo de certificado inválido', 'fe-woo'),
            ];
        }

        return ['valid' => true];
    }

    /**
     * Get user-friendly upload error message
     *
     * @param int $error_code PHP upload error code
     * @return string Error message
     */
    private static function get_upload_error_message($error_code) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => __('El archivo excede el tamaño máximo', 'fe-woo'),
            UPLOAD_ERR_FORM_SIZE => __('El archivo excede el tamaño máximo', 'fe-woo'),
            UPLOAD_ERR_PARTIAL => __('El archivo solo se cargó parcialmente', 'fe-woo'),
            UPLOAD_ERR_NO_FILE => __('No se cargó ningún archivo', 'fe-woo'),
            UPLOAD_ERR_NO_TMP_DIR => __('Falta la carpeta temporal', 'fe-woo'),
            UPLOAD_ERR_CANT_WRITE => __('Error al escribir el archivo en el disco', 'fe-woo'),
            UPLOAD_ERR_EXTENSION => __('Carga bloqueada por extensión PHP', 'fe-woo'),
        ];

        return isset($error_messages[$error_code])
            ? $error_messages[$error_code]
            : __('Error de carga desconocido', 'fe-woo');
    }

    /**
     * Verify certificate with PIN
     *
     * @param string $cert_path Path to certificate file
     * @param string $pin Certificate PIN/password
     * @return array Result with 'valid' boolean and optional 'error' message
     */
    public static function verify_certificate($cert_path, $pin) {
        if (!file_exists($cert_path)) {
            return [
                'valid' => false,
                'error' => __('Archivo de certificado no encontrado', 'fe-woo'),
            ];
        }

        // Try to read the certificate
        $cert_data = file_get_contents($cert_path);
        if ($cert_data === false) {
            return [
                'valid' => false,
                'error' => __('Error al leer el archivo de certificado', 'fe-woo'),
            ];
        }

        // Try to parse PKCS12 certificate
        $certs = [];
        if (!openssl_pkcs12_read($cert_data, $certs, $pin)) {
            return [
                'valid' => false,
                'error' => __('Certificado o PIN inválido', 'fe-woo'),
            ];
        }

        // Parse the X.509 certificate to get details
        if (isset($certs['cert'])) {
            $cert_info = openssl_x509_parse($certs['cert']);

            // Check if certificate is expired
            if (isset($cert_info['validTo_time_t'])) {
                if (time() > $cert_info['validTo_time_t']) {
                    return [
                        'valid' => false,
                        'error' => __('El certificado ha expirado', 'fe-woo'),
                        'cert_info' => $cert_info,
                    ];
                }
            }

            return [
                'valid' => true,
                'cert_info' => $cert_info,
            ];
        }

        return [
            'valid' => false,
            'error' => __('Formato de certificado inválido', 'fe-woo'),
        ];
    }

    /**
     * Load cryptographic material from a PKCS12 file for XML signing.
     *
     * @param string $cert_path Absolute path to .p12/.pfx.
     * @param string $pin Certificate PIN (plaintext).
     * @return array {cert: string PEM, pkey: string PEM, chain: string[], x509_parsed: array}
     * @throws Exception If file unreadable, PIN wrong, or certificate expired.
     */
    public static function load_key_material($cert_path, $pin) {
        if (!is_readable($cert_path)) {
            throw new Exception(sprintf(__('Certificado no encontrado o ilegible: %s', 'fe-woo'), $cert_path));
        }
        $pkcs12 = file_get_contents($cert_path);
        if ($pkcs12 === false) {
            throw new Exception(__('Error al leer el archivo de certificado.', 'fe-woo'));
        }
        $certs = [];
        if (!openssl_pkcs12_read($pkcs12, $certs, (string) $pin)) {
            throw new Exception(__('No se pudo abrir el certificado (PIN incorrecto o archivo corrupto).', 'fe-woo'));
        }
        if (empty($certs['cert']) || empty($certs['pkey'])) {
            throw new Exception(__('El certificado no contiene clave privada o certificado público.', 'fe-woo'));
        }
        $parsed = openssl_x509_parse($certs['cert']);
        if (!$parsed) {
            throw new Exception(__('Certificado PEM inválido (no se pudo parsear X.509).', 'fe-woo'));
        }
        if (isset($parsed['validTo_time_t']) && $parsed['validTo_time_t'] < time()) {
            throw new Exception(sprintf(
                __('Certificado expirado el %s.', 'fe-woo'),
                date('Y-m-d', $parsed['validTo_time_t'])
            ));
        }
        return [
            'cert'        => $certs['cert'],
            'pkey'        => $certs['pkey'],
            'chain'       => isset($certs['extracerts']) ? $certs['extracerts'] : [],
            'x509_parsed' => $parsed,
        ];
    }

    /**
     * Get certificate information
     *
     * @param string $cert_path Path to certificate file
     * @param string $pin Certificate PIN
     * @return array|null Certificate information or null on failure
     */
    public static function get_certificate_info($cert_path, $pin) {
        $verification = self::verify_certificate($cert_path, $pin);

        if (!$verification['valid']) {
            return null;
        }

        if (isset($verification['cert_info'])) {
            $cert_info = $verification['cert_info'];

            return [
                'subject' => isset($cert_info['subject']) ? $cert_info['subject'] : [],
                'issuer' => isset($cert_info['issuer']) ? $cert_info['issuer'] : [],
                'valid_from' => isset($cert_info['validFrom_time_t']) ? date('Y-m-d H:i:s', $cert_info['validFrom_time_t']) : '',
                'valid_to' => isset($cert_info['validTo_time_t']) ? date('Y-m-d H:i:s', $cert_info['validTo_time_t']) : '',
                'serial_number' => isset($cert_info['serialNumber']) ? $cert_info['serialNumber'] : '',
            ];
        }

        return null;
    }

    /**
     * Delete certificate file
     *
     * @param string $cert_path Path to certificate file
     * @return bool True on success
     */
    public static function delete_certificate($cert_path) {
        if (!empty($cert_path) && file_exists($cert_path)) {
            $ok = @unlink($cert_path);
            self::bust_cached_status();
            return $ok;
        }
        return false;
    }

    /**
     * Get certificate status
     *
     * @return array Status information
     */
    public static function get_status() {
        $cert_path = FE_Woo_Hacienda_Config::get_certificate_path();
        $cert_pin = FE_Woo_Hacienda_Config::get_certificate_pin();

        if (empty($cert_path) || !file_exists($cert_path)) {
            return [
                'status' => 'missing',
                'message' => __('No se ha cargado ningún certificado', 'fe-woo'),
            ];
        }

        if (empty($cert_pin)) {
            return [
                'status' => 'incomplete',
                'message' => __('PIN del certificado no configurado', 'fe-woo'),
            ];
        }

        $verification = self::verify_certificate($cert_path, $cert_pin);

        if (!$verification['valid']) {
            return [
                'status' => 'invalid',
                'message' => $verification['error'],
            ];
        }

        $cert_info = isset($verification['cert_info']) ? $verification['cert_info'] : null;
        $valid_to = isset($cert_info['validTo_time_t']) ? $cert_info['validTo_time_t'] : null;

        if ($valid_to) {
            $days_until_expiry = ceil(($valid_to - time()) / 86400);

            if ($days_until_expiry < 30) {
                return [
                    'status' => 'expiring',
                    'message' => sprintf(
                        __('El certificado expira en %d días', 'fe-woo'),
                        $days_until_expiry
                    ),
                    'days_until_expiry' => $days_until_expiry,
                    'cert_info' => $cert_info,
                ];
            }
        }

        return [
            'status' => 'valid',
            'message' => __('El certificado es válido', 'fe-woo'),
            'cert_info' => $cert_info,
        ];
    }

    /**
     * Cached version of get_status() for use en admin_notices y otros loops
     * de pageload. Re-checkea cada hora; el cert no cambia más rápido y leerlo
     * + parsearlo con openssl en cada pageload es costoso.
     *
     * Se invalida implícitamente cuando se sube un nuevo cert (via delete del
     * transient en upload_certificate / delete_certificate).
     *
     * @return array Mismo shape que get_status().
     */
    public static function get_cached_status() {
        $cache_key = 'fe_woo_cert_status';
        $cached = get_transient($cache_key);
        if (is_array($cached)) {
            return $cached;
        }
        $status = self::get_status();
        // No cachear estados sin cert / sin pin — son cheap de checkear y el
        // usuario los está activamente resolviendo.
        if (in_array($status['status'], ['valid', 'expiring', 'invalid'], true)) {
            set_transient($cache_key, $status, HOUR_IN_SECONDS);
        }
        return $status;
    }

    /**
     * Bust the cached cert status (call después de upload/delete).
     */
    public static function bust_cached_status() {
        delete_transient('fe_woo_cert_status');
    }
}
