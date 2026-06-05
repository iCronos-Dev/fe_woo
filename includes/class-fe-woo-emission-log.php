<?php
/**
 * Emission log: una fila por cada consecutivo consumido.
 *
 * Permite detectar comprobantes huérfanos: emisiones que llegaron a Hacienda
 * pero cuya clave no está en `_fe_woo_factura_clave` del order WC. Esto pasa
 * cuando dos requests concurrentes para el mismo order_id queman dos
 * consecutivos pero solo uno gana la carrera de save().
 *
 * Cada `generate_consecutive` registra:
 *   - clave generada (lo único que persiste tras una colisión).
 *   - order_id y emisor en el momento de la generación.
 *   - status de Hacienda inicialmente NULL; se actualiza cuando el flujo termina.
 *
 * El CLI `wp fe-woo find-orphans` cruza este log con los meta de orders y
 * reporta entradas cuya clave no quedó asociada al order.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

class FE_Woo_Emission_Log {

    const TABLE_NAME = 'fe_woo_emission_log';

    public static function create_table() {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            clave varchar(50) NOT NULL,
            cedula_emisor varchar(20) NOT NULL,
            sucursal varchar(3) NOT NULL,
            terminal varchar(5) NOT NULL,
            document_type varchar(2) NOT NULL,
            consecutivo bigint(20) UNSIGNED NOT NULL,
            emitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            hacienda_status varchar(20) DEFAULT NULL,
            updated_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY idx_order_id (order_id),
            KEY idx_clave (clave),
            KEY idx_emisor_doctype (cedula_emisor, document_type),
            KEY idx_emitted_at (emitted_at)
        ) {$charset_collate};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
        return $exists;
    }

    /**
     * Registrar una emisión recién generada (clave + consecutivo).
     */
    public static function record_emission($order_id, $clave, $cedula_emisor, $sucursal, $terminal, $document_type, $consecutivo) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->insert($table, [
            'order_id'      => (int) $order_id,
            'clave'         => $clave,
            'cedula_emisor' => $cedula_emisor,
            'sucursal'      => $sucursal,
            'terminal'      => $terminal,
            'document_type' => $document_type,
            'consecutivo'   => (int) $consecutivo,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%d']);
    }

    /**
     * Actualizar el status final de Hacienda (aceptado / rechazado).
     */
    public static function update_status($clave, $hacienda_status) {
        global $wpdb;
        $table = $wpdb->prefix . self::TABLE_NAME;
        $wpdb->update(
            $table,
            ['hacienda_status' => substr($hacienda_status, 0, 20), 'updated_at' => current_time('mysql')],
            ['clave' => $clave],
            ['%s', '%s'],
            ['%s']
        );
    }

    /**
     * Encontrar comprobantes huérfanos: claves en el log que la orden no
     * referencia ni en `_fe_woo_factura_clave` (single) ni dentro del array
     * `_fe_woo_facturas_generated` (multi-emisor).
     *
     * El check de multi-emisor es crítico: si solo comparáramos contra
     * `_fe_woo_factura_clave` (que en multi guarda únicamente `first_clave`),
     * todas las claves de la 2da, 3ra, … factura aparecerían como huérfanas
     * — falsos positivos que llevarían a anular facturas válidas.
     *
     * Soporta filtro temporal opcional.
     *
     * @param string|null $since   YYYY-MM-DD (UTC) o null para todo el log.
     * @param int|null    $limit   Aplicado al resultado final post-filtro.
     * @return array Filas con [order_id, clave, cedula_emisor, document_type, consecutivo, emitted_at, hacienda_status, current_clave_in_order]
     */
    public static function find_orphans($since = null, $limit = null) {
        global $wpdb;
        $log_table = $wpdb->prefix . self::TABLE_NAME;
        $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
        $postmeta  = $wpdb->postmeta;

        $hpos_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta)) === $hpos_meta;

        $where = '1=1';
        $params = [];
        if ($since) {
            $where .= ' AND el.emitted_at >= %s';
            $params[] = $since;
        }

        // Subqueries para current clave del order, en HPOS y postmeta.
        $hpos_clave_sql = $hpos_exists
            ? "(SELECT om.meta_value FROM {$hpos_meta} om WHERE om.order_id = el.order_id AND om.meta_key = '_fe_woo_factura_clave' LIMIT 1)"
            : "NULL";
        $postmeta_clave_sql = "(SELECT pm.meta_value FROM {$postmeta} pm WHERE pm.post_id = el.order_id AND pm.meta_key = '_fe_woo_factura_clave' LIMIT 1)";

        // Primer pase SQL: candidatos cuya clave no está en _fe_woo_factura_clave.
        // Resultados de este pase incluyen falsos positivos en multi-emisor que
        // se filtran después en PHP contra _fe_woo_facturas_generated.
        $sql = "SELECT
                    el.order_id,
                    el.clave,
                    el.cedula_emisor,
                    el.document_type,
                    el.consecutivo,
                    el.emitted_at,
                    el.hacienda_status,
                    COALESCE({$hpos_clave_sql}, {$postmeta_clave_sql}) as current_clave_in_order
                FROM {$log_table} el
                WHERE {$where}
                HAVING current_clave_in_order IS NULL OR current_clave_in_order != el.clave
                ORDER BY el.emitted_at DESC";

        if (!empty($params)) {
            $sql = $wpdb->prepare($sql, ...$params);
        }

        $candidates = $wpdb->get_results($sql, ARRAY_A);
        if (empty($candidates)) {
            return [];
        }

        // Segundo pase: para cada order_id con candidatos, leer
        // _fe_woo_facturas_generated y juntar todas las claves del multi.
        // Si la clave del candidato está ahí, NO es huérfano.
        $order_ids = array_unique(array_map('intval', array_column($candidates, 'order_id')));
        $multi_claves_by_order = self::collect_multi_factura_claves($order_ids);

        $orphans = [];
        foreach ($candidates as $row) {
            $oid = (int) $row['order_id'];
            $clave = $row['clave'];
            if (isset($multi_claves_by_order[$oid]) && in_array($clave, $multi_claves_by_order[$oid], true)) {
                continue; // está en multi-factura array → no huérfano
            }
            $orphans[] = $row;
            if ($limit !== null && count($orphans) >= (int) $limit) {
                break;
            }
        }

        return $orphans;
    }

    /**
     * Lee `_fe_woo_facturas_generated` (HPOS + postmeta) para los order_ids
     * dados y devuelve mapa order_id → array de claves contenidas.
     *
     * @param int[] $order_ids
     * @return array<int, string[]>
     */
    private static function collect_multi_factura_claves(array $order_ids) {
        global $wpdb;
        if (empty($order_ids)) {
            return [];
        }
        $hpos_meta = $wpdb->prefix . 'wc_orders_meta';
        $postmeta  = $wpdb->postmeta;
        $hpos_exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $hpos_meta)) === $hpos_meta;

        $placeholders = implode(',', array_fill(0, count($order_ids), '%d'));
        $rows = [];

        if ($hpos_exists) {
            $sql = "SELECT order_id, meta_value FROM {$hpos_meta}
                    WHERE meta_key = '_fe_woo_facturas_generated' AND order_id IN ($placeholders)";
            $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare($sql, ...$order_ids), ARRAY_A));
        }
        $sql = "SELECT post_id AS order_id, meta_value FROM {$postmeta}
                WHERE meta_key = '_fe_woo_facturas_generated' AND post_id IN ($placeholders)";
        $rows = array_merge($rows, (array) $wpdb->get_results($wpdb->prepare($sql, ...$order_ids), ARRAY_A));

        $map = [];
        foreach ($rows as $r) {
            $oid = (int) $r['order_id'];
            $arr = maybe_unserialize($r['meta_value']);
            if (!is_array($arr)) {
                continue;
            }
            foreach ($arr as $factura) {
                if (!empty($factura['clave']) && is_string($factura['clave'])) {
                    $map[$oid][] = $factura['clave'];
                }
            }
        }
        return $map;
    }
}
