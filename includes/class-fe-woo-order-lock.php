<?php
/**
 * Order-level locks for serializing FE operations.
 *
 * Cierra la ventana de carrera entre dos operaciones que mutan el estado
 * fiscal del mismo order_id (Reintentar, Ejecutar, reexecute_invoice). Sin el
 * lock, dos requests concurrentes pueden:
 *   - Quemar 2 consecutivos del counter para una sola orden.
 *   - POSTear 2 documentos distintos a Hacienda con la misma intención.
 *   - Producir comprobantes huérfanos (válidos en Hacienda, sin order_id).
 *
 * El lock se adquiere ANTES de `clear_invoice_data` y se libera en el `finally`,
 * tanto en éxito como en excepción. La unicidad la garantiza una UNIQUE KEY
 * sobre (order_id) — `INSERT IGNORE` devuelve `affected_rows=1` solo cuando el
 * insert efectivamente creó la fila.
 *
 * Limpieza: cada `acquire` borra primero los locks expirados (TTL configurable,
 * default 120s). Esto evita que un proceso que crashea sin liberar deje la
 * orden bloqueada para siempre.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Order_Lock {

    const TABLE_NAME = 'fe_woo_order_locks';
    const DEFAULT_TTL = 120; // seconds

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            operation varchar(50) NOT NULL,
            locked_until datetime NOT NULL,
            locked_by varchar(64) DEFAULT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY idx_locked_until (locked_until)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        if ($exists) {
            $unique = $wpdb->get_var("SHOW INDEX FROM {$table} WHERE Key_name = 'unique_order_id'");
            if (!$unique) {
                $wpdb->query("ALTER TABLE {$table} ADD UNIQUE KEY unique_order_id (order_id)");
            }
        }
        return $exists;
    }

    /**
     * Intentar adquirir el lock atómicamente.
     *
     * @param int    $order_id
     * @param string $operation Identificador de la operación (debug only).
     * @param int    $ttl       Segundos antes de auto-expirar (default 120).
     * @return bool True si el lock fue adquirido.
     */
    public static function acquire($order_id, $operation = 'unknown', $ttl = self::DEFAULT_TTL) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;

        // Primero limpiar locks expirados — evita orden bloqueada permanente
        // si el proceso que la tomó crasheó antes de liberar.
        $wpdb->query("DELETE FROM {$table} WHERE locked_until < NOW()");

        $locked_until = gmdate('Y-m-d H:i:s', time() + (int) $ttl);
        $locked_by = self::request_signature();

        // INSERT IGNORE: si ya existe row para ese order_id (UNIQUE KEY violation),
        // no inserta y `rows_affected` queda en 0. Atomicidad la garantiza el
        // engine de MySQL al evaluar el UNIQUE.
        $inserted = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$table} (order_id, operation, locked_until, locked_by)
             VALUES (%d, %s, %s, %s)",
            $order_id, substr($operation, 0, 50), $locked_until, $locked_by
        ));

        return $inserted === 1;
    }

    /**
     * Liberar el lock. Idempotente.
     */
    public static function release($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->query($wpdb->prepare("DELETE FROM {$table} WHERE order_id = %d", $order_id));
    }

    /**
     * Inspección: ¿está la orden bloqueada AHORA?
     *
     * @return array|null ['operation' => ..., 'locked_until' => ..., 'remaining' => seconds] o null si libre.
     */
    public static function inspect($order_id) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT operation, locked_until, locked_by FROM {$table}
             WHERE order_id = %d AND locked_until > NOW()",
            $order_id
        ), ARRAY_A);
        if (!$row) {
            return null;
        }
        $remaining = strtotime($row['locked_until'] . ' UTC') - time();
        return [
            'operation'    => $row['operation'],
            'locked_until' => $row['locked_until'],
            'remaining'    => max(0, $remaining),
            'locked_by'    => $row['locked_by'],
        ];
    }

    /**
     * Helper para envolver una operación en un lock con cleanup garantizado.
     *
     * @param int      $order_id
     * @param string   $operation
     * @param callable $work Recibe nada, retorna lo que sea.
     * @param int      $ttl
     * @return mixed Retorno de $work.
     * @throws RuntimeException Si no se pudo adquirir el lock.
     */
    public static function with_lock($order_id, $operation, callable $work, $ttl = self::DEFAULT_TTL) {
        if (!self::acquire($order_id, $operation, $ttl)) {
            $existing = self::inspect($order_id);
            throw new RuntimeException(sprintf(
                'Orden #%d ya tiene una operación FE en proceso (%s, %d s restantes). Espera o recarga.',
                $order_id,
                $existing['operation'] ?? 'unknown',
                $existing['remaining'] ?? 0
            ));
        }
        try {
            return $work();
        } finally {
            self::release($order_id);
        }
    }

    /**
     * Firma corta de la request actual para auditoría (no es secreto).
     */
    private static function request_signature() {
        $bits = [];
        if (function_exists('get_current_user_id')) {
            $bits[] = 'u' . (int) get_current_user_id();
        }
        if (defined('WP_CLI') && WP_CLI) {
            $bits[] = 'cli';
        }
        $bits[] = 'p' . getmypid();
        return substr(implode(':', $bits), 0, 64);
    }
}
