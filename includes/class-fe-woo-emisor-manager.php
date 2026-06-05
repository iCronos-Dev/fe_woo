<?php
/**
 * Emisor Manager Class
 *
 * Manages CRUD operations for multiple emisores (issuers) for electronic invoicing
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Emisor_Manager
 *
 * Handles creation, reading, updating, and deletion of emisores
 */
class FE_Woo_Emisor_Manager {

    /**
     * Database table name (without prefix)
     */
    const TABLE_NAME = 'fe_woo_emisores';

    /**
     * Create the emisores table in the database
     *
     * @return bool True on success, false on failure
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        // Note: dbDelta() is very strict about formatting:
        // - No blank lines between column definitions
        // - Two spaces between PRIMARY KEY and opening parenthesis
        // - KEY instead of INDEX
        // - No UNIQUE KEY (added separately below to avoid dbDelta parsing bugs)
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            is_parent tinyint(1) NOT NULL DEFAULT 0,
            nombre_legal varchar(255) NOT NULL,
            cedula_juridica varchar(50) NOT NULL,
            tipo_identificacion varchar(2) NOT NULL DEFAULT '02',
            nombre_comercial varchar(255) DEFAULT NULL,
            api_username varchar(255) DEFAULT NULL,
            api_password varchar(255) DEFAULT NULL,
            certificate_path varchar(500) DEFAULT NULL,
            certificate_pin varchar(255) DEFAULT NULL,
            actividad_economica varchar(10) NOT NULL,
            codigo_provincia varchar(2) NOT NULL,
            codigo_canton varchar(2) NOT NULL,
            codigo_distrito varchar(2) NOT NULL,
            codigo_barrio varchar(4) DEFAULT NULL,
            direccion text NOT NULL,
            telefono varchar(50) DEFAULT NULL,
            email varchar(100) DEFAULT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            created_by bigint(20) UNSIGNED DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_cedula (cedula_juridica),
            KEY idx_is_parent (is_parent),
            KEY idx_active (active)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Check if table was created successfully
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;

        if ($table_exists) {
            // Add UNIQUE constraint separately (dbDelta can't reliably parse UNIQUE KEY)
            $index_exists = $wpdb->get_var(
                "SHOW INDEX FROM {$table_name} WHERE Key_name = 'unique_cedula'"
            );
            if (!$index_exists) {
                $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY unique_cedula (cedula_juridica)");
            }

            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info('Emisores table created/updated successfully', ['source' => 'fe-woo-emisor']);
            }
        }

        return $table_exists;
    }

    /**
     * Create a new emisor
     *
     * @param array $data Emisor data
     * @return array Result with success status, emisor_id or error message
     */
    public static function create_emisor($data) {
        global $wpdb;

        // Validate data
        $validation = self::validate_emisor_data($data);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        // Check if parent emisor already exists when trying to create another parent
        if (!empty($data['is_parent']) && $data['is_parent']) {
            $existing_parent = self::get_parent_emisor();
            if ($existing_parent) {
                return [
                    'success' => false,
                    'errors' => [__('Ya existe un emisor padre. Solo puede haber un emisor padre en el sistema.', 'fe-woo')],
                ];
            }
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Prepare data for insertion
        $insert_data = [
            'is_parent' => !empty($data['is_parent']) ? 1 : 0,
            'nombre_legal' => sanitize_text_field($data['nombre_legal']),
            'cedula_juridica' => sanitize_text_field($data['cedula_juridica']),
            'tipo_identificacion' => !empty($data['tipo_identificacion']) ? sanitize_text_field($data['tipo_identificacion']) : '02',
            'nombre_comercial' => !empty($data['nombre_comercial']) ? sanitize_text_field($data['nombre_comercial']) : null,
            'api_username' => !empty($data['api_username']) ? sanitize_text_field($data['api_username']) : null,
            'certificate_path' => !empty($data['certificate_path']) ? sanitize_text_field($data['certificate_path']) : null,
            'certificate_pin' => null,
            'api_password' => null,
            'actividad_economica' => sanitize_text_field($data['actividad_economica']),
            'codigo_provincia' => sanitize_text_field($data['codigo_provincia']),
            'codigo_canton' => sanitize_text_field($data['codigo_canton']),
            'codigo_distrito' => sanitize_text_field($data['codigo_distrito']),
            'codigo_barrio' => !empty($data['codigo_barrio']) ? sanitize_text_field($data['codigo_barrio']) : null,
            'direccion' => sanitize_textarea_field($data['direccion']),
            'telefono' => !empty($data['telefono']) ? sanitize_text_field($data['telefono']) : null,
            'email' => !empty($data['email']) ? sanitize_email($data['email']) : null,
            'active' => !empty($data['active']) ? 1 : 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
            'created_by' => get_current_user_id(),
        ];

        // Encrypt sensitive values with error handling
        try {
            if (!empty($data['api_password'])) {
                $insert_data['api_password'] = self::encrypt_value(sanitize_text_field($data['api_password']));
            }
            if (!empty($data['certificate_pin'])) {
                $insert_data['certificate_pin'] = self::encrypt_value(sanitize_text_field($data['certificate_pin']));
            }
        } catch (\RuntimeException $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }

        $inserted = $wpdb->insert($table_name, $insert_data);

        if ($inserted === false) {
            $db_error = $wpdb->last_error;

            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error("Failed to create emisor. DB error: {$db_error}", [
                    'source' => 'fe-woo-emisor',
                    'insert_data_keys' => array_keys($insert_data),
                ]);
            }

            return [
                'success' => false,
                'errors' => [
                    __('Error al crear el emisor en la base de datos. Revise los registros para más detalles.', 'fe-woo'),
                ],
            ];
        }

        $emisor_id = $wpdb->insert_id;

        // Clear cache
        self::clear_emisor_cache($emisor_id);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info("Emisor created: {$insert_data['nombre_legal']} (ID: {$emisor_id})", [
                'source' => 'fe-woo-emisor',
                'emisor_id' => $emisor_id,
            ]);
        }

        return [
            'success' => true,
            'emisor_id' => $emisor_id,
            'message' => __('Emisor creado exitosamente.', 'fe-woo'),
        ];
    }

    /**
     * Get emisor by ID
     *
     * @param int $emisor_id Emisor ID
     * @return object|null Emisor object or null if not found
     */
    public static function get_emisor($emisor_id) {
        // Try to get from cache first
        $cache_key = "fe_woo_emisor_{$emisor_id}";
        $emisor = wp_cache_get($cache_key, 'fe_woo');

        if (false === $emisor) {
            global $wpdb;
            $table_name = $wpdb->prefix . self::TABLE_NAME;

            $emisor = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} WHERE id = %d",
                $emisor_id
            ));

            if ($emisor) {
                // Cache the raw (encrypted) version - never cache decrypted secrets
                wp_cache_set($cache_key, $emisor, 'fe_woo', HOUR_IN_SECONDS);
            }
        }

        // Always decrypt after cache retrieval
        if ($emisor) {
            $emisor = self::decrypt_emisor_fields($emisor);
        }

        return $emisor;
    }

    /**
     * Update emisor
     *
     * @param int   $emisor_id Emisor ID
     * @param array $data      Emisor data to update
     * @return array Result with success status and message
     */
    public static function update_emisor($emisor_id, $data) {
        global $wpdb;

        // Check if emisor exists
        $existing = self::get_emisor($emisor_id);
        if (!$existing) {
            return [
                'success' => false,
                'errors' => [__('Emisor no encontrado.', 'fe-woo')],
            ];
        }

        // Merge existing data with new data for validation (allows partial updates)
        $merged_data = array_merge((array) $existing, $data);
        $validation = self::validate_emisor_data($merged_data, $emisor_id);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'errors' => $validation['errors'],
            ];
        }

        // Check if trying to set as parent when another parent exists
        if (!empty($data['is_parent']) && $data['is_parent'] && !$existing->is_parent) {
            $existing_parent = self::get_parent_emisor();
            if ($existing_parent && $existing_parent->id !== $emisor_id) {
                return [
                    'success' => false,
                    'errors' => [__('Ya existe un emisor padre. Solo puede haber un emisor padre en el sistema.', 'fe-woo')],
                ];
            }
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Prepare data for update
        $update_data = [];

        if (isset($data['is_parent'])) {
            $update_data['is_parent'] = !empty($data['is_parent']) ? 1 : 0;
        }
        if (isset($data['nombre_legal'])) {
            $update_data['nombre_legal'] = sanitize_text_field($data['nombre_legal']);
        }
        if (isset($data['cedula_juridica'])) {
            $update_data['cedula_juridica'] = sanitize_text_field($data['cedula_juridica']);
        }
        if (isset($data['tipo_identificacion'])) {
            $update_data['tipo_identificacion'] = sanitize_text_field($data['tipo_identificacion']);
        }
        if (isset($data['nombre_comercial'])) {
            $update_data['nombre_comercial'] = sanitize_text_field($data['nombre_comercial']);
        }
        if (isset($data['api_username'])) {
            $update_data['api_username'] = sanitize_text_field($data['api_username']);
        }
        if (isset($data['certificate_path'])) {
            $update_data['certificate_path'] = sanitize_text_field($data['certificate_path']);
        }
        try {
            if (!empty($data['api_password'])) {
                $update_data['api_password'] = self::encrypt_value(sanitize_text_field($data['api_password']));
            }
            if (!empty($data['certificate_pin'])) {
                $update_data['certificate_pin'] = self::encrypt_value(sanitize_text_field($data['certificate_pin']));
            }
        } catch (\RuntimeException $e) {
            return ['success' => false, 'errors' => [$e->getMessage()]];
        }
        if (isset($data['actividad_economica'])) {
            $update_data['actividad_economica'] = sanitize_text_field($data['actividad_economica']);
        }
        if (isset($data['codigo_provincia'])) {
            $update_data['codigo_provincia'] = sanitize_text_field($data['codigo_provincia']);
        }
        if (isset($data['codigo_canton'])) {
            $update_data['codigo_canton'] = sanitize_text_field($data['codigo_canton']);
        }
        if (isset($data['codigo_distrito'])) {
            $update_data['codigo_distrito'] = sanitize_text_field($data['codigo_distrito']);
        }
        if (isset($data['codigo_barrio'])) {
            $update_data['codigo_barrio'] = sanitize_text_field($data['codigo_barrio']);
        }
        if (isset($data['direccion'])) {
            $update_data['direccion'] = sanitize_textarea_field($data['direccion']);
        }
        if (isset($data['telefono'])) {
            $update_data['telefono'] = sanitize_text_field($data['telefono']);
        }
        if (isset($data['email'])) {
            $update_data['email'] = sanitize_email($data['email']);
        }
        if (isset($data['active'])) {
            $update_data['active'] = !empty($data['active']) ? 1 : 0;
        }

        if (empty($update_data)) {
            return [
                'success' => false,
                'errors' => [__('No hay datos para actualizar.', 'fe-woo')],
            ];
        }

        // Always update the timestamp
        $update_data['updated_at'] = current_time('mysql');

        $updated = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $emisor_id]
        );

        if ($updated === false) {
            $db_error = $wpdb->last_error;

            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->error("Failed to update emisor ID {$emisor_id}. DB error: {$db_error}", [
                    'source' => 'fe-woo-emisor',
                    'update_data_keys' => array_keys($update_data),
                ]);
            }

            return [
                'success' => false,
                'errors' => [
                    __('Error al actualizar el emisor en la base de datos. Revise los registros para más detalles.', 'fe-woo'),
                ],
            ];
        }

        // Clear cache
        self::clear_emisor_cache($emisor_id);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info("Emisor updated: ID {$emisor_id}", [
                'source' => 'fe-woo-emisor',
                'emisor_id' => $emisor_id,
            ]);
        }

        return [
            'success' => true,
            'message' => __('Emisor actualizado exitosamente.', 'fe-woo'),
        ];
    }

    /**
     * Delete emisor (hard delete)
     *
     * @param int $emisor_id Emisor ID
     * @return array Result with success status and message
     */
    public static function delete_emisor($emisor_id) {
        global $wpdb;

        // Check if emisor exists
        $existing = self::get_emisor($emisor_id);
        if (!$existing) {
            return [
                'success' => false,
                'errors' => [__('Emisor no encontrado.', 'fe-woo')],
            ];
        }

        // Check if it's the parent emisor
        if ($existing->is_parent) {
            return [
                'success' => false,
                'errors' => [__('No se puede eliminar el emisor padre.', 'fe-woo')],
            ];
        }

        // Check if emisor has products associated
        $products_count = self::count_products_by_emisor($emisor_id);
        if ($products_count > 0) {
            return [
                'success' => false,
                'errors' => [
                    sprintf(
                        __('No se puede eliminar el emisor porque tiene %d producto(s) asociado(s). Por favor reasigne los productos a otro emisor primero.', 'fe-woo'),
                        $products_count
                    ),
                ],
            ];
        }

        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // Hard delete
        $deleted = $wpdb->delete(
            $table_name,
            ['id' => $emisor_id],
            ['%d']
        );

        if ($deleted === false) {
            return [
                'success' => false,
                'errors' => [__('Error al eliminar el emisor.', 'fe-woo')],
            ];
        }

        // Clear cache
        self::clear_emisor_cache($emisor_id);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info("Emisor deleted: ID {$emisor_id}", [
                'source' => 'fe-woo-emisor',
                'emisor_id' => $emisor_id,
            ]);
        }

        return [
            'success' => true,
            'message' => __('Emisor eliminado exitosamente.', 'fe-woo'),
        ];
    }

    /**
     * Get all emisores
     *
     * @param bool $active_only Whether to return only active emisores
     * @return array Array of emisor objects
     */
    public static function get_all_emisores($active_only = true) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $where = $active_only ? 'WHERE active = 1' : '';

        $emisores = $wpdb->get_results(
            "SELECT * FROM {$table_name} {$where} ORDER BY is_parent DESC, nombre_legal ASC"
        );

        if ($emisores) {
            foreach ($emisores as &$emisor) {
                $emisor = self::decrypt_emisor_fields($emisor);
            }
            unset($emisor);
        }

        return $emisores ? $emisores : [];
    }

    /**
     * Get parent emisor
     *
     * @return object|null Parent emisor object or null
     */
    public static function get_parent_emisor() {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $emisor = $wpdb->get_row(
            "SELECT * FROM {$table_name} WHERE is_parent = 1 AND active = 1 LIMIT 1"
        );

        return $emisor ? self::decrypt_emisor_fields($emisor) : null;
    }

    /**
     * Get emisor by cédula jurídica.
     *
     * El consumidor más común es resolver credenciales OAuth a partir de un
     * clave de Hacienda: posiciones 9..20 (12 dígitos) contienen la cédula
     * padded con ceros a la izquierda (ej. `003101950828` para una jurídica
     * `3101950828`). Acá se normalizan ambos lados eliminando ceros líderes
     * para que un clave-extraído matchee la fila aunque la cédula esté
     * almacenada en su forma corta.
     *
     * @param string $cedula Cédula jurídica (con o sin padding de ceros).
     * @return object|null Emisor activo o null si no se encuentra.
     */
    public static function get_emisor_by_cedula($cedula) {
        $digits = preg_replace('/\D/', '', (string) $cedula);
        $digits = ltrim($digits, '0');
        if ($digits === '') {
            return null;
        }

        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        // TRIM(LEADING '0' ...) en el lado almacenado para que un clave-extraído
        // padded matchee una fila con cédula sin padding (caso típico).
        $emisor = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name}
             WHERE TRIM(LEADING '0' FROM cedula_juridica) = %s
             AND active = 1
             LIMIT 1",
            $digits
        ));

        return $emisor ? self::decrypt_emisor_fields($emisor) : null;
    }

    /**
     * Search emisores by name or cedula
     *
     * @param string $search_term Search term
     * @param bool   $active_only Whether to search only active emisores
     * @return array Array of emisor objects
     */
    public static function search_emisores($search_term, $active_only = true) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::TABLE_NAME;

        $search_term = '%' . $wpdb->esc_like($search_term) . '%';
        $where = $active_only ? 'AND active = 1' : '';

        $emisores = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name}
            WHERE (nombre_legal LIKE %s OR cedula_juridica LIKE %s) {$where}
            ORDER BY is_parent DESC, nombre_legal ASC",
            $search_term,
            $search_term
        ));

        if ($emisores) {
            foreach ($emisores as &$emisor) {
                $emisor = self::decrypt_emisor_fields($emisor);
            }
            unset($emisor);
        }

        return $emisores ? $emisores : [];
    }

    /**
     * Validate emisor data
     *
     * @param array $data      Emisor data to validate
     * @param int   $emisor_id Optional emisor ID (for updates)
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validate_emisor_data($data, $emisor_id = null) {
        $errors = [];

        // Required fields
        if (empty($data['nombre_legal'])) {
            $errors[] = __('El nombre legal es requerido.', 'fe-woo');
        }

        // NombreComercial es requerido por el XSD v4.4 del proyecto (maxLength=80).
        if (empty($data['nombre_comercial'])) {
            $errors[] = __('El nombre comercial es requerido.', 'fe-woo');
        } elseif (mb_strlen($data['nombre_comercial']) > 80) {
            $errors[] = __('El nombre comercial no puede exceder 80 caracteres.', 'fe-woo');
        }

        if (empty($data['cedula_juridica'])) {
            $errors[] = __('La cédula jurídica es requerida.', 'fe-woo');
        } else {
            // Validate cedula format (only numbers)
            if (!preg_match('/^\d+$/', $data['cedula_juridica'])) {
                $errors[] = __('La cédula jurídica debe contener solo números.', 'fe-woo');
            }

            // Check if cedula is unique
            global $wpdb;
            $table_name = $wpdb->prefix . self::TABLE_NAME;

            if ($emisor_id) {
                // For updates, check if cedula exists for other emisores
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE cedula_juridica = %s AND id != %d",
                    $data['cedula_juridica'],
                    $emisor_id
                ));
            } else {
                // For creates, check if cedula exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$table_name} WHERE cedula_juridica = %s",
                    $data['cedula_juridica']
                ));
            }

            if ($existing) {
                $errors[] = __('Ya existe un emisor con esta cédula jurídica.', 'fe-woo');
            }
        }

        if (empty($data['actividad_economica'])) {
            $errors[] = __('La actividad económica es requerida.', 'fe-woo');
        } else {
            // Validate format: 1234.5
            if (!preg_match('/^\d{4}\.\d{1}$/', $data['actividad_economica'])) {
                $errors[] = __('El formato de actividad económica es inválido. Formato requerido: 1234.5', 'fe-woo');
            }
        }

        if (empty($data['codigo_provincia'])) {
            $errors[] = __('El código de provincia es requerido.', 'fe-woo');
        }

        if (empty($data['codigo_canton'])) {
            $errors[] = __('El código de cantón es requerido.', 'fe-woo');
        }

        if (empty($data['codigo_distrito'])) {
            $errors[] = __('El código de distrito es requerido.', 'fe-woo');
        }

        // Dirección — XSD v4.4 OtrasSenas: minLength=5, maxLength=250.
        // Bloquear acá evita guardar un emisor que después rompe el XSD y
        // quema un consecutivo en Hacienda en la primera emisión.
        if (empty($data['direccion'])) {
            $errors[] = __('La dirección es requerida.', 'fe-woo');
        } else {
            $direccion_trim = trim((string) $data['direccion']);
            $direccion_len = function_exists('mb_strlen') ? mb_strlen($direccion_trim) : strlen($direccion_trim);
            if ($direccion_len < 5) {
                $errors[] = __('La dirección debe tener al menos 5 caracteres (Hacienda XSD v4.4 OtrasSenas).', 'fe-woo');
            } elseif ($direccion_len > 250) {
                $errors[] = __('La dirección no puede exceder 250 caracteres (Hacienda XSD v4.4 OtrasSenas).', 'fe-woo');
            }
        }

        // Validate email format if provided
        if (!empty($data['email']) && !is_email($data['email'])) {
            $errors[] = __('El formato del correo electrónico es inválido.', 'fe-woo');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Count products associated with an emisor
     *
     * @param int $emisor_id Emisor ID
     * @return int Number of products
     */
    public static function count_products_by_emisor($emisor_id) {
        global $wpdb;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
            WHERE meta_key = '_fe_woo_emisor_id' AND meta_value = %d",
            $emisor_id
        ));

        return (int) $count;
    }

    /**
     * Encrypt a sensitive value for storage
     *
     * @param string $value Plain text value
     * @return string Encrypted value (base64 encoded)
     */
    private static function encrypt_value($value) {
        if (empty($value)) {
            return $value;
        }

        $key = self::get_encryption_key();
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
        $encrypted = openssl_encrypt($value, 'aes-256-cbc', $key, 0, $iv);

        if ($encrypted === false) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->critical('Encryption failed for sensitive value. Value NOT stored.', [
                    'source' => 'fe-woo-emisor',
                ]);
            }
            throw new \RuntimeException(
                __('Error crítico: no se pudo cifrar el valor sensible. Verifique la configuración de OpenSSL.', 'fe-woo')
            );
        }

        return base64_encode($iv . '::' . $encrypted);
    }

    /**
     * Decrypt a sensitive value from storage
     *
     * @param string $value Encrypted value (base64 encoded)
     * @return string Decrypted plain text value
     */
    private static function decrypt_value($value) {
        if (empty($value)) {
            return $value;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || strpos($decoded, '::') === false) {
            // Value is not encrypted (legacy plain text), return as-is
            return $value;
        }

        $key = self::get_encryption_key();
        list($iv, $encrypted) = explode('::', $decoded, 2);
        $decrypted = openssl_decrypt($encrypted, 'aes-256-cbc', $key, 0, $iv);

        if ($decrypted === false) {
            // Decryption failed - may be legacy plain text that happened to base64 decode
            return $value;
        }

        return $decrypted;
    }

    /**
     * Get encryption key derived from WordPress auth constants
     *
     * WARNING: SECURE_AUTH_KEY must not change once emisores with encrypted
     * fields exist. Changing it will make api_password and certificate_pin
     * values unrecoverable. If key rotation is needed in the future,
     * implement versioned encryption (e.g. prefix "v1:" to encrypted values)
     * and maintain a key history table.
     *
     * @return string Encryption key
     */
    private static function get_encryption_key() {
        if (defined('SECURE_AUTH_KEY') && SECURE_AUTH_KEY !== '' && SECURE_AUTH_KEY !== 'generateme') {
            return hash('sha256', SECURE_AUTH_KEY, true);
        }

        // In production, SECURE_AUTH_KEY must be properly defined
        $env = defined('WP_ENV') ? WP_ENV : 'production';
        if ($env === 'production') {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->critical('SECURE_AUTH_KEY is not properly defined in production. Cannot encrypt sensitive data.', [
                    'source' => 'fe-woo-emisor',
                ]);
            }
            throw new \RuntimeException(
                __('Error crítico: SECURE_AUTH_KEY no está configurado. No se pueden cifrar datos sensibles.', 'fe-woo')
            );
        }

        // Development/staging fallback - use AUTH_KEY or site-specific key
        $fallback = defined('AUTH_KEY') && AUTH_KEY !== '' ? AUTH_KEY : wp_salt('secure_auth');
        return hash('sha256', $fallback, true);
    }

    /**
     * Decrypt sensitive fields on an emisor object
     *
     * @param object $emisor Emisor object from database
     * @return object Emisor object with decrypted fields
     */
    private static function decrypt_emisor_fields($emisor) {
        if (!$emisor) {
            return $emisor;
        }

        try {
            $emisor->api_password = self::decrypt_value($emisor->api_password);
            $emisor->certificate_pin = self::decrypt_value($emisor->certificate_pin);
        } catch (\RuntimeException $e) {
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->error("Failed to decrypt emisor fields for ID {$emisor->id}: " . $e->getMessage(), [
                    'source' => 'fe-woo-emisor',
                ]);
            }
            $emisor->api_password = '';
            $emisor->certificate_pin = '';
        }

        return $emisor;
    }

    /**
     * Clear emisor cache
     *
     * @param int $emisor_id Emisor ID
     */
    private static function clear_emisor_cache($emisor_id) {
        wp_cache_delete("fe_woo_emisor_{$emisor_id}", 'fe_woo');
    }

    /**
     * Migrate current emisor configuration to parent emisor
     *
     * This reads the current configuration from FE_Woo_Hacienda_Config
     * and creates it as the parent emisor in the new system
     *
     * @return array Result with success status and message
     */
    public static function migrate_current_emisor_to_parent() {
        // Check if parent emisor already exists
        $existing_parent = self::get_parent_emisor();
        if ($existing_parent) {
            return [
                'success' => false,
                'errors' => [__('Ya existe un emisor padre. La migración ya fue realizada.', 'fe-woo')],
            ];
        }

        // Get current configuration
        $config = FE_Woo_Hacienda_Config::get_all_config();
        $location = FE_Woo_Hacienda_Config::get_location_codes();

        // Prepare data for parent emisor
        $parent_data = [
            'is_parent' => true,
            'nombre_legal' => $config['company_name'],
            'cedula_juridica' => $config['cedula_juridica'],
            'nombre_comercial' => null,
            'api_username' => $config['api_username'],
            'api_password' => $config['api_password'],
            'certificate_path' => $config['certificate_path'],
            'certificate_pin' => $config['certificate_pin'],
            'actividad_economica' => $config['economic_activity'],
            'codigo_provincia' => $location['province'],
            'codigo_canton' => $location['canton'],
            'codigo_distrito' => $location['district'],
            'codigo_barrio' => $location['neighborhood'],
            'direccion' => $config['address'],
            'telefono' => $config['phone'],
            'email' => $config['email'],
            'active' => 1,
        ];

        // Create parent emisor
        $result = self::create_emisor($parent_data);

        if ($result['success']) {
            // Add migration flag
            update_option('fe_woo_emisor_migration_completed', true);
            update_option('fe_woo_emisor_migration_date', current_time('mysql'));

            if (function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info('Emisor migration completed successfully', [
                    'source' => 'fe-woo-emisor',
                    'parent_emisor_id' => $result['emisor_id'],
                ]);
            }

            return [
                'success' => true,
                'emisor_id' => $result['emisor_id'],
                'message' => __('Migración completada exitosamente. El emisor actual ahora es el emisor padre.', 'fe-woo'),
            ];
        }

        return $result;
    }
}
