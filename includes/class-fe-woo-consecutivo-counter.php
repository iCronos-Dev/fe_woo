<?php
/**
 * Consecutivo Counter
 *
 * Genera NumeroConsecutivo secuenciales por (cedula_emisor, sucursal, terminal,
 * document_type) cumpliendo Resolución DGT-R-48-2016 art. 4 (consecutivos
 * únicos e incrementales sin huecos por punto de venta y tipo de documento).
 *
 * El diseño anterior usaba `str_pad($order->get_id(), 10, '0')` lo que producía
 * dos problemas: (1) Hacienda rechaza con "consecutivo ya existe" cuando se
 * reintenta una orden previamente enviada (aceptada o rechazada), y (2) los
 * consecutivos saltan números (16170 → 16175 → 16180) cuando órdenes
 * intermedias no producen tiquete, violando la regla de "sin huecos".
 *
 * Hacienda guarda TODOS los consecutivos recibidos en /recepcion (aceptados Y
 * rechazados). Una vez consumido, un consecutivo no puede reusarse.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Consecutivo_Counter {

    const TABLE_NAME = 'fe_woo_consecutivos';

    /**
     * Crear la tabla del contador.
     *
     * Idempotente: dbDelta deja la tabla intacta si ya existe.
     *
     * @return bool True si la tabla existe al final.
     */
    public static function create_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        // dbDelta requirements: dos espacios antes del paréntesis del PRIMARY KEY,
        // KEY en lugar de INDEX, sin UNIQUE KEY (lo agregamos por ALTER abajo).
        $sql = "CREATE TABLE {$table_name} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            cedula_emisor varchar(20) NOT NULL,
            sucursal varchar(3) NOT NULL DEFAULT '001',
            terminal varchar(5) NOT NULL DEFAULT '00001',
            document_type varchar(2) NOT NULL,
            next_value bigint(20) UNSIGNED NOT NULL DEFAULT 1,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_cedula (cedula_emisor),
            KEY idx_doctype (document_type)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $table_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table_name)) === $table_name;

        if ($table_exists) {
            $unique_exists = $wpdb->get_var(
                "SHOW INDEX FROM {$table_name} WHERE Key_name = 'unique_emisor_pos_doctype'"
            );
            if (!$unique_exists) {
                $wpdb->query("ALTER TABLE {$table_name} ADD UNIQUE KEY unique_emisor_pos_doctype (cedula_emisor, sucursal, terminal, document_type)");
            }
        }

        return $table_exists;
    }

    /**
     * Tomar el siguiente consecutivo atómicamente.
     *
     * Usa el truco LAST_INSERT_ID(expr) de MySQL para incrementar y devolver
     * el valor anterior en una sola operación. Si la fila no existe, la inserta
     * con next_value=2 y devuelve 1.
     *
     * Ojo: `$wpdb->insert_id` se popula sólo desde INSERTs con AUTO_INCREMENT,
     * NO refleja LAST_INSERT_ID(expr) tras un UPDATE. Para leer el valor
     * almacenado por LAST_INSERT_ID(expr) hay que ejecutar un SELECT explícito
     * dentro de la misma conexión. Si no lo hacemos, devolveríamos el insert_id
     * de cualquier INSERT anterior del request (notas de orden, items de cola,
     * etc.) — eso causa consecutivos basura como 75970 cuando el counter está
     * en 16177.
     *
     * @param string $cedula_emisor  Cédula del emisor (jurídica o física).
     * @param string $sucursal       Sucursal (3 dígitos).
     * @param string $terminal       Terminal/punto de venta (5 dígitos).
     * @param string $document_type  Código de tipo de documento (01–05).
     * @return int Consecutivo numérico (sin padding) reservado para este envío.
     * @throws RuntimeException Si la BD falla al reservar.
     */
    public static function next_value($cedula_emisor, $sucursal, $terminal, $document_type) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Path feliz: la fila ya existe → UPDATE atómico que incrementa y
        // expone el valor anterior vía LAST_INSERT_ID(expr). Inmediatamente
        // después leemos `SELECT LAST_INSERT_ID()` en la misma conexión.
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET next_value = LAST_INSERT_ID(next_value) + 1
             WHERE cedula_emisor = %s AND sucursal = %s AND terminal = %s AND document_type = %s",
            $cedula_emisor, $sucursal, $terminal, $document_type
        ));

        if ($updated > 0) {
            return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        }

        // Fila no existe: insertar con next_value=2 y devolver 1.
        // INSERT IGNORE para tolerar carrera con otra request concurrente.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (cedula_emisor, sucursal, terminal, document_type, next_value)
             VALUES (%s, %s, %s, %s, 2)",
            $cedula_emisor, $sucursal, $terminal, $document_type
        ));

        if ($inserted === 1) {
            return 1;
        }

        // Perdimos la carrera: la fila la creó otro proceso entre nuestro UPDATE
        // y nuestro INSERT. Reintentar el UPDATE.
        $retry = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET next_value = LAST_INSERT_ID(next_value) + 1
             WHERE cedula_emisor = %s AND sucursal = %s AND terminal = %s AND document_type = %s",
            $cedula_emisor, $sucursal, $terminal, $document_type
        ));

        if ($retry > 0) {
            return (int) $wpdb->get_var('SELECT LAST_INSERT_ID()');
        }

        throw new RuntimeException(sprintf(
            'No se pudo reservar consecutivo para emisor=%s sucursal=%s terminal=%s tipo=%s',
            $cedula_emisor, $sucursal, $terminal, $document_type
        ));
    }

    /**
     * Backfill: inicializar el contador con el max consecutivo encontrado en
     * órdenes históricas. Pensado para correr una sola vez en el upgrade a la
     * versión que introduce este contador.
     *
     * Estrategia:
     *   1. Recorrer todos los `_fe_woo_factura_clave` en postmeta + HPOS meta.
     *   2. Recorrer todos los `_fe_woo_facturas_generated` (multi-factura) y
     *      extraer la clave de cada factura.
     *   3. Parsear cada clave: cédula(9-20), sucursal(21-23), terminal(24-28),
     *      doc_type(29-30), numero(31-40).
     *   4. Calcular max por (cedula, sucursal, terminal, doc_type).
     *   5. UPSERT en wp_fe_woo_consecutivos con next_value = max + 1.
     *
     * Es idempotente: si la fila ya existe con next_value mayor, no la baja.
     *
     * @return array ['groups' => int, 'orders_scanned' => int, 'errors' => array]
     */
    public static function backfill_from_orders() {
        global $wpdb;

        $maxes = []; // key: "cedula|sucursal|terminal|doctype" → max consecutivo
        $orders_scanned = 0;
        $errors = [];

        $accumulate = function ($clave) use (&$maxes) {
            if (!is_string($clave) || strlen($clave) !== 50) {
                return;
            }
            $cedula = substr($clave, 9, 12);
            $sucursal = substr($clave, 21, 3);
            $terminal = substr($clave, 24, 5);
            $doctype = substr($clave, 29, 2);
            $numero = (int) substr($clave, 31, 10);
            if ($numero <= 0) {
                return;
            }
            $key = $cedula . '|' . $sucursal . '|' . $terminal . '|' . $doctype;
            if (!isset($maxes[$key]) || $maxes[$key] < $numero) {
                $maxes[$key] = $numero;
            }
        };

        // 1. Claves en postmeta (modo legacy / pre-HPOS).
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_fe_woo_factura_clave'
        ));
        foreach ((array) $rows as $clave) {
            $accumulate($clave);
            $orders_scanned++;
        }

        // 2. Claves en wp_wc_orders_meta (HPOS).
        $hpos_table = $wpdb->prefix . 'wc_orders_meta';
        $hpos_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_table)) === $hpos_table;
        if ($hpos_exists) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$hpos_table} WHERE meta_key = %s",
                '_fe_woo_factura_clave'
            ));
            foreach ((array) $rows as $clave) {
                $accumulate($clave);
                $orders_scanned++;
            }
        }

        // 3. Multi-factura (postmeta).
        $multi_rows = $wpdb->get_col($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
            '_fe_woo_facturas_generated'
        ));
        if ($hpos_exists) {
            $multi_rows = array_merge($multi_rows, (array) $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$hpos_table} WHERE meta_key = %s",
                '_fe_woo_facturas_generated'
            )));
        }
        foreach ((array) $multi_rows as $serialized) {
            $arr = maybe_unserialize($serialized);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $factura) {
                if (!empty($factura['clave'])) {
                    $accumulate($factura['clave']);
                }
            }
        }

        // 4. UPSERT por grupo.
        $table = $wpdb->prefix . self::TABLE_NAME;
        foreach ($maxes as $key => $max_numero) {
            list($cedula, $sucursal, $terminal, $doctype) = explode('|', $key);
            $next = $max_numero + 1;
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO {$table} (cedula_emisor, sucursal, terminal, document_type, next_value)
                 VALUES (%s, %s, %s, %s, %d)
                 ON DUPLICATE KEY UPDATE next_value = GREATEST(next_value, VALUES(next_value))",
                $cedula, $sucursal, $terminal, $doctype, $next
            ));
            if ($result === false) {
                $errors[] = sprintf('UPSERT falló para %s: %s', $key, $wpdb->last_error);
            }
        }

        return [
            'groups' => count($maxes),
            'orders_scanned' => $orders_scanned,
            'errors' => $errors,
        ];
    }

    /**
     * Lectura de inspección (no atómica). Útil para CLI/debug.
     *
     * @return int|null Próximo valor que devolverá next_value(), o null si la fila no existe.
     */
    public static function peek_next_value($cedula_emisor, $sucursal, $terminal, $document_type) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $value = $wpdb->get_var($wpdb->prepare(
            "SELECT next_value FROM {$table}
             WHERE cedula_emisor = %s AND sucursal = %s AND terminal = %s AND document_type = %s",
            $cedula_emisor, $sucursal, $terminal, $document_type
        ));
        return $value === null ? null : (int) $value;
    }
}
