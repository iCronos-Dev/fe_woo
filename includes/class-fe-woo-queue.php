<?php
/**
 * FE WooCommerce Queue Manager
 *
 * Manages the queue for processing electronic invoices (Facturas Electrónicas)
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Queue Class
 *
 * Handles queue operations for electronic invoice processing
 */
class FE_Woo_Queue {

    /**
     * Queue status constants
     */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRY = 'retry';

    /**
     * Table name
     */
    private static $table_name = 'fe_woo_factura_queue';

    /**
     * Initialize the queue
     */
    public static function init() {
        // Create database table on plugin activation
        register_activation_hook(FE_WOO_PLUGIN_DIR . 'fe_woo.php', [__CLASS__, 'create_queue_table']);

        // Hook into order status change
        add_action('woocommerce_order_status_completed', [__CLASS__, 'add_order_to_queue'], 10, 1);
        add_action('woocommerce_order_status_processing', [__CLASS__, 'add_order_to_queue'], 10, 1);

        // Hook into payment completion (fires when payment is received)
        add_action('woocommerce_payment_complete', [__CLASS__, 'add_order_to_queue'], 10, 1);

        // Hook for orders created with 'paid' status (e.g., manual orders, Cash on Delivery marked as paid)
        add_action('woocommerce_order_status_changed', [__CLASS__, 'maybe_add_paid_order_to_queue'], 10, 4);
    }

    /**
     * Create queue database table
     */
    public static function create_queue_table() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $charset_collate = $wpdb->get_charset_collate();

        // Note: dbDelta() is strict about formatting:
        // - No UNIQUE KEY (added separately to avoid dbDelta parsing bugs)
        // - No DEFAULT CURRENT_TIMESTAMP (not reliably supported by dbDelta)
        // - No CREATE TABLE IF NOT EXISTS (dbDelta doesn't support IF NOT EXISTS)
        $sql = "CREATE TABLE $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            order_id bigint(20) UNSIGNED NOT NULL,
            document_type varchar(20) NOT NULL DEFAULT 'tiquete',
            emisor_id bigint(20) UNSIGNED DEFAULT NULL,
            status varchar(20) NOT NULL DEFAULT 'pending',
            attempts int(11) NOT NULL DEFAULT 0,
            max_attempts int(11) NOT NULL DEFAULT 3,
            factura_data longtext DEFAULT NULL,
            xml_data longtext DEFAULT NULL,
            hacienda_response longtext DEFAULT NULL,
            clave varchar(50) DEFAULT NULL,
            referenced_clave varchar(50) DEFAULT NULL,
            reference_code varchar(10) DEFAULT NULL,
            error_message text DEFAULT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            processed_at datetime DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY order_id (order_id),
            KEY document_type (document_type),
            KEY emisor_id (emisor_id),
            KEY status (status),
            KEY clave (clave),
            KEY referenced_clave (referenced_clave),
            KEY created_at (created_at)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Add UNIQUE constraint separately (dbDelta can't reliably parse UNIQUE KEY)
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) === $table_name;
        if ($table_exists) {
            $index_exists = $wpdb->get_var(
                "SHOW INDEX FROM $table_name WHERE Key_name = 'idx_nota_unique'"
            );
            if (!$index_exists) {
                $wpdb->query("ALTER TABLE $table_name ADD UNIQUE KEY idx_nota_unique (order_id, document_type, referenced_clave, reference_code)");
            }
        }
    }

    /**
     * Add order to queue when order is completed or processing
     *
     * @param int $order_id Order ID
     */
    public static function add_order_to_queue($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Check if already in queue
        if (self::order_exists_in_queue($order_id)) {
            return; // Already in queue
        }

        // Allow filtering whether to add order to queue (used by proforma status)
        $should_add = apply_filters('fe_woo_should_add_order_to_queue', true, $order);
        if (!$should_add) {
            // Log
            if (FE_Woo_Hacienda_Config::is_debug_enabled() && function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info(
                    sprintf('Order #%d excluded from queue (status: %s)', $order_id, $order->get_status()),
                    ['source' => 'fe-woo-queue']
                );
            }
            return;
        }

        // Determine document type based on checkbox
        $require_factura = $order->get_meta('_fe_woo_require_factura');
        $document_type = ($require_factura === 'yes') ? 'factura' : 'tiquete';

        // Add to queue (all orders get either tiquete or factura)
        self::add_to_queue($order_id, null, $document_type);

        // Log
        if (FE_Woo_Hacienda_Config::is_debug_enabled() && function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info(
                sprintf('Order #%d added to %s queue', $order_id, $document_type),
                ['source' => 'fe-woo-queue']
            );
        }
    }

    /**
     * Maybe add order to queue when status changes to a paid status
     *
     * @param int    $order_id   Order ID
     * @param string $old_status Old order status
     * @param string $new_status New order status
     * @param WC_Order $order    Order object
     */
    public static function maybe_add_paid_order_to_queue($order_id, $old_status, $new_status, $order) {
        // Only add to queue if transitioning to a paid status
        $paid_statuses = ['processing', 'completed', 'on-hold'];

        if (!in_array($new_status, $paid_statuses, true)) {
            return;
        }

        // Check if order is actually paid
        if (!$order->is_paid()) {
            return;
        }

        // Use the existing add_order_to_queue method
        self::add_order_to_queue($order_id);
    }

    /**
     * Check if order exists in queue
     *
     * @param int $order_id Order ID
     * @return bool True if exists
     */
    public static function order_exists_in_queue($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE order_id = %d",
            $order_id
        ));

        return $count > 0;
    }

    /**
     * Add order to queue
     *
     * @param int    $order_id Order ID
     * @param array  $factura_data Optional factura data
     * @param string $document_type Document type (tiquete or factura)
     * @return int|false Queue item ID or false on failure
     */
    public static function add_to_queue($order_id, $factura_data = null, $document_type = 'tiquete') {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $data = [
            'order_id' => $order_id,
            'document_type' => $document_type,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'factura_data' => $factura_data ? wp_json_encode($factura_data) : null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $result = $wpdb->insert($table_name, $data);

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Get pending queue items.
     *
     * Applies a post-SELECT filter (`fe_woo_pending_items`) before returning
     * so external integrations can hold back items without modifying the
     * SQL — the parent theme uses this to exclude orders containing products
     * flagged with the per-event "Pausar FE" meta.
     *
     * @param int $limit Number of items to retrieve.
     * @return array Queue items.
     */
    public static function get_pending_items($limit = 10) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE status IN (%s, %s)
            AND attempts < max_attempts
            ORDER BY created_at ASC
            LIMIT %d",
            self::STATUS_PENDING,
            self::STATUS_RETRY,
            $limit
        ));

        /**
         * Filter the queue items returned by get_pending_items().
         *
         * Default: identity passthrough. External integrations (e.g. the
         * parent theme's per-event FE pause) may filter out items here so
         * the cron processor never picks them up. The filter runs AFTER
         * the SQL SELECT (post-filter), keeping the query simple and
         * decoupled from the consumer's schema.
         *
         * Callers that need an UNFILTERED view (e.g. a manual batch that
         * deliberately processes held-back items) should query a different
         * entry point — see `get_pending_items_for_product()`.
         *
         * @param array $items Rows from wp_fe_woo_factura_queue.
         * @param int   $limit Original limit requested.
         */
        $items = apply_filters('fe_woo_pending_items', $items, $limit);

        // Harden against malformed filter returns: drop anything that isn't
        // an object carrying at least `id` and `order_id`, since downstream
        // process_item() addresses these fields directly.
        if (!is_array($items)) {
            return [];
        }
        $items = array_filter($items, function ($i) {
            return is_object($i) && isset($i->id, $i->order_id);
        });
        return array_values($items);
    }

    /**
     * Get pending queue items whose order contains a given product.
     *
     * Used by manual batch processors (e.g. the theme's "Ejecutar Facturas"
     * button per event) to fetch items the cron is intentionally holding
     * back via the `fe_woo_pending_items` filter. Returns items in
     * `pending`/`retry` whose `order_id` references an order with at least
     * one line item resolving to the given product or any of its
     * variations.
     *
     * Does NOT apply the `fe_woo_pending_items` filter — this is the
     * explicit escape hatch by design.
     *
     * @param int $product_id Product ID (parent or simple).
     * @param int $limit      Max items to return (default 10).
     * @return array Queue items.
     */
    public static function get_pending_items_for_product($product_id, $limit = 10) {
        $product_id = (int) $product_id;
        $limit      = (int) $limit;
        if ($product_id <= 0 || $limit <= 0) {
            return [];
        }

        global $wpdb;

        // Build the candidate product ID set: the product itself plus any
        // variations it owns. We don't recurse into grouped children because
        // FE queue rows reference the order, and order line items already
        // carry the bought variation ID directly.
        $candidate_ids = [$product_id];
        $variations    = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product_variation'
               AND post_status IN ('publish','private','draft','pending')
               AND post_parent = %d",
            $product_id
        ));
        foreach ((array) $variations as $vid) {
            $vid = (int) $vid;
            if ($vid > 0) {
                $candidate_ids[] = $vid;
            }
        }
        $candidate_ids = array_values(array_unique($candidate_ids));
        $placeholders  = implode(',', array_fill(0, count($candidate_ids), '%d'));

        $table       = $wpdb->prefix . self::$table_name;
        $items_table = $wpdb->prefix . 'woocommerce_order_items';
        $imeta_table = $wpdb->prefix . 'woocommerce_order_itemmeta';

        // Order item meta key '_product_id' is the parent for variable products;
        // '_variation_id' is the chosen variation. Either match is a hit.
        //
        // We use EXISTS instead of INNER JOIN + DISTINCT so the inner scan
        // short-circuits on the first matching itemmeta row per order, and we
        // avoid materialising a wide DISTINCT result set over the queue's
        // longtext columns (factura_data/xml_data/hacienda_response) which
        // would spill to a temp table at scale.
        $sql = "SELECT q.* FROM $table q
                WHERE q.status IN (%s, %s)
                  AND q.attempts < q.max_attempts
                  AND EXISTS (
                      SELECT 1
                      FROM $items_table oi
                      INNER JOIN $imeta_table oim
                              ON oim.order_item_id = oi.order_item_id
                      WHERE oi.order_id = q.order_id
                        AND oim.meta_key IN ('_product_id', '_variation_id')
                        AND oim.meta_value IN ($placeholders)
                      LIMIT 1
                  )
                ORDER BY q.created_at ASC
                LIMIT %d";

        $args = array_merge(
            [self::STATUS_PENDING, self::STATUS_RETRY],
            array_map('intval', $candidate_ids),
            [$limit]
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $items = $wpdb->get_results($wpdb->prepare($sql, ...$args));

        return is_array($items) ? $items : [];
    }

    /**
     * Update queue item status
     *
     * @param int    $queue_id Queue item ID
     * @param string $status New status
     * @param array  $data Additional data to update
     * @return bool True on success
     */
    public static function update_status($queue_id, $status, $data = []) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $update_data = array_merge([
            'status' => $status,
            'updated_at' => current_time('mysql'),
        ], $data);

        if ($status === self::STATUS_COMPLETED) {
            $update_data['processed_at'] = current_time('mysql');
        }

        $result = $wpdb->update(
            $table_name,
            $update_data,
            ['id' => $queue_id]
        );

        return $result !== false;
    }

    /**
     * Mark item as processing
     *
     * @param int $queue_id Queue item ID
     * @return bool True on success
     */
    public static function mark_processing($queue_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // Single raw query: $wpdb->update() no soporta expresiones SQL como
        // "attempts + 1", así que antes hacíamos 2 queries (T-7 v1.19.0 fold-in).
        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table_name
             SET status = %s,
                 attempts = attempts + 1,
                 updated_at = %s
             WHERE id = %d",
            self::STATUS_PROCESSING,
            current_time('mysql'),
            $queue_id
        ));

        return $result !== false;
    }

    /**
     * Mark item as completed
     *
     * @param int    $queue_id Queue item ID
     * @param string $clave Factura clave
     * @param string $xml_data XML data
     * @param array  $hacienda_response Hacienda API response
     * @return bool True on success
     */
    public static function mark_completed($queue_id, $clave, $xml_data, $hacienda_response) {
        return self::update_status($queue_id, self::STATUS_COMPLETED, [
            'clave' => $clave,
            'xml_data' => $xml_data,
            'hacienda_response' => wp_json_encode($hacienda_response),
            'error_message' => '',
            'processed_at' => current_time('mysql'),
        ]);
    }

    /**
     * Mark item as failed
     *
     * @param int    $queue_id Queue item ID
     * @param string $error_message Error message
     * @param bool   $retry Whether to retry
     * @return bool True on success
     */
    public static function mark_failed($queue_id, $error_message, $retry = true) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // Get current attempts
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT attempts, max_attempts FROM $table_name WHERE id = %d",
            $queue_id
        ));

        // Determine status based on attempts
        $status = self::STATUS_FAILED;
        if ($retry && $item && $item->attempts < $item->max_attempts) {
            $status = self::STATUS_RETRY;
        }

        return self::update_status($queue_id, $status, [
            'error_message' => $error_message,
        ]);
    }

    /**
     * Mark a queue item as "keep retrying until Hacienda accepts".
     *
     * Used when Hacienda itself rejected the document or hasn't issued a
     * verdict yet. Unlike mark_failed, this resets the attempts counter
     * so the queue never drops the row into STATUS_FAILED for something
     * that's expected to be resolved by a config fix (wrong actividad,
     * wrong ubicación, expired token, etc.). The operator's data fix is
     * what moves the item forward; the queue just keeps handing it to the
     * processor on each cron tick.
     *
     * @param int    $queue_id
     * @param string $error_message
     * @return bool
     */
    public static function mark_pending_hacienda($queue_id, $error_message) {
        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->update(
            $table_name,
            [
                'status'        => self::STATUS_RETRY,
                'attempts'      => 0,
                'error_message' => $error_message,
                'updated_at'    => current_time('mysql'),
            ],
            ['id' => $queue_id]
        ) !== false;
    }

    /**
     * Get queue item by ID
     *
     * @param int $queue_id Queue item ID
     * @return object|null Queue item or null
     */
    public static function get_item($queue_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $queue_id
        ));
    }

    /**
     * Get queue item by order ID
     *
     * @param int $order_id Order ID
     * @return object|null Queue item or null
     */
    public static function get_item_by_order($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at DESC LIMIT 1",
            $order_id
        ));
    }

    /**
     * Remove order from queue
     *
     * @param int $order_id Order ID
     * @return bool True on success
     */
    public static function remove_from_queue($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->delete(
            $table_name,
            ['order_id' => $order_id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Get queue statistics
     *
     * @return array Statistics
     */
    public static function get_stats() {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $stats = [
            'pending' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'retry' => 0,
            'total' => 0,
        ];

        $results = $wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM $table_name GROUP BY status"
        );

        foreach ($results as $row) {
            $stats[$row->status] = (int) $row->count;
            $stats['total'] += (int) $row->count;
        }

        return $stats;
    }

    /**
     * Add a nota (credit/debit note) to queue
     *
     * @param int    $order_id      Order ID
     * @param array  $nota_data     Nota data (note_type, referenced_clave, reference_code, reason, emisor_id, etc.)
     * @param string $document_type Document type ('nota_credito' or 'nota_debito')
     * @param int    $emisor_id     Emisor ID
     * @return int|false Queue item ID or false on failure
     */
    public static function add_nota_to_queue($order_id, $nota_data, $document_type = 'nota_credito', $emisor_id = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        // Idempotency: check if a nota with same referenced_clave and type is already queued
        $referenced_clave = isset($nota_data['referenced_clave']) ? $nota_data['referenced_clave'] : '';
        if (!empty($referenced_clave) && self::nota_exists_in_queue($order_id, $referenced_clave, isset($nota_data['reference_code']) ? $nota_data['reference_code'] : null)) {
            return false;
        }

        $reference_code = isset($nota_data['reference_code']) ? $nota_data['reference_code'] : null;

        $data = [
            'order_id' => $order_id,
            'document_type' => $document_type,
            'emisor_id' => $emisor_id,
            'referenced_clave' => !empty($referenced_clave) ? $referenced_clave : null,
            'reference_code' => $reference_code,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
            'factura_data' => wp_json_encode($nota_data),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Use INSERT IGNORE for atomic idempotency via UNIQUE constraint
        $result = $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO $table_name (order_id, document_type, emisor_id, referenced_clave, reference_code, status, attempts, factura_data, created_at, updated_at)
            VALUES (%d, %s, %d, %s, %s, %s, %d, %s, %s, %s)",
            $data['order_id'],
            $data['document_type'],
            $data['emisor_id'] ? $data['emisor_id'] : 0,
            $data['referenced_clave'],
            $data['reference_code'],
            $data['status'],
            $data['attempts'],
            $data['factura_data'],
            $data['created_at'],
            $data['updated_at']
        ));

        return $result ? $wpdb->insert_id : false;
    }

    /**
     * Check if a nota already exists in the queue for a specific factura clave
     *
     * @param int    $order_id         Order ID
     * @param string $referenced_clave Clave of the referenced factura
     * @param string $reference_code   Optional reference code filter (e.g. '01')
     * @return bool True if exists in queue (not failed)
     */
    public static function nota_exists_in_queue($order_id, $referenced_clave, $reference_code = null) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        if ($reference_code !== null) {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name
                WHERE order_id = %d
                AND document_type IN ('nota_credito', 'nota_debito')
                AND referenced_clave = %s
                AND reference_code = %s
                AND status NOT IN (%s)",
                $order_id,
                $referenced_clave,
                $reference_code,
                self::STATUS_FAILED
            );
        } else {
            $query = $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name
                WHERE order_id = %d
                AND document_type IN ('nota_credito', 'nota_debito')
                AND referenced_clave = %s
                AND status NOT IN (%s)",
                $order_id,
                $referenced_clave,
                self::STATUS_FAILED
            );
        }

        $count = $wpdb->get_var($query);

        return $count > 0;
    }

    /**
     * Get all queue items for an order (including notas)
     *
     * @param int $order_id Order ID
     * @return array Queue items
     */
    public static function get_items_by_order($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE order_id = %d ORDER BY created_at ASC",
            $order_id
        ));
    }

    /**
     * Get pending/processing nota queue items for an order
     *
     * Returns nota items that are still in queue (pending, processing, or retry).
     * Each item includes parsed factura_data for easy access to referenced_clave, reason, etc.
     *
     * @param int $order_id Order ID
     * @return array Array of queue item objects with additional 'nota_data' property
     */
    public static function get_queued_notas_for_order($order_id) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name
            WHERE order_id = %d
            AND document_type IN ('nota_credito', 'nota_debito')
            AND status IN (%s, %s, %s)
            ORDER BY created_at ASC",
            $order_id,
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_RETRY
        ));

        // Parse factura_data JSON for each item
        foreach ($items as &$item) {
            $item->nota_data = !empty($item->factura_data) ? json_decode($item->factura_data, true) : [];
        }

        return $items;
    }

    /**
     * Recover items varados en STATUS_PROCESSING.
     *
     * Cuando un cron tick se interrumpe (timeout de Pantheon, redeploy,
     * fatal PHP), los items marcados `processing` por mark_processing()
     * al inicio de process_item() no vuelven solos a `retry`. El transient
     * lock fe_woo_queue_processing expira a los 5 min, pero los items
     * quedan bloqueados indefinidamente, sin reintento.
     *
     * Este método se invoca al inicio de process_queue() para detectar
     * y resetear esos items.
     *
     * @param int $threshold_minutes Items en processing por más tiempo se resetean.
     * @return int Cantidad de items recuperados.
     */
    public static function recover_stale_processing_items($threshold_minutes = 10) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;
        $threshold = gmdate('Y-m-d H:i:s', time() - ($threshold_minutes * 60));

        $result = $wpdb->query($wpdb->prepare(
            "UPDATE $table_name
             SET status = %s, updated_at = NOW()
             WHERE status = %s AND updated_at < %s",
            self::STATUS_RETRY,
            self::STATUS_PROCESSING,
            $threshold
        ));

        return (int) $result;
    }

    /**
     * Cron-driven health check para detectar y notificar problemas operativos.
     *
     * Acciones:
     *  1. Recover stale items varados en `processing` (>{$stale_threshold} min).
     *  2. Contar items en `failed` con attempts > {$max_attempts}.
     *  3. Si hay problemas → log + email al admin (rate-limited: 1x/día).
     *
     * Diseñado para correr como cron horario. Reusa `recover_stale_processing_items`
     * (no es destructivo: solo cambia processing→retry para que el cron principal
     * los reintente).
     *
     * @return array Resumen del checkeo: ['recovered', 'failed_permanent', 'notified'].
     */
    public static function health_check() {
        $recovered = self::recover_stale_processing_items(10);

        global $wpdb;
        $table_name = $wpdb->prefix . self::$table_name;
        // Match schema default (3 attempts). Antes estaba hardcoded en 5,
        // por lo que ningún item realmente fallido (max 3 intentos) era
        // contado y el email diario al admin nunca se disparaba aunque la
        // cola tuviera items varados. Si en el futuro se cambia el schema
        // default, actualizar este valor o migrar a per-row
        // `attempts >= max_attempts`.
        $max_attempts = 3;
        $failed_permanent = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE status = %s AND attempts >= %d",
            self::STATUS_FAILED,
            $max_attempts
        ));

        $notified = false;
        if ($recovered > 0 || $failed_permanent > 0) {
            $lock = 'fe_woo_queue_health_email_lock';
            if (!get_transient($lock)) {
                $admin_email = get_option('admin_email');
                if ($admin_email) {
                    $subject = sprintf(
                        '[%s] Cola de Factura Electrónica requiere atención',
                        wp_specialchars_decode(get_option('blogname'), ENT_QUOTES)
                    );
                    $body = "Resumen del health check:\n\n";
                    if ($recovered > 0) {
                        $body .= "- $recovered items recuperados de estado 'processing' (>10 min) → reagendados.\n";
                    }
                    if ($failed_permanent > 0) {
                        $body .= "- $failed_permanent items en 'failed' con $max_attempts+ intentos (no se reintentarán automáticamente). Usa `wp fe-woo unblock_failed` para reactivar transitorios.\n";
                    }
                    $body .= "\nRevisa: " . admin_url('admin.php?page=wc-settings&tab=fe') . "\n";
                    wp_mail($admin_email, $subject, $body);
                    set_transient($lock, time(), DAY_IN_SECONDS);
                    $notified = true;
                }
            }
            if (function_exists('wc_get_logger')) {
                wc_get_logger()->warning(
                    sprintf('Queue health check: %d recovered, %d failed-permanent', $recovered, $failed_permanent),
                    ['source' => 'fe-woo-queue']
                );
            }
        }

        return [
            'recovered'        => $recovered,
            'failed_permanent' => $failed_permanent,
            'notified'         => $notified,
        ];
    }

    /**
     * Clean old completed items
     *
     * @param int $days Number of days to keep
     * @return int Number of items deleted
     */
    public static function clean_old_items($days = 30) {
        global $wpdb;

        $table_name = $wpdb->prefix . self::$table_name;

        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $table_name
            WHERE status = %s
            AND processed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            self::STATUS_COMPLETED,
            $days
        ));

        return $result;
    }
}
