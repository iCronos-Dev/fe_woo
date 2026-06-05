<?php
/**
 * WP-CLI Commands for FE Woo
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE Woo WP-CLI Commands
 */
class FE_Woo_CLI {

    /**
     * Migrate current emisor configuration to multi-emisor system
     *
     * ## EXAMPLES
     *
     *     wp fe-woo migrate-emisor
     *     wp fe-woo migrate-emisor --dry-run
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be migrated without making changes
     *
     * @when after_wp_load
     */
    public function migrate_emisor($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);

        WP_CLI::line('');
        WP_CLI::line('==============================================');
        WP_CLI::line('  FE WOO - MIGRACIÓN A SISTEMA MULTI-EMISOR  ');
        WP_CLI::line('==============================================');
        WP_CLI::line('');

        if ($dry_run) {
            WP_CLI::warning('MODO DRY-RUN: No se realizarán cambios');
            WP_CLI::line('');
        }

        // Check if migration already done
        $existing_parent = FE_Woo_Emisor_Manager::get_parent_emisor();
        if ($existing_parent && !$dry_run) {
            WP_CLI::error('Ya existe un emisor padre. La migración ya fue completada.');
            WP_CLI::line('  Emisor: ' . $existing_parent->nombre_legal);
            WP_CLI::line('  Cédula: ' . $existing_parent->cedula_juridica);
            return;
        }

        // Get current configuration
        WP_CLI::line('📋 Configuración actual:');
        WP_CLI::line('');

        $config = FE_Woo_Hacienda_Config::get_all_config();
        $location = FE_Woo_Hacienda_Config::get_location_codes();

        WP_CLI::line('  Empresa: ' . ($config['company_name'] ?: '(vacío)'));
        WP_CLI::line('  Cédula: ' . ($config['cedula_juridica'] ?: '(vacío)'));
        WP_CLI::line('  Actividad Económica: ' . ($config['economic_activity'] ?: '(vacío)'));
        WP_CLI::line('  Email: ' . ($config['email'] ?: '(vacío)'));
        WP_CLI::line('  Teléfono: ' . ($config['phone'] ?: '(vacío)'));
        WP_CLI::line('  Ubicación: Prov=' . $location['province'] . ' Cant=' . $location['canton'] . ' Dist=' . $location['district']);
        WP_CLI::line('  Certificado: ' . ($config['certificate_path'] && file_exists($config['certificate_path']) ? '✓ Presente' : '✗ No encontrado'));
        WP_CLI::line('');

        // Validate configuration
        $validation = FE_Woo_Hacienda_Config::validate_configuration();
        if (!empty($validation)) {
            WP_CLI::warning('⚠ La configuración actual tiene errores:');
            foreach ($validation as $error) {
                WP_CLI::line('  - ' . $error);
            }
            WP_CLI::line('');

            if (!$dry_run && !WP_CLI\Utils\get_flag_value($assoc_args, 'force', false)) {
                WP_CLI::error('La configuración está incompleta. Use --force para migrar de todos modos.');
                return;
            }
        } else {
            WP_CLI::success('✓ Configuración válida');
            WP_CLI::line('');
        }

        if ($dry_run) {
            WP_CLI::line('📝 Se crearía el siguiente emisor padre:');
            WP_CLI::line('');
            WP_CLI::line('  Nombre Legal: ' . $config['company_name']);
            WP_CLI::line('  Cédula Jurídica: ' . $config['cedula_juridica']);
            WP_CLI::line('  Actividad Económica: ' . $config['economic_activity']);
            WP_CLI::line('  Email: ' . $config['email']);
            WP_CLI::line('  Teléfono: ' . $config['phone']);
            WP_CLI::line('  Dirección: ' . $config['address']);
            WP_CLI::line('');
            WP_CLI::success('DRY-RUN completado. Use el comando sin --dry-run para ejecutar la migración.');
            return;
        }

        // Execute migration
        WP_CLI::line('🚀 Ejecutando migración...');
        WP_CLI::line('');

        $result = FE_Woo_Emisor_Manager::migrate_current_emisor_to_parent();

        if ($result['success']) {
            WP_CLI::success('✓ Migración completada exitosamente');
            WP_CLI::line('');
            WP_CLI::line('  Emisor Padre ID: ' . $result['emisor_id']);
            WP_CLI::line('  Fecha: ' . current_time('mysql'));
            WP_CLI::line('');
            WP_CLI::success('El sistema ahora está configurado para multi-emisor');
        } else {
            WP_CLI::error('Error en la migración:');
            if (isset($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    WP_CLI::line('  - ' . $error);
                }
            }
        }
    }

    /**
     * List all emisores
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fe-woo list_emisores
     *     wp fe-woo list_emisores --format=json
     *
     * @when after_wp_load
     */
    public function list_emisores($args, $assoc_args) {
        $emisores = FE_Woo_Emisor_Manager::get_all_emisores(false);

        if (empty($emisores)) {
            WP_CLI::warning('No hay emisores configurados');
            return;
        }

        $format = \WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

        $items = [];
        foreach ($emisores as $emisor) {
            $items[] = [
                'ID' => $emisor->id,
                'Tipo' => $emisor->is_parent ? '⭐ PADRE' : 'Hijo',
                'Nombre' => $emisor->nombre_legal,
                'Cédula' => $emisor->cedula_juridica,
                'Email' => $emisor->email,
                'Estado' => $emisor->active ? 'Activo' : 'Inactivo',
            ];
        }

        \WP_CLI\Utils\format_items($format, $items, ['ID', 'Tipo', 'Nombre', 'Cédula', 'Email', 'Estado']);
    }

    /**
     * Show system status
     *
     * ## EXAMPLES
     *
     *     wp fe-woo status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        WP_CLI::line('');
        WP_CLI::line('==============================================');
        WP_CLI::line('  FE WOO - ESTADO DEL SISTEMA                ');
        WP_CLI::line('==============================================');
        WP_CLI::line('');

        // Check database tables
        global $wpdb;
        $emisores_table = $wpdb->prefix . 'fe_woo_emisores';
        $queue_table = $wpdb->prefix . 'fe_woo_factura_queue';

        $tables_exist = [
            'emisores' => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $emisores_table)) === $emisores_table,
            'queue' => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) === $queue_table,
        ];

        WP_CLI::line('📊 Tablas de Base de Datos:');
        WP_CLI::line('  Emisores: ' . ($tables_exist['emisores'] ? '✓ Existe' : '✗ No existe'));
        WP_CLI::line('  Cola: ' . ($tables_exist['queue'] ? '✓ Existe' : '✗ No existe'));
        WP_CLI::line('');

        // Check emisores
        if ($tables_exist['emisores']) {
            $emisores_count = count(FE_Woo_Emisor_Manager::get_all_emisores(true));
            $parent_emisor = FE_Woo_Emisor_Manager::get_parent_emisor();

            WP_CLI::line('👥 Emisores:');
            WP_CLI::line('  Total activos: ' . $emisores_count);
            WP_CLI::line('  Emisor padre: ' . ($parent_emisor ? '✓ Configurado (' . $parent_emisor->nombre_legal . ')' : '✗ No configurado'));
            WP_CLI::line('');
        }

        // Check service charge products
        $service_charge_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_fe_woo_is_service_charge',
                    'value' => 'yes',
                ],
            ],
        ]);

        WP_CLI::line('💰 Productos Cargo por Servicio:');
        if (!empty($service_charge_products)) {
            foreach ($service_charge_products as $product_post) {
                $product = wc_get_product($product_post->ID);
                if ($product) {
                    WP_CLI::line('  ✓ ' . $product->get_name() . ' (ID: ' . $product_post->ID . ')');
                }
            }
        } else {
            WP_CLI::line('  ✗ Ningún producto marcado como cargo por servicio');
        }
        WP_CLI::line('');

        // Check queue
        if ($tables_exist['queue']) {
            $pending = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'pending'));
            $processing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'processing'));
            $failed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'failed'));

            WP_CLI::line('📋 Cola de Procesamiento:');
            WP_CLI::line('  Pendientes: ' . $pending);
            WP_CLI::line('  Procesando: ' . $processing);
            WP_CLI::line('  Fallidos: ' . $failed);
            WP_CLI::line('');
        }

        // Check configuration
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        WP_CLI::line('⚙️  Estado de Configuración:');
        if ($ready_status['ready']) {
            WP_CLI::success('✓ ' . $ready_status['message']);
        } else {
            WP_CLI::warning('⚠ ' . $ready_status['message']);
        }
        WP_CLI::line('');
    }

    /**
     * Generate, sign, and validate an invoice XML locally without sending it to Hacienda.
     *
     * ## OPTIONS
     *
     * --order=<id>
     * : WooCommerce order ID.
     *
     * [--type=<doc>]
     * : Document type: factura, tiquete, nota_credito, nota_debito. Default: factura.
     *
     * [--emisor=<id>]
     * : Emisor ID. Default: order's mapped emisor or parent emisor.
     *
     * ## EXAMPLES
     *
     *     wp fe-woo sign_test --order=1504
     *     wp fe-woo sign_test --order=1504 --type=tiquete
     *     wp fe-woo sign_test --order=1504 --force-production
     *
     * @when after_wp_load
     */
    public function sign_test($args, $assoc_args) {
        // sign_test writes a real signed legal invoice to disk. Refuse to run
        // on production unless the operator explicitly opts in, otherwise
        // a stray CLI on a live container creates fiscal artefacts.
        $is_prod = defined('WP_ENV') && in_array(WP_ENV, ['production', 'live'], true);
        if ($is_prod && empty($assoc_args['force-production'])) {
            WP_CLI::error('sign_test rechazado en producción. Usa --force-production si es intencional.');
        }

        $order_id = isset($assoc_args['order']) ? (int) $assoc_args['order'] : 0;
        if ($order_id <= 0) {
            WP_CLI::error('Debes pasar --order=<id>.');
        }
        $type = isset($assoc_args['type']) ? (string) $assoc_args['type'] : 'factura';
        $emisor_id = isset($assoc_args['emisor']) ? (int) $assoc_args['emisor'] : null;

        $order = wc_get_order($order_id);
        if (!$order) {
            WP_CLI::error('Orden no encontrada: ' . $order_id);
        }

        $emisor = null;
        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        }
        if (!$emisor) {
            $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
        }
        if (!$emisor) {
            WP_CLI::error('No hay emisor configurado.');
        }
        if (empty($emisor->certificate_path) || empty($emisor->certificate_pin)) {
            WP_CLI::error(sprintf('El emisor "%s" no tiene certificado o PIN configurado.', $emisor->nombre_legal));
        }

        WP_CLI::line("Orden #{$order_id} | Tipo: {$type} | Emisor: {$emisor->nombre_legal}");

        $result = FE_Woo_Factura_Generator::generate_from_order($order, $type, (int) $emisor->id);
        if (!$result['success']) {
            WP_CLI::error('Error al generar XML: ' . ($result['error'] ?? 'desconocido'));
        }
        $unsigned = $result['xml'];
        $clave = $result['clave'];

        // wp_tempnam creates a unique, 0600-able file inside the WP upload
        // private dir (not /tmp), so concurrent runs don't collide and the
        // output isn't world-readable on shared hosts. Shutdown cleanup.
        $unsigned_path = wp_tempnam('fe_woo_unsigned_');
        file_put_contents($unsigned_path, $unsigned);
        @chmod($unsigned_path, 0600);
        WP_CLI::line("  Unsigned XML: {$unsigned_path} (" . strlen($unsigned) . ' bytes)');

        try {
            $signed = FE_Woo_XML_Signer::sign($unsigned, $emisor->certificate_path, $emisor->certificate_pin);
        } catch (Exception $e) {
            WP_CLI::error('Error al firmar: ' . $e->getMessage());
        }
        $signed_path = wp_tempnam('fe_woo_signed_');
        file_put_contents($signed_path, $signed);
        @chmod($signed_path, 0600);
        WP_CLI::line("  Signed XML:   {$signed_path} (" . strlen($signed) . ' bytes)');

        register_shutdown_function(function () use ($unsigned_path, $signed_path) {
            @unlink($unsigned_path);
            @unlink($signed_path);
        });
        WP_CLI::line("  Clave:        {$clave}");

        $validation = FE_Woo_XML_Validator::validate($signed);
        if ($validation['valid']) {
            WP_CLI::success('VALID — el XML firmado pasa el XSD v4.4.');
        } else {
            WP_CLI::warning('Validación XSD falló (' . count($validation['errors']) . ' error(es)):');
            foreach (array_slice($validation['errors'], 0, 5) as $i => $err) {
                WP_CLI::line("    [{$i}] " . substr($err, 0, 300));
            }
        }
    }

    /**
     * Bulk re-execute facturas: purge queue, wipe existing invoices and re-queue all matching orders.
     *
     * DESTRUCTIVE operation. Deletes every row in wp_fe_woo_factura_queue, then for each matching
     * order deletes the stored XML/PDF/acuse files, clears invoice metadata and inserts a fresh
     * pending entry in the queue. The next queue processor run (cron or `wp fe-woo status` /
     * manual execution) will regenerate everything from scratch.
     *
     * ## OPTIONS
     *
     * [--status=<csv>]
     * : Comma-separated WooCommerce order statuses to target. Default: completed.
     *
     * [--batch-size=<n>]
     * : How many orders to process per page. Default: 50.
     *
     * [--dry-run]
     * : Print what would happen without changing anything.
     *
     * [--yes]
     * : Skip the confirmation prompt (for automation).
     *
     * [--force-production]
     * : Required to run on production/live environments.
     *
     * ## EXAMPLES
     *
     *     wp fe-woo reexecute_all --dry-run
     *     wp fe-woo reexecute_all --yes
     *     wp fe-woo reexecute_all --status=completed,processing --batch-size=100 --yes
     *
     * @when after_wp_load
     */
    public function reexecute_all($args, $assoc_args) {
        global $wpdb;

        $dry_run = isset($assoc_args['dry-run']);
        $statuses_csv = (string) \WP_CLI\Utils\get_flag_value($assoc_args, 'status', 'completed');
        $statuses = array_values(array_filter(array_map('trim', explode(',', $statuses_csv))));
        $batch_size = max(1, (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'batch-size', 50));

        if (empty($statuses)) {
            WP_CLI::error('Debes pasar al menos un status en --status.');
        }

        $is_prod = defined('WP_ENV') && in_array(WP_ENV, ['production', 'live'], true);
        if ($is_prod && !$dry_run && empty($assoc_args['force-production'])) {
            WP_CLI::error('reexecute_all está bloqueado en producción. Usa --force-production si realmente quieres hacer un reset masivo.');
        }

        // Count target orders (paginate=true returns {total, orders, ...}).
        $count_query = wc_get_orders([
            'status'   => $statuses,
            'type'     => 'shop_order',
            'limit'    => 1,
            'paginate' => true,
            'return'   => 'ids',
        ]);
        $total_orders = (int) $count_query->total;

        $queue_table = $wpdb->prefix . 'fe_woo_factura_queue';
        $queue_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$queue_table}");

        WP_CLI::line('');
        WP_CLI::line('==============================================');
        WP_CLI::line('  FE WOO - RE-EJECUCIÓN MASIVA               ');
        WP_CLI::line('==============================================');
        WP_CLI::line('');
        WP_CLI::line('  Entorno: ' . (defined('WP_ENV') ? WP_ENV : 'desconocido'));
        WP_CLI::line('  Status objetivo: ' . implode(', ', $statuses));
        WP_CLI::line('  Órdenes a re-encolar: ' . number_format_i18n($total_orders));
        WP_CLI::line('  Items actualmente en la cola: ' . number_format_i18n($queue_count));
        WP_CLI::line('  Tamaño de batch: ' . $batch_size);
        WP_CLI::line('  Modo: ' . ($dry_run ? 'DRY-RUN (no se cambia nada)' : 'EJECUCIÓN REAL'));
        WP_CLI::line('');

        if ($total_orders === 0 && $queue_count === 0) {
            WP_CLI::success('No hay nada que hacer.');
            return;
        }

        if (!$dry_run) {
            if (empty($assoc_args['yes'])) {
                WP_CLI::confirm(sprintf(
                    'Esto eliminará %d item(s) en cola y borrará archivos + metadata de %d orden(es). ¿Continuar?',
                    $queue_count,
                    $total_orders
                ), $assoc_args);
            }
        }

        // 1. Purge queue table.
        if ($dry_run) {
            WP_CLI::line(sprintf('[dry-run] Se eliminarían %d item(s) de %s.', $queue_count, $queue_table));
        } else {
            $deleted = $wpdb->query("DELETE FROM {$queue_table}");
            WP_CLI::line(sprintf('✓ Cola vaciada (%d fila(s) eliminadas).', (int) $deleted));
            delete_transient('fe_woo_queue_processing');
        }
        WP_CLI::line('');

        if ($total_orders === 0) {
            WP_CLI::success('Cola vaciada. No hay órdenes que re-encolar.');
            return;
        }

        // 2. Iterate orders in batches with a progress bar.
        $progress = \WP_CLI\Utils\make_progress_bar(
            $dry_run ? 'Dry-run órdenes' : 'Re-encolando órdenes',
            $total_orders
        );

        $success = 0;
        $failed = 0;
        $errors = [];
        $page = 1;

        do {
            $page_result = wc_get_orders([
                'status'   => $statuses,
                'type'     => 'shop_order',
                'limit'    => $batch_size,
                'page'     => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'paginate' => true,
                'return'   => 'ids',
            ]);

            $order_ids = $page_result->orders;

            foreach ($order_ids as $order_id) {
                if ($dry_run) {
                    $progress->tick();
                    $success++;
                    continue;
                }

                try {
                    $result = FE_Woo_Queue_Processor::reexecute_invoice((int) $order_id);
                    if (!empty($result['success'])) {
                        $success++;
                    } else {
                        $failed++;
                        $errors[] = sprintf('Orden #%d: %s', $order_id, $result['message'] ?? 'error desconocido');
                    }
                } catch (\Throwable $e) {
                    $failed++;
                    $errors[] = sprintf('Orden #%d: %s', $order_id, $e->getMessage());
                }

                $progress->tick();
            }

            $page++;

            // Free memory between batches — WooCommerce caches order objects per request.
            if (function_exists('wp_cache_flush_runtime')) {
                wp_cache_flush_runtime();
            }
        } while ($page <= (int) $page_result->max_num_pages);

        $progress->finish();

        WP_CLI::line('');
        WP_CLI::line('----------------------------------------------');
        WP_CLI::line('  RESUMEN');
        WP_CLI::line('----------------------------------------------');
        WP_CLI::line('  Éxitos: ' . $success);
        WP_CLI::line('  Fallos: ' . $failed);

        if (!empty($errors)) {
            WP_CLI::line('');
            WP_CLI::warning('Se encontraron errores en las siguientes órdenes:');
            foreach (array_slice($errors, 0, 20) as $err) {
                WP_CLI::line('  - ' . $err);
            }
            if (count($errors) > 20) {
                WP_CLI::line(sprintf('  ... y %d error(es) más.', count($errors) - 20));
            }
        }

        WP_CLI::line('');
        if ($dry_run) {
            WP_CLI::success('DRY-RUN completado. Ejecuta sin --dry-run para aplicar los cambios.');
        } elseif ($failed === 0) {
            WP_CLI::success('Re-ejecución completada. Las órdenes se procesarán en el próximo ciclo de cola.');
        } else {
            WP_CLI::warning('Re-ejecución completada con errores. Revisa el listado anterior.');
        }
    }

    /**
     * Backfill the signed MensajeHacienda (AHC-) XML for orders that already
     * have a factura stored but no acuse XML on disk. Queries Hacienda's
     * consulta endpoint per clave, decodes `respuesta-xml`, and writes it as
     * AHC-{clave}.xml.
     *
     * Safe to re-run. Skips orders that already have the file.
     *
     * ## OPTIONS
     *
     * [--status=<csv>]
     * : Comma-separated WooCommerce order statuses to target. Default: completed,processing.
     *
     * [--limit=<n>]
     * : Max orders to process. Default: no limit.
     *
     * [--sleep=<seconds>]
     * : Seconds to wait between API calls to avoid rate-limits. Default: 1.
     *
     * [--dry-run]
     * : List orders that would be processed without contacting Hacienda.
     *
     * @when after_wp_load
     */
    public function backfill_acuse_xml($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);
        $statuses_csv = (string) \WP_CLI\Utils\get_flag_value($assoc_args, 'status', 'completed,processing');
        $statuses = array_values(array_filter(array_map('trim', explode(',', $statuses_csv))));
        $limit = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 0);
        $sleep = max(0, (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'sleep', 1));

        $orders = wc_get_orders([
            'status'   => $statuses,
            'limit'    => $limit > 0 ? $limit : -1,
            'meta_key' => '_fe_woo_clave',
            'meta_compare' => 'EXISTS',
            'return'   => 'objects',
        ]);

        if (empty($orders)) {
            WP_CLI::success('No hay órdenes con clave de factura almacenada.');
            return;
        }

        WP_CLI::line(sprintf('Evaluando %d órdenes...', count($orders)));
        $saved = 0; $skipped = 0; $failed = 0;
        $client = $dry_run ? null : new FE_Woo_API_Client();

        foreach ($orders as $order) {
            $order_id = $order->get_id();
            $clave = $order->get_meta('_fe_woo_clave');
            if (empty($clave)) { $skipped++; continue; }

            if (FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave)) {
                $skipped++;
                continue;
            }

            if ($dry_run) {
                WP_CLI::line(sprintf('  [dry] #%d %s', $order_id, $clave));
                continue;
            }

            try {
                $result = $client->query_invoice_status($clave);
            } catch (Exception $e) {
                WP_CLI::warning(sprintf('#%d query exception: %s', $order_id, $e->getMessage()));
                $failed++;
                continue;
            }

            if (FE_Woo_Queue_Processor::save_acuse_xml_from_response($order_id, $clave, $result)) {
                WP_CLI::line(sprintf('  ✓ #%d saved', $order_id));
                $saved++;
            } else {
                $state = isset($result['data']['ind-estado']) ? $result['data']['ind-estado'] : (isset($result['status_code']) ? 'HTTP ' . $result['status_code'] : 'no-xml');
                WP_CLI::line(sprintf('  · #%d no XML yet (%s)', $order_id, $state));
                $failed++;
            }

            if ($sleep > 0) { sleep($sleep); }
        }

        WP_CLI::line('');
        WP_CLI::success(sprintf('Backfill: %d guardado(s), %d sin XML disponible, %d saltado(s).', $saved, $failed, $skipped));
    }

    /**
     * Detectar comprobantes huérfanos: claves que se emitieron a Hacienda pero
     * cuya orden WC no las tiene como `_fe_woo_factura_clave` actual.
     *
     * Pasa cuando dos requests concurrentes para el mismo order_id queman
     * dos consecutivos (counter atómico funciona) pero solo uno gana la
     * carrera de `$order->save()`. La otra clave queda válida en Hacienda
     * pero "perdida" desde la perspectiva del catálogo WC. Este reporte la
     * destapa para que el operador decida emitir nota de crédito o
     * conciliarla manualmente.
     *
     * El emission log empezó a poblarse a partir de la versión 1.23.0 — no
     * detecta huérfanos previos a esa fecha (no hay datos para compararlos).
     *
     * ## OPTIONS
     *
     * [--since=<YYYY-MM-DD>]
     * : Solo considerar emisiones desde esta fecha (UTC). Default: todo el log.
     *
     * [--limit=<n>]
     * : Máximo de filas a reportar.
     *
     * [--format=<format>]
     * : Output format. table | json | csv. Default: table.
     *
     * @when after_wp_load
     */
    public function find_orphans($args, $assoc_args) {
        if (!class_exists('FE_Woo_Emission_Log')) {
            WP_CLI::error('FE_Woo_Emission_Log no está cargado. ¿Plugin activo en versión >= 1.23.0?');
        }

        $since = \WP_CLI\Utils\get_flag_value($assoc_args, 'since', null);
        $limit = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 0);
        $format = (string) \WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

        $orphans = FE_Woo_Emission_Log::find_orphans($since, $limit > 0 ? $limit : null);

        if (empty($orphans)) {
            WP_CLI::success('No hay comprobantes huérfanos.');
            return;
        }

        WP_CLI::warning(sprintf('Se encontraron %d comprobante(s) huérfano(s):', count($orphans)));
        \WP_CLI\Utils\format_items(
            $format,
            $orphans,
            ['order_id', 'clave', 'cedula_emisor', 'document_type', 'consecutivo', 'emitted_at', 'hacienda_status', 'current_clave_in_order']
        );
        WP_CLI::line('');
        WP_CLI::line('Sugerencia: para cada huérfano con hacienda_status=aceptado, emitir una nota de crédito que lo anule (referenced_clave = clave huérfana, reference_code = "01" Anula).');
    }

    /**
     * Reactivar items de cola en STATUS_FAILED.
     *
     * Items que cayeron en FAILED tras agotar max_attempts pueden quedar
     * bloqueados permanentemente. Este comando los pone en STATUS_RETRY con
     * attempts=0 y opcionalmente sube max_attempts, dándoles otra ronda de
     * reintentos en el próximo cron tick.
     *
     * Por default excluye items donde error_message indica rechazo de
     * Hacienda — esos requieren "Reintentar con datos actualizados" desde
     * el admin para regenerar la clave. Usa --include-rejected para forzar.
     *
     * ## OPTIONS
     *
     * [--since=<date>]
     * : Solo items creados desde esta fecha (YYYY-MM-DD).
     *
     * [--limit=<n>]
     * : Máximo de items a reactivar. Default: sin límite.
     *
     * [--max-attempts=<n>]
     * : Nuevo valor de max_attempts. Default: deja el actual del item.
     *
     * [--include-rejected]
     * : Incluir items donde error_message indica rechazo de Hacienda.
     *
     * [--dry-run]
     * : Listar candidatos sin modificar nada.
     *
     * [--yes]
     * : Saltar confirmación interactiva.
     *
     * ## EXAMPLES
     *
     *     wp fe-woo unblock_failed --dry-run
     *     wp fe-woo unblock_failed --since=2026-04-01 --max-attempts=10
     *     wp fe-woo unblock_failed --limit=50 --yes
     *
     * @when after_wp_load
     */
    public function unblock_failed($args, $assoc_args) {
        global $wpdb;
        $table = $wpdb->prefix . 'fe_woo_factura_queue';

        $since = \WP_CLI\Utils\get_flag_value($assoc_args, 'since', null);
        $limit = (int) \WP_CLI\Utils\get_flag_value($assoc_args, 'limit', 0);
        $max_attempts_override = \WP_CLI\Utils\get_flag_value($assoc_args, 'max-attempts', null);
        $include_rejected = isset($assoc_args['include-rejected']);
        $dry_run = isset($assoc_args['dry-run']);

        if ($since !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $since)) {
            WP_CLI::error('--since debe tener formato YYYY-MM-DD.');
        }

        $where = 'status = %s';
        $params = [FE_Woo_Queue::STATUS_FAILED];

        if ($since !== null) {
            $where .= ' AND created_at >= %s';
            $params[] = $since . ' 00:00:00';
        }

        // Heurística para excluir rechazos de Hacienda. Un rechazo es final
        // (consecutivo consumido y registrado en /recepcion); reactivar es
        // peligroso porque generaría una nueva emisión. El operador puede
        // forzar con --include-rejected si sabe lo que hace.
        if (!$include_rejected) {
            $where .= ' AND (error_message IS NULL OR ('
                . 'error_message NOT LIKE %s'
                . ' AND error_message NOT LIKE %s'
                . ' AND error_message NOT LIKE %s'
                . '))';
            $params[] = '%rechazado por hacienda%';
            $params[] = '%rechazado:%';
            $params[] = '%hacienda rechaz%';
        }

        $sql = "SELECT id, order_id, attempts, max_attempts, created_at, error_message
                FROM {$table}
                WHERE {$where}
                ORDER BY created_at ASC";
        if ($limit > 0) {
            $sql .= ' LIMIT %d';
            $params[] = $limit;
        }

        $items = $wpdb->get_results($wpdb->prepare($sql, $params), ARRAY_A);

        if (empty($items)) {
            WP_CLI::success('No hay items en FAILED que reactivar (con los filtros dados).');
            return;
        }

        $display = array_map(function ($r) {
            $err = (string) ($r['error_message'] ?? '');
            if (function_exists('mb_strlen') ? mb_strlen($err) > 80 : strlen($err) > 80) {
                $err = (function_exists('mb_substr') ? mb_substr($err, 0, 77) : substr($err, 0, 77)) . '...';
            }
            return [
                'id'           => (int) $r['id'],
                'order_id'     => (int) $r['order_id'],
                'attempts'     => (int) $r['attempts'],
                'max_attempts' => (int) $r['max_attempts'],
                'created_at'   => $r['created_at'],
                'error'        => $err,
            ];
        }, $items);

        WP_CLI::line(sprintf(
            'Encontrados: %d item(s) en FAILED %s.',
            count($items),
            $include_rejected ? '(incluye rechazos de Hacienda)' : '(sin rechazos de Hacienda)'
        ));
        WP_CLI::line('');

        \WP_CLI\Utils\format_items('table', $display, ['id', 'order_id', 'attempts', 'max_attempts', 'created_at', 'error']);
        WP_CLI::line('');

        if ($dry_run) {
            WP_CLI::success(sprintf(
                'DRY-RUN: %d item(s) serían reactivados. Re-corre sin --dry-run para aplicar.',
                count($items)
            ));
            return;
        }

        $confirm_msg = sprintf(
            '¿Reactivar %d item(s) con max_attempts=%s? (status → retry, attempts → 0, error_message → NULL)',
            count($items),
            $max_attempts_override !== null ? (int) $max_attempts_override : 'sin cambio'
        );
        WP_CLI::confirm($confirm_msg, $assoc_args);

        $ids = array_map(static function ($r) {
            return (int) $r['id'];
        }, $items);
        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        if ($max_attempts_override !== null) {
            $sql_update = "UPDATE {$table}
                              SET status = %s,
                                  attempts = 0,
                                  max_attempts = %d,
                                  error_message = NULL,
                                  updated_at = %s
                            WHERE id IN ({$placeholders})";
            $params_update = array_merge(
                [
                    FE_Woo_Queue::STATUS_RETRY,
                    (int) $max_attempts_override,
                    current_time('mysql'),
                ],
                $ids
            );
        } else {
            $sql_update = "UPDATE {$table}
                              SET status = %s,
                                  attempts = 0,
                                  error_message = NULL,
                                  updated_at = %s
                            WHERE id IN ({$placeholders})";
            $params_update = array_merge(
                [
                    FE_Woo_Queue::STATUS_RETRY,
                    current_time('mysql'),
                ],
                $ids
            );
        }

        $affected = $wpdb->query($wpdb->prepare($sql_update, $params_update));

        WP_CLI::success(sprintf(
            'Reactivados %d item(s). Próximo cron tick los procesará.',
            (int) $affected
        ));
    }
}

// Register WP-CLI commands if available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('fe-woo', 'FE_Woo_CLI');
}
