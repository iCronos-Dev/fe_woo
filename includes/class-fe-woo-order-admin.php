<?php
/**
 * FE WooCommerce Order Admin
 *
 * Handles admin interface for factura status on orders
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Order_Admin Class
 *
 * Adds factura status information to WooCommerce orders in admin
 */
class FE_Woo_Order_Admin {

    /**
     * Initialize the order admin functionality
     */
    public static function init() {
        // Add meta box to order edit page
        add_action('add_meta_boxes', [__CLASS__, 'add_factura_meta_box']);

        // Add custom column to orders list
        add_filter('manage_woocommerce_page_wc-orders_columns', [__CLASS__, 'add_factura_column'], 20);
        add_filter('manage_shop_order_posts_columns', [__CLASS__, 'add_factura_column'], 20);

        // Populate custom column
        add_action('manage_woocommerce_page_wc-orders_custom_column', [__CLASS__, 'render_factura_column'], 10, 2);
        add_action('manage_shop_order_posts_custom_column', [__CLASS__, 'render_factura_column_legacy'], 10, 2);

        // AJAX handlers
        add_action('wp_ajax_fe_woo_manual_execute_factura', [__CLASS__, 'ajax_manual_execute_factura']);
        add_action('wp_ajax_fe_woo_recheck_status', [__CLASS__, 'ajax_recheck_status']);
        add_action('wp_ajax_fe_woo_retry_with_updated_data', [__CLASS__, 'ajax_retry_with_updated_data']);
        add_action('wp_ajax_fe_woo_download_all_documents', [__CLASS__, 'ajax_download_all_documents']);
        add_action('wp_ajax_fe_woo_download_all_multi_factura', [__CLASS__, 'ajax_download_all_multi_factura']);
        add_action('wp_ajax_fe_woo_download_nota_docs', [__CLASS__, 'ajax_download_nota_docs']);
        add_action('wp_ajax_fe_woo_download_zip', [__CLASS__, 'ajax_download_zip']);
        add_action('wp_ajax_fe_woo_generate_nota', [__CLASS__, 'ajax_generate_nota']);

        // Enqueue admin scripts
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_admin_scripts']);
    }

    /**
     * Add meta box to order edit page
     */
    public static function add_factura_meta_box() {
        $screen = wc_get_container()->get(\Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class)->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id('shop-order')
            : 'shop_order';

        add_meta_box(
            'fe_woo_factura_status',
            __('Factura Electrónica Status', 'fe-woo'),
            [__CLASS__, 'render_factura_meta_box'],
            $screen,
            'side',
            'high'
        );
    }

    /**
     * Render factura status meta box
     *
     * @param WP_Post|WC_Order $post_or_order Post or Order object
     */
    public static function render_factura_meta_box($post_or_order) {
        $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);

        if (!$order) {
            echo '<p>' . esc_html__('Orden no encontrada', 'fe-woo') . '</p>';
            return;
        }

        $order_id = $order->get_id();
        $clave = $order->get_meta('_fe_woo_factura_clave');
        $status = $order->get_meta('_fe_woo_factura_status');
        $hacienda_status = $order->get_meta('_fe_woo_hacienda_status');
        $hacienda_estado_mensaje = $order->get_meta('_fe_woo_hacienda_estado_mensaje');
        $hacienda_detalle = $order->get_meta('_fe_woo_hacienda_detalle');
        $sent_date = $order->get_meta('_fe_woo_factura_sent_date');
        $last_checked = $order->get_meta('_fe_woo_status_last_checked');

        // Check if multi-factura
        $is_multi_factura = $order->get_meta('_fe_woo_multi_factura') === 'yes';
        $facturas_generated = $order->get_meta('_fe_woo_facturas_generated');

        // Get queue status
        $queue_item = FE_Woo_Queue::get_item_by_order($order_id);
        $queue_status = $queue_item ? $queue_item->status : null;

        // Get notas in queue (pending/processing/retry)
        $queued_notas = FE_Woo_Queue::get_queued_notas_for_order($order_id);

        // Lock status: si hay una operación en progreso, mostramos un banner
        // y ocultamos los botones de acción. El JS hace polling y recarga
        // cuando el lock expira.
        $lock_info = class_exists('FE_Woo_Order_Lock') ? FE_Woo_Order_Lock::inspect($order_id) : null;
        ?>
        <div class="fe-woo-factura-status-box" data-order-id="<?php echo esc_attr($order_id); ?>" data-fe-locked="<?php echo $lock_info ? '1' : '0'; ?>" data-fe-lock-remaining="<?php echo $lock_info ? (int) $lock_info['remaining'] : 0; ?>">
            <?php if ($lock_info) : ?>
                <div class="fe-woo-lock-banner" style="margin-bottom: 12px; padding: 10px 12px; background: #fff8e1; border-left: 3px solid #ff9800; font-size: 12px;">
                    <strong style="display:block; margin-bottom: 4px;">
                        <span class="spinner is-active" style="float:none;margin:0 6px 0 0;vertical-align:middle;"></span>
                        <?php esc_html_e('Operación FE en progreso', 'fe-woo'); ?>
                    </strong>
                    <?php printf(
                        esc_html__('La orden tiene una operación "%1$s" en proceso (max %2$ds restantes). Los botones se desactivan hasta que termine. Esta página se recargará automáticamente.', 'fe-woo'),
                        esc_html($lock_info['operation']),
                        (int) $lock_info['remaining']
                    ); ?>
                </div>
            <?php endif; ?>

            <?php if (empty($clave)) : ?>
                <!-- Show queue status if in queue -->
                <?php if ($queue_item) : ?>
                    <p>
                        <strong><?php esc_html_e('Estado de Cola:', 'fe-woo'); ?></strong><br>
                        <span class="fe-woo-status-badge fe-woo-queue-status-<?php echo esc_attr($queue_status); ?>">
                            <?php echo esc_html(self::get_queue_status_label($queue_status)); ?>
                        </span>
                    </p>

                    <?php if ($queue_item->error_message) : ?>
                        <p style="padding: 10px; background: #ffebee; border-left: 3px solid #f44336; font-size: 12px;">
                            <strong><?php esc_html_e('Error:', 'fe-woo'); ?></strong><br>
                            <?php echo esc_html($queue_item->error_message); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($queue_status === FE_Woo_Queue::STATUS_PENDING || $queue_status === FE_Woo_Queue::STATUS_RETRY) : ?>
                        <p style="padding: 10px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                            <?php esc_html_e('Factura electrónica en cola de procesamiento. Será procesada automáticamente en unos momentos.', 'fe-woo'); ?>
                        </p>
                    <?php elseif ($queue_status === FE_Woo_Queue::STATUS_PROCESSING) : ?>
                        <p style="padding: 10px; background: #e3f2fd; border-left: 3px solid #2196f3; font-size: 12px;">
                            <?php esc_html_e('Procesando factura electrónica...', 'fe-woo'); ?>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <p><em><?php esc_html_e('Aún no se ha generado factura para esta orden.', 'fe-woo'); ?></em></p>
                <?php endif; ?>

                <!-- Preview of facturas to generate -->
                <?php
                $multi_factura_preview = FE_Woo_Multi_Factura_Generator::generate_facturas_for_order($order);
                if (!empty($multi_factura_preview['facturas'])) :
                ?>
                    <div style="margin-top: 15px; padding: 12px; background: #f5f5f5; border-radius: 4px; border: 1px solid #ddd;">
                        <strong style="display: block; margin-bottom: 10px; font-size: 12px; color: #333;">
                            <?php printf(__('📋 Facturas a Generar: %d', 'fe-woo'), count($multi_factura_preview['facturas'])); ?>
                        </strong>

                        <?php foreach ($multi_factura_preview['facturas'] as $index => $factura_preview) : ?>
                            <?php
                            $emisor = FE_Woo_Emisor_Manager::get_emisor($factura_preview['emisor_id']);
                            $emisor_name = $emisor ? $emisor->nombre_legal : __('Emisor no encontrado', 'fe-woo');
                            $items_total = FE_Woo_Multi_Factura_Generator::calculate_items_total($factura_preview['items']);
                            ?>
                            <div style="margin-bottom: 10px; padding: 10px; background: white; border-radius: 4px; border-left: 3px solid #4caf50;">
                                <div style="display: flex; align-items: center; margin-bottom: 5px;">
                                    <span style="background: #4caf50; color: white; padding: 2px 6px; border-radius: 3px; font-size: 10px; font-weight: 600; margin-right: 6px;">
                                        <?php printf(__('FACTURA %d', 'fe-woo'), $index + 1); ?>
                                    </span>
                                </div>

                                <div style="font-size: 12px; margin-bottom: 4px;">
                                    <strong><?php esc_html_e('Emisor:', 'fe-woo'); ?></strong>
                                    <?php echo esc_html($emisor_name); ?>
                                </div>

                                <div style="font-size: 11px; color: #666; margin-bottom: 4px;">
                                    <strong><?php esc_html_e('Items:', 'fe-woo'); ?></strong>
                                    <?php echo count($factura_preview['items']); ?> producto(s)
                                </div>

                                <ul style="margin: 5px 0 0 15px; padding: 0; font-size: 10px; color: #666;">
                                    <?php foreach ($factura_preview['items'] as $item) : ?>
                                        <li style="margin-bottom: 2px;">
                                            <?php echo esc_html($item->get_name()); ?>
                                            x<?php echo esc_html($item->get_quantity()); ?>
                                            - <?php echo wc_price($item->get_total() + $item->get_total_tax()); ?>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>

                                <div style="font-size: 12px; margin-top: 8px; font-weight: 600; color: #2e7d32;">
                                    <?php esc_html_e('Total:', 'fe-woo'); ?> <?php echo wc_price($items_total); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <!-- Manual execution button - Show if order exists, status is completed, and no factura yet -->
                <?php if ($order_id > 0) : ?>
                    <?php if ($order->get_status() === 'completed') : ?>
                        <p style="margin-top: 15px;">
                            <button type="button" class="button button-primary fe-woo-ejecutar-factura" data-order-id="<?php echo esc_attr($order_id); ?>" style="width: 100%; background: #d32f2f; border-color: #b71c1c; font-weight: 600;">
                                <?php esc_html_e('EJECUTAR', 'fe-woo'); ?>
                            </button>
                        </p>
                    <?php else : ?>
                        <p style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                            <?php
                            printf(
                                esc_html__('La facturación electrónica solo se puede ejecutar cuando el estado de la orden sea "Completado". Estado actual: %s', 'fe-woo'),
                                '<strong>' . esc_html(wc_get_order_status_name($order->get_status())) . '</strong>'
                            );
                            ?>
                        </p>
                    <?php endif; ?>
                <?php else : ?>
                    <p style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 3px solid #ffc107; font-size: 12px;">
                        <?php esc_html_e('Guarde la orden primero para poder ejecutar la facturación electrónica.', 'fe-woo'); ?>
                    </p>
                <?php endif; ?>
            <?php else : ?>
                <?php if ($is_multi_factura && !empty($facturas_generated)) : ?>
                    <!-- Multi-Factura Display -->
                    <div style="padding: 10px; background: #e3f2fd; border-left: 4px solid #2196f3; margin-bottom: 15px;">
                        <strong style="color: #1976d2;">
                            <?php printf(__('✓ %d Facturas Generadas', 'fe-woo'), count($facturas_generated)); ?>
                        </strong>
                    </div>

                    <?php foreach ($facturas_generated as $index => $factura) : ?>
                        <?php
                        // Get status for this specific factura (stored per factura or use general)
                        $factura_status = isset($factura['status']) ? $factura['status'] : $status;
                        $factura_hacienda_status = isset($factura['hacienda_status']) ? $factura['hacienda_status'] : $hacienda_status;
                        ?>
                        <div style="margin-bottom: 20px; padding: 12px; background: #f5f5f5; border-radius: 4px; border-left: 3px solid #4caf50;">
                            <div style="display: flex; align-items: center; margin-bottom: 8px;">
                                <span style="background: #4caf50; color: white; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-right: 8px;">
                                    <?php printf(__('FACTURA %d', 'fe-woo'), $index + 1); ?>
                                </span>
                                <strong style="font-size: 13px;"><?php echo esc_html($factura['emisor_name']); ?></strong>
                            </div>

                            <p style="margin: 8px 0; font-size: 12px;">
                                <strong><?php esc_html_e('Clave:', 'fe-woo'); ?></strong><br>
                                <code style="font-size: 9px; word-break: break-all; background: white; padding: 4px; display: inline-block; border-radius: 2px;">
                                    <?php echo esc_html($factura['clave']); ?>
                                </code>
                            </p>

                            <p style="margin: 8px 0; font-size: 12px;">
                                <strong><?php esc_html_e('Monto:', 'fe-woo'); ?></strong>
                                <span style="font-weight: 600; color: #2e7d32;">
                                    <?php echo wc_price($factura['monto']); ?>
                                </span>
                                <span style="color: #666; font-size: 11px;">
                                    (<?php echo esc_html($factura['items_count']); ?> <?php echo _n('item', 'items', $factura['items_count'], 'fe-woo'); ?>)
                                </span>
                            </p>

                            <!-- Estados por factura -->
                            <div style="display: flex; gap: 15px; margin: 10px 0; flex-wrap: wrap;">
                                <div style="font-size: 12px;">
                                    <strong><?php esc_html_e('Estado Local:', 'fe-woo'); ?></strong>
                                    <span class="fe-woo-status-badge fe-woo-status-<?php echo esc_attr($factura_status); ?>" style="margin-left: 5px;">
                                        <?php echo esc_html(self::get_status_label($factura_status)); ?>
                                    </span>
                                </div>
                                <?php if ($factura_hacienda_status) : ?>
                                    <div style="font-size: 12px;">
                                        <strong><?php esc_html_e('Estado Hacienda:', 'fe-woo'); ?></strong>
                                        <span class="fe-woo-hacienda-status-badge fe-woo-hacienda-<?php echo esc_attr($factura_hacienda_status); ?>" style="margin-left: 5px;">
                                            <?php echo esc_html(self::get_hacienda_status_label($factura_hacienda_status)); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            // Get document URLs for this factura
                            $factura_xml_url = FE_Woo_Document_Storage::get_download_url($order_id, $factura['clave'], 'xml');
                            $factura_pdf_url = FE_Woo_Document_Storage::get_download_url($order_id, $factura['clave'], 'pdf');
                            ?>

                            <?php if ($factura_xml_url || $factura_pdf_url) : ?>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                                    <strong style="font-size: 11px; color: #666;"><?php esc_html_e('Documentos:', 'fe-woo'); ?></strong>
                                    <div style="display: flex; gap: 8px; margin-top: 5px; flex-wrap: wrap;">
                                        <?php if ($factura_pdf_url) : ?>
                                            <a href="<?php echo esc_url($factura_pdf_url); ?>" target="_blank" class="button button-small" style="font-size: 11px; padding: 4px 8px;">
                                                <span class="dashicons dashicons-pdf" style="font-size: 14px; vertical-align: text-top;"></span>
                                                PDF
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($factura_xml_url) : ?>
                                            <a href="<?php echo esc_url($factura_xml_url); ?>" target="_blank" class="button button-small" style="font-size: 11px; padding: 4px 8px;">
                                                <span class="dashicons dashicons-media-document" style="font-size: 14px; vertical-align: text-top;"></span>
                                                XML
                                            </a>
                                        <?php endif; ?>
                                        <button type="button" class="button button-small fe-woo-download-single-factura" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($factura['clave']); ?>" style="font-size: 11px; padding: 4px 8px; background: #2271b1; color: white; border-color: #2271b1;">
                                            <span class="dashicons dashicons-download" style="font-size: 14px; vertical-align: text-top;"></span>
                                            <?php esc_html_e('Zip', 'fe-woo'); ?>
                                        </button>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <!-- Notas de Crédito/Débito for this specific factura -->
                            <?php
                            $all_notas = $order->get_meta('_fe_woo_notas');
                            $factura_notas = [];
                            if (!empty($all_notas) && is_array($all_notas)) {
                                $factura_notas = array_filter($all_notas, function($nota) use ($factura) {
                                    return isset($nota['referenced_clave']) && $nota['referenced_clave'] === $factura['clave'];
                                });
                            }
                            $factura_emisor_id = isset($factura['emisor_id']) ? $factura['emisor_id'] : 0;
                            ?>
                            <?php
                            // Filter queued notas for this specific factura
                            $factura_queued_notas = array_filter($queued_notas, function($qi) use ($factura) {
                                return isset($qi->nota_data['referenced_clave']) && $qi->nota_data['referenced_clave'] === $factura['clave'];
                            });
                            ?>
                            <?php if (!empty($factura_notas) || !empty($factura_queued_notas)) : ?>
                                <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                                    <strong style="font-size: 11px; color: #666;"><?php esc_html_e('Notas:', 'fe-woo'); ?></strong>
                                    <?php foreach ($factura_notas as $nota) :
                                        $nota_type_label = $nota['type'] === 'nota_credito' ? __('NC', 'fe-woo') : __('ND', 'fe-woo');
                                        $nota_color = $nota['type'] === 'nota_credito' ? '#2e7d32' : '#e65100';
                                    ?>
                                        <div style="margin-top: 6px; padding: 8px; background: white; border-radius: 3px; border-left: 2px solid <?php echo esc_attr($nota_color); ?>; font-size: 11px;">
                                            <strong style="color: <?php echo esc_attr($nota_color); ?>;"><?php echo esc_html($nota_type_label); ?></strong>
                                            - <?php echo esc_html($nota['reason']); ?>
                                            <br>
                                            <code style="font-size: 9px; word-break: break-all;"><?php echo esc_html($nota['clave']); ?></code>
                                            <br>
                                            <span style="color: #666;"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($nota['created_at']))); ?></span>
                                            <?php if (isset($nota['hacienda_status'])) : ?>
                                                - <span style="color: <?php echo esc_attr($nota['hacienda_status'] === 'sent' ? '#2e7d32' : '#d32f2f'); ?>;">
                                                    <?php echo esc_html($nota['hacienda_status'] === 'sent' ? __('Enviada', 'fe-woo') : __('Fallo', 'fe-woo')); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php
                                            $nota_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $nota['clave']);
                                            if (!empty($nota_paths['xml']) || !empty($nota_paths['pdf'])) :
                                            ?>
                                                <div style="margin-top: 4px; display: flex; gap: 4px;">
                                                    <button type="button" class="button button-small fe-woo-download-nota-docs" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($nota['clave']); ?>" style="font-size: 10px; padding: 2px 6px; background: <?php echo esc_attr($nota_color); ?>; color: white; border-color: <?php echo esc_attr($nota_color); ?>;">
                                                        <?php esc_html_e('Descargar', 'fe-woo'); ?>
                                                    </button>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php foreach ($factura_queued_notas as $queued_nota) :
                                        $q_type = isset($queued_nota->nota_data['note_type']) ? $queued_nota->nota_data['note_type'] : $queued_nota->document_type;
                                        $q_label = $q_type === 'nota_credito' ? __('NC', 'fe-woo') : __('ND', 'fe-woo');
                                        $q_reason = isset($queued_nota->nota_data['reason']) ? $queued_nota->nota_data['reason'] : '';
                                        $q_status_map = [
                                            'pending' => __('En cola', 'fe-woo'),
                                            'processing' => __('Procesando', 'fe-woo'),
                                            'retry' => __('Reintentando', 'fe-woo'),
                                        ];
                                        $q_status_label = isset($q_status_map[$queued_nota->status]) ? $q_status_map[$queued_nota->status] : $queued_nota->status;
                                    ?>
                                        <div style="margin-top: 6px; padding: 8px; background: #fff8e1; border-radius: 3px; border-left: 2px solid #ff9800; font-size: 11px;">
                                            <strong style="color: #e65100;"><?php echo esc_html($q_label); ?></strong>
                                            - <?php echo esc_html($q_reason); ?>
                                            <br>
                                            <span style="display: inline-block; margin-top: 3px; padding: 1px 6px; background: #ff9800; color: white; border-radius: 3px; font-size: 10px; font-weight: 600;">
                                                <?php echo esc_html($q_status_label); ?>
                                            </span>
                                            <span style="color: #666; margin-left: 4px;"><?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($queued_nota->created_at))); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Generate nota form for this factura -->
                            <div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">
                                <details style="border: 1px solid #ddd; border-radius: 4px; padding: 8px; background: #fafafa;">
                                    <summary style="cursor: pointer; font-weight: 600; font-size: 11px; color: #2271b1;">
                                        <?php esc_html_e('+ Generar nota para esta factura', 'fe-woo'); ?>
                                    </summary>
                                    <div class="fe-woo-nota-form-container" style="margin-top: 10px;">
                                        <p style="margin: 6px 0;">
                                            <label style="display: block; font-weight: 600; font-size: 10px; margin-bottom: 3px;">
                                                <?php esc_html_e('Tipo:', 'fe-woo'); ?>
                                            </label>
                                            <select class="fe-woo-note-type widefat" style="font-size: 11px;">
                                                <option value="nota_credito"><?php esc_html_e('Nota de Crédito', 'fe-woo'); ?></option>
                                                <option value="nota_debito"><?php esc_html_e('Nota de Débito', 'fe-woo'); ?></option>
                                            </select>
                                        </p>
                                        <p style="margin: 6px 0;">
                                            <label style="display: block; font-weight: 600; font-size: 10px; margin-bottom: 3px;">
                                                <?php esc_html_e('Código de referencia:', 'fe-woo'); ?>
                                            </label>
                                            <select class="fe-woo-reference-code widefat" style="font-size: 11px;">
                                                <option value="01"><?php esc_html_e('01 - Anula documento', 'fe-woo'); ?></option>
                                                <option value="02"><?php esc_html_e('02 - Corrige texto', 'fe-woo'); ?></option>
                                                <option value="03"><?php esc_html_e('03 - Corrige monto', 'fe-woo'); ?></option>
                                                <option value="04"><?php esc_html_e('04 - Referencia otro', 'fe-woo'); ?></option>
                                                <option value="05"><?php esc_html_e('05 - Sustituye contingencia', 'fe-woo'); ?></option>
                                                <option value="99"><?php esc_html_e('99 - Otros', 'fe-woo'); ?></option>
                                            </select>
                                        </p>
                                        <p style="margin: 6px 0;">
                                            <label style="display: block; font-weight: 600; font-size: 10px; margin-bottom: 3px;">
                                                <?php esc_html_e('Razón (máx. 180):', 'fe-woo'); ?>
                                            </label>
                                            <textarea class="fe-woo-note-reason widefat" rows="2" maxlength="180" style="font-size: 11px;"></textarea>
                                            <span class="fe-woo-reason-counter" style="font-size: 9px; color: #666;">0/180</span>
                                        </p>
                                        <p style="margin: 6px 0;">
                                            <label style="display: block; font-weight: 600; font-size: 10px; margin-bottom: 3px;">
                                                <?php esc_html_e('Notas adicionales:', 'fe-woo'); ?>
                                            </label>
                                            <textarea class="fe-woo-note-additional widefat" rows="1" style="font-size: 11px;"></textarea>
                                        </p>
                                        <p style="margin-top: 8px;">
                                            <button type="button" class="button button-primary fe-woo-generate-note" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($factura['clave']); ?>" data-emisor-id="<?php echo esc_attr($factura_emisor_id); ?>" style="width: 100%; font-weight: 600; font-size: 11px;">
                                                <?php esc_html_e('Generar Nota', 'fe-woo'); ?>
                                            </button>
                                        </p>
                                        <div class="fe-woo-note-message" style="margin-top: 8px; padding: 6px; border-radius: 4px; font-size: 11px; display: none;"></div>
                                    </div>
                                </details>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <!-- Download ALL multi-factura documents button -->
                    <div style="margin-top: 15px; padding: 12px; background: #e8f5e9; border-radius: 4px;">
                        <button type="button" class="button fe-woo-download-all-multi" data-order-id="<?php echo esc_attr($order_id); ?>" style="width: 100%; border: 2px solid #4caf50; color: white; background: #4caf50; font-weight: 600;">
                            <?php printf(__('Descargar todas las facturas (%d)', 'fe-woo'), count($facturas_generated)); ?>
                        </button>
                    </div>

                    <?php if ($sent_date) : ?>
                        <p style="font-size: 12px; color: #666; margin-top: 15px;">
                            <strong><?php esc_html_e('Fecha de Envío:', 'fe-woo'); ?></strong><br>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sent_date))); ?>
                        </p>
                    <?php endif; ?>

                <?php else : ?>
                    <!-- Single Factura Display (Original) -->
                    <p>
                        <strong><?php esc_html_e('Clave:', 'fe-woo'); ?></strong><br>
                        <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html($clave); ?></code>
                    </p>

                    <p>
                        <strong><?php esc_html_e('Estado Local:', 'fe-woo'); ?></strong><br>
                        <span class="fe-woo-status-badge fe-woo-status-<?php echo esc_attr($status); ?>">
                            <?php echo esc_html(self::get_status_label($status)); ?>
                        </span>
                    </p>

                    <?php if ($hacienda_status) : ?>
                        <p>
                            <strong><?php esc_html_e('Estado Hacienda:', 'fe-woo'); ?></strong><br>
                            <span class="fe-woo-hacienda-status-badge fe-woo-hacienda-<?php echo esc_attr($hacienda_status); ?>">
                                <?php echo esc_html(self::get_hacienda_status_label($hacienda_status)); ?>
                            </span>
                            <?php if ($hacienda_estado_mensaje && strcasecmp($hacienda_estado_mensaje, $hacienda_status) !== 0) : ?>
                                <span style="color: #666; font-size: 12px; margin-left: 6px;">(<?php echo esc_html($hacienda_estado_mensaje); ?>)</span>
                            <?php endif; ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($hacienda_detalle) : ?>
                        <?php
                        // Color-code the block: green when accepted, red when rejected, neutral otherwise.
                        $is_rejected = ($hacienda_status === 'rechazado');
                        $is_accepted = ($hacienda_status === 'aceptado');
                        $bg = $is_rejected ? '#fdecea' : ($is_accepted ? '#e8f5e9' : '#f5f5f5');
                        $border = $is_rejected ? '#d32f2f' : ($is_accepted ? '#4caf50' : '#9e9e9e');
                        ?>
                        <div style="margin-top: 8px; padding: 10px 12px; background: <?php echo esc_attr($bg); ?>; border-left: 3px solid <?php echo esc_attr($border); ?>; font-size: 12px; white-space: pre-wrap; max-height: 220px; overflow: auto;">
                            <strong style="display: block; margin-bottom: 4px;"><?php esc_html_e('Mensaje de Hacienda:', 'fe-woo'); ?></strong>
                            <?php echo esc_html($hacienda_detalle); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($hacienda_status, ['procesando', 'recibido'], true)) : ?>
                        <p style="margin-top: 8px;">
                            <button type="button"
                                    class="button fe-woo-recheck-status"
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    style="width: 100%;">
                                <?php esc_html_e('Volver a consultar a Hacienda', 'fe-woo'); ?>
                            </button>
                            <span style="display:block; margin-top:6px; font-size:11px; color:#666;">
                                <?php esc_html_e('Pregunta a Hacienda si ya hay veredicto final.', 'fe-woo'); ?>
                            </span>
                        </p>
                    <?php endif; ?>

                    <?php if ($hacienda_status === 'rechazado') : ?>
                        <p style="margin-top: 10px;">
                            <button type="button"
                                    class="button button-primary fe-woo-retry-with-updated-data"
                                    data-order-id="<?php echo esc_attr($order_id); ?>"
                                    style="width: 100%; background:#d32f2f; border-color:#d32f2f;">
                                <?php esc_html_e('Reintentar', 'fe-woo'); ?>
                            </button>
                            <span style="display:block; margin-top:6px; font-size:11px; color:#666;">
                                <?php esc_html_e('Descarta la factura rechazada y genera una nueva con los datos actuales del emisor. Úsalo solo después de corregir la causa del rechazo.', 'fe-woo'); ?>
                            </span>
                        </p>
                    <?php endif; ?>

                    <?php if ($sent_date) : ?>
                        <p>
                            <strong><?php esc_html_e('Fecha de Envío:', 'fe-woo'); ?></strong><br>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($sent_date))); ?>
                        </p>
                    <?php endif; ?>

                    <?php if ($last_checked) : ?>
                        <p>
                            <strong><?php esc_html_e('Última Verificación:', 'fe-woo'); ?></strong><br>
                            <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($last_checked))); ?>
                        </p>
                    <?php endif; ?>
                <?php endif; ?>

                <?php
                // Show document downloads ONLY when Hacienda has accepted the
                // comprobante. A rejected factura is NOT a valid fiscal
                // document — the operator must not send the PDF to the
                // customer, the XML is not authoritative (it was never signed
                // into Hacienda's ledger) and exposing either creates
                // confusion. Multi-factura docs live in their own section
                // above and follow the same rule there.
                if (
                    !$is_multi_factura
                    && $hacienda_status === 'aceptado'
                    && FE_Woo_Document_Storage::documents_exist($order_id, $clave)
                ) :
                    $document_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $clave);
                    $xml_url = FE_Woo_Document_Storage::get_download_url($order_id, $clave, 'xml');
                    $pdf_url = FE_Woo_Document_Storage::get_download_url($order_id, $clave, 'pdf');
                ?>
                    <div style="margin-top: 15px; padding: 12px; background: #f5f5f5; border-radius: 4px;">
                        <strong><?php esc_html_e('Documentos Generados:', 'fe-woo'); ?></strong>
                        <ul style="margin: 8px 0 0 0; padding: 0; list-style: none;">
                            <?php if ($pdf_url && isset($document_paths['pdf'])) : ?>
                                <li style="margin: 5px 0;">
                                    <span class="dashicons dashicons-pdf" style="color: #d32f2f;"></span>
                                    <a href="<?php echo esc_url($pdf_url); ?>" target="_blank" style="text-decoration: none; font-weight: 600;">
                                        <?php esc_html_e('PDF Factura', 'fe-woo'); ?>
                                    </a>
                                    <span style="color: #666; font-size: 11px;">
                                        (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($document_paths['pdf'])); ?>)
                                    </span>
                                </li>
                            <?php endif; ?>
                            <?php if ($xml_url && isset($document_paths['xml'])) : ?>
                                <li style="margin: 5px 0;">
                                    <span class="dashicons dashicons-media-document" style="color: #2196f3;"></span>
                                    <a href="<?php echo esc_url($xml_url); ?>" target="_blank" style="text-decoration: none;">
                                        <?php esc_html_e('XML Factura', 'fe-woo'); ?>
                                    </a>
                                    <span style="color: #666; font-size: 11px;">
                                        (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($document_paths['xml'])); ?>)
                                    </span>
                                </li>
                            <?php endif; ?>
                            <?php
                            $acuse_xml_url = !empty($document_paths['acuse_xml'])
                                ? FE_Woo_Document_Storage::get_download_url($order_id, $clave, 'acuse_xml')
                                : null;
                            if ($acuse_xml_url) : ?>
                                <li style="margin: 5px 0;">
                                    <span class="dashicons dashicons-yes-alt" style="color: #4caf50;"></span>
                                    <a href="<?php echo esc_url($acuse_xml_url); ?>" target="_blank" style="text-decoration: none; font-weight: 600;">
                                        <?php esc_html_e('Acuse de Hacienda (AHC)', 'fe-woo'); ?>
                                    </a>
                                    <span style="color: #666; font-size: 11px;">
                                        (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($document_paths['acuse_xml'])); ?>)
                                    </span>
                                </li>
                            <?php endif; ?>
                        </ul>

                        <!-- Download all documents button -->
                        <p style="margin-top: 12px;">
                            <button type="button" class="button fe-woo-download-all" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($clave); ?>" style="width: 100%; border: 2px solid #2271b1; color: white; background: #2271b1; font-weight: 600;">
                                <?php esc_html_e('Descargar todos', 'fe-woo'); ?>
                            </button>
                        </p>
                    </div>
                <?php endif; ?>

                <?php if (!$is_multi_factura && $hacienda_status === 'aceptado') : ?>
                <p style="padding: 10px; background: #e8f5e9; border-left: 3px solid #4caf50; font-size: 12px; margin-top: 15px;">
                    <strong><?php esc_html_e('✓ Factura aceptada por Hacienda', 'fe-woo'); ?></strong><br>
                    <?php esc_html_e('El comprobante fue validado y aceptado. Los documentos están disponibles para descarga.', 'fe-woo'); ?>
                </p>

                <!-- Notas de Crédito/Débito Section -->
                <div style="margin-top: 20px; padding-top: 15px; border-top: 2px solid #e0e0e0;">
                    <h4 style="margin-top: 0; margin-bottom: 10px; font-size: 13px; color: #333;">
                        <?php esc_html_e('Notas de Crédito/Débito', 'fe-woo'); ?>
                    </h4>

                    <!-- Display existing notes -->
                    <?php
                    $notas = $order->get_meta('_fe_woo_notas');
                    if (!empty($notas) && is_array($notas)) :
                        foreach ($notas as $nota) :
                            $note_type_label = $nota['type'] === 'nota_credito' ? __('Nota de Crédito', 'fe-woo') : __('Nota de Débito', 'fe-woo');
                            $nota_clave = $nota['clave'];
                    ?>
                        <div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 2px solid #e0e0e0;">
                            <h4 style="margin: 0 0 10px 0; font-size: 13px; color: <?php echo $nota['type'] === 'nota_credito' ? '#2e7d32' : '#e65100'; ?>;">
                                <?php echo esc_html($note_type_label); ?>
                            </h4>

                            <p style="margin: 5px 0; font-size: 11px;">
                                <strong><?php esc_html_e('Clave:', 'fe-woo'); ?></strong><br>
                                <code style="font-size: 10px; word-break: break-all;"><?php echo esc_html($nota_clave); ?></code>
                            </p>

                            <p style="margin: 5px 0; font-size: 11px;">
                                <strong><?php esc_html_e('Razón:', 'fe-woo'); ?></strong><br>
                                <span style="color: #666;"><?php echo esc_html($nota['reason']); ?></span>
                            </p>

                            <p style="margin: 5px 0; font-size: 11px;">
                                <strong><?php esc_html_e('Fecha:', 'fe-woo'); ?></strong><br>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($nota['created_at']))); ?>
                            </p>

                            <?php
                            // Check if documents exist for this note
                            $nota_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $nota_clave);

                            if (!empty($nota_paths['xml']) || !empty($nota_paths['pdf'])) :
                            ?>
                                <div style="margin-top: 12px; padding: 12px; background: #f5f5f5; border-radius: 4px;">
                                    <strong><?php esc_html_e('Documentos Generados:', 'fe-woo'); ?></strong>
                                    <ul style="margin: 8px 0 0 0; padding: 0; list-style: none;">
                                        <?php if (!empty($nota_paths['pdf']) && file_exists($nota_paths['pdf'])) : ?>
                                            <li style="margin: 5px 0;">
                                                <span class="dashicons dashicons-pdf" style="color: #d32f2f;"></span>
                                                <a href="<?php echo esc_url(FE_Woo_Document_Storage::get_download_url($order_id, $nota_clave, 'pdf')); ?>" target="_blank" style="text-decoration: none; font-weight: 600;">
                                                    <?php esc_html_e('PDF Nota', 'fe-woo'); ?>
                                                </a>
                                                <span style="color: #666; font-size: 11px;">
                                                    (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($nota_paths['pdf'])); ?>)
                                                </span>
                                            </li>
                                        <?php endif; ?>
                                        <?php if (!empty($nota_paths['xml']) && file_exists($nota_paths['xml'])) : ?>
                                            <li style="margin: 5px 0;">
                                                <span class="dashicons dashicons-media-document" style="color: #2196f3;"></span>
                                                <a href="<?php echo esc_url(FE_Woo_Document_Storage::get_download_url($order_id, $nota_clave, 'xml')); ?>" target="_blank" style="text-decoration: none;">
                                                    <?php esc_html_e('XML Nota', 'fe-woo'); ?>
                                                </a>
                                                <span style="color: #666; font-size: 11px;">
                                                    (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($nota_paths['xml'])); ?>)
                                                </span>
                                            </li>
                                        <?php endif; ?>
                                        <?php
                                        $nota_acuse_xml_path = FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $nota_clave);
                                        if ($nota_acuse_xml_path) : ?>
                                            <li style="margin: 5px 0;">
                                                <span class="dashicons dashicons-yes-alt" style="color: #4caf50;"></span>
                                                <a href="<?php echo esc_url(FE_Woo_Document_Storage::get_download_url($order_id, $nota_clave, 'acuse_xml')); ?>" target="_blank" style="text-decoration: none; font-weight: 600;">
                                                    <?php esc_html_e('Acuse de Hacienda (AHC)', 'fe-woo'); ?>
                                                </a>
                                                <span style="color: #666; font-size: 11px;">
                                                    (<?php echo esc_html(FE_Woo_Document_Storage::get_file_size($nota_acuse_xml_path)); ?>)
                                                </span>
                                            </li>
                                        <?php endif; ?>
                                    </ul>

                                    <!-- Download all documents for this note -->
                                    <p style="margin-top: 12px;">
                                        <button type="button" class="button fe-woo-download-nota-docs" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($nota_clave); ?>" style="width: 100%; border: 2px solid <?php echo $nota['type'] === 'nota_credito' ? '#4caf50' : '#ff9800'; ?>; color: white; background: <?php echo $nota['type'] === 'nota_credito' ? '#4caf50' : '#ff9800'; ?>; font-weight: 600;">
                                            <?php esc_html_e('Descargar todos', 'fe-woo'); ?>
                                        </button>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php
                        endforeach;
                    endif;
                    ?>

                    <!-- Display queued notas (pending/processing) -->
                    <?php
                    // For single-factura, show all queued notas (they all belong to the same factura)
                    if (!empty($queued_notas)) :
                        foreach ($queued_notas as $queued_nota) :
                            $q_type = isset($queued_nota->nota_data['note_type']) ? $queued_nota->nota_data['note_type'] : $queued_nota->document_type;
                            $q_type_label = $q_type === 'nota_credito' ? __('Nota de Crédito', 'fe-woo') : __('Nota de Débito', 'fe-woo');
                            $q_reason = isset($queued_nota->nota_data['reason']) ? $queued_nota->nota_data['reason'] : '';
                            $q_status_map = [
                                'pending' => __('En cola', 'fe-woo'),
                                'processing' => __('Procesando', 'fe-woo'),
                                'retry' => __('Reintentando', 'fe-woo'),
                            ];
                            $q_status_label = isset($q_status_map[$queued_nota->status]) ? $q_status_map[$queued_nota->status] : $queued_nota->status;
                    ?>
                        <div style="margin-bottom: 15px; padding: 12px; background: #fff8e1; border-left: 3px solid #ff9800; border-radius: 4px;">
                            <h4 style="margin: 0 0 8px 0; font-size: 13px; color: #e65100;">
                                <?php echo esc_html($q_type_label); ?>
                                <span style="display: inline-block; padding: 2px 8px; background: #ff9800; color: white; border-radius: 3px; font-size: 10px; font-weight: 600; vertical-align: middle; margin-left: 6px;">
                                    <?php echo esc_html($q_status_label); ?>
                                </span>
                            </h4>

                            <p style="margin: 5px 0; font-size: 11px;">
                                <strong><?php esc_html_e('Razón:', 'fe-woo'); ?></strong><br>
                                <span style="color: #666;"><?php echo esc_html($q_reason); ?></span>
                            </p>

                            <p style="margin: 5px 0; font-size: 11px;">
                                <strong><?php esc_html_e('Fecha:', 'fe-woo'); ?></strong><br>
                                <?php echo esc_html(date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($queued_nota->created_at))); ?>
                            </p>

                            <p style="margin: 5px 0; font-size: 11px; color: #795548;">
                                <?php esc_html_e('Esta nota será procesada automáticamente por el sistema de cola.', 'fe-woo'); ?>
                            </p>
                        </div>
                    <?php
                        endforeach;
                    endif;
                    ?>

                    <!-- Create new note form -->
                    <?php
                    // Get the emisor_id for single factura (parent emisor)
                    $single_emisor_id = 0;
                    $parent_emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
                    if ($parent_emisor) {
                        $single_emisor_id = $parent_emisor->id;
                    }
                    ?>
                    <details style="border: 1px solid #ddd; border-radius: 4px; padding: 10px; margin-bottom: 10px; background: #fafafa;">
                        <summary style="cursor: pointer; font-weight: 600; font-size: 12px; color: #2271b1;">
                            <?php esc_html_e('+ Generar nueva nota', 'fe-woo'); ?>
                        </summary>

                        <div class="fe-woo-nota-form-container" style="margin-top: 12px;">
                            <!-- Note Type Selection -->
                            <p style="margin: 8px 0;">
                                <label style="display: block; font-weight: 600; font-size: 11px; margin-bottom: 4px;">
                                    <?php esc_html_e('Tipo de nota:', 'fe-woo'); ?>
                                </label>
                                <select class="fe-woo-note-type widefat" style="font-size: 12px;">
                                    <option value="nota_credito"><?php esc_html_e('Nota de Crédito', 'fe-woo'); ?></option>
                                    <option value="nota_debito"><?php esc_html_e('Nota de Débito', 'fe-woo'); ?></option>
                                </select>
                            </p>

                            <!-- Reference Code Selection -->
                            <p style="margin: 8px 0;">
                                <label style="display: block; font-weight: 600; font-size: 11px; margin-bottom: 4px;">
                                    <?php esc_html_e('Código de referencia:', 'fe-woo'); ?>
                                </label>
                                <select class="fe-woo-reference-code widefat" style="font-size: 12px;">
                                    <option value="01"><?php esc_html_e('01 - Anula documento de referencia', 'fe-woo'); ?></option>
                                    <option value="02"><?php esc_html_e('02 - Corrige texto documento referencia', 'fe-woo'); ?></option>
                                    <option value="03"><?php esc_html_e('03 - Corrige monto', 'fe-woo'); ?></option>
                                    <option value="04"><?php esc_html_e('04 - Referencia a otro documento', 'fe-woo'); ?></option>
                                    <option value="05"><?php esc_html_e('05 - Sustituye comprobante provisional por contingencia', 'fe-woo'); ?></option>
                                    <option value="99"><?php esc_html_e('99 - Otros', 'fe-woo'); ?></option>
                                </select>
                            </p>

                            <!-- Reason Text -->
                            <p style="margin: 8px 0;">
                                <label style="display: block; font-weight: 600; font-size: 11px; margin-bottom: 4px;">
                                    <?php esc_html_e('Razón (máx. 180 caracteres):', 'fe-woo'); ?>
                                </label>
                                <textarea class="fe-woo-note-reason widefat" rows="3" maxlength="180" placeholder="<?php esc_attr_e('Describa la razón de la nota...', 'fe-woo'); ?>" style="font-size: 12px;"></textarea>
                                <span class="fe-woo-reason-counter" style="font-size: 10px; color: #666;">0/180</span>
                            </p>

                            <!-- Additional Notes -->
                            <p style="margin: 8px 0;">
                                <label style="display: block; font-weight: 600; font-size: 11px; margin-bottom: 4px;">
                                    <?php esc_html_e('Notas adicionales (opcional):', 'fe-woo'); ?>
                                </label>
                                <textarea class="fe-woo-note-additional widefat" rows="2" placeholder="<?php esc_attr_e('Notas internas adicionales...', 'fe-woo'); ?>" style="font-size: 12px;"></textarea>
                            </p>

                            <!-- Generate Button -->
                            <p style="margin-top: 12px;">
                                <button type="button" class="button button-primary fe-woo-generate-note" data-order-id="<?php echo esc_attr($order_id); ?>" data-clave="<?php echo esc_attr($clave); ?>" data-emisor-id="<?php echo esc_attr($single_emisor_id); ?>" style="width: 100%; font-weight: 600;">
                                    <?php esc_html_e('Generar Nota', 'fe-woo'); ?>
                                </button>
                            </p>

                            <div class="fe-woo-note-message" style="margin-top: 10px; padding: 8px; border-radius: 4px; font-size: 12px; display: none;"></div>
                        </div>
                    </details>
                </div>
                <?php endif; ?>

            <?php endif; ?>
        </div>

        <style>
            .fe-woo-status-badge,
            .fe-woo-hacienda-status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }

            .fe-woo-status-sent {
                background: #c8e6c9;
                color: #2e7d32;
            }

            .fe-woo-status-pending {
                background: #fff9c4;
                color: #f57f17;
            }

            .fe-woo-status-failed {
                background: #ffcdd2;
                color: #c62828;
            }

            .fe-woo-hacienda-aceptado,
            .fe-woo-hacienda-procesando {
                background: #c8e6c9;
                color: #2e7d32;
            }

            .fe-woo-hacienda-rechazado {
                background: #ffcdd2;
                color: #c62828;
            }

            /* Queue status badges */
            .fe-woo-queue-status-pending,
            .fe-woo-queue-status-retry {
                background: #fff3cd;
                color: #856404;
            }

            .fe-woo-queue-status-processing {
                background: #cce5ff;
                color: #004085;
            }

            .fe-woo-queue-status-completed {
                background: #d4edda;
                color: #155724;
            }

            .fe-woo-queue-status-failed {
                background: #f8d7da;
                color: #721c24;
            }

            .fe-woo-status-message.success {
                color: #2e7d32;
                padding: 8px;
                background: #c8e6c9;
                border-radius: 3px;
            }

            .fe-woo-status-message.error {
                color: #c62828;
                padding: 8px;
                background: #ffcdd2;
                border-radius: 3px;
            }
        </style>
        <?php
    }

    /**
     * Add factura column to orders list
     *
     * @param array $columns Existing columns
     * @return array Modified columns
     */
    public static function add_factura_column($columns) {
        $new_columns = [];

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            // Add after order status column
            if ($key === 'order_status') {
                $new_columns['factura_status'] = __('Factura Status', 'fe-woo');
            }
        }

        return $new_columns;
    }

    /**
     * Render factura column content (HPOS)
     *
     * @param string   $column Column name
     * @param WC_Order $order Order object
     */
    public static function render_factura_column($column, $order) {
        if ($column === 'factura_status') {
            $order_obj = $order instanceof WC_Order ? $order : wc_get_order($order);
            self::output_factura_status($order_obj);
        }
    }

    /**
     * Render factura column content (Legacy)
     *
     * @param string $column Column name
     * @param int    $post_id Post ID
     */
    public static function render_factura_column_legacy($column, $post_id) {
        if ($column === 'factura_status') {
            $order = wc_get_order($post_id);
            self::output_factura_status($order);
        }
    }

    /**
     * Output factura status for order list
     *
     * @param WC_Order $order Order object
     */
    private static function output_factura_status($order) {
        if (!$order) {
            echo '<span style="color: #999;">-</span>';
            return;
        }

        $clave = $order->get_meta('_fe_woo_factura_clave');
        $status = $order->get_meta('_fe_woo_factura_status');
        $hacienda_status = $order->get_meta('_fe_woo_hacienda_status');

        if (empty($clave)) {
            echo '<span style="color: #999;">' . esc_html__('No enviada', 'fe-woo') . '</span>';
            return;
        }

        $display_status = $hacienda_status ? $hacienda_status : $status;
        $label = $hacienda_status ? self::get_hacienda_status_label($hacienda_status) : self::get_status_label($status);

        echo '<span class="fe-woo-status-badge fe-woo-status-' . esc_attr($display_status) . '">';
        echo esc_html($label);
        echo '</span>';

        if ($hacienda_status === 'aceptado') {
            echo ' <span class="dashicons dashicons-yes-alt" style="color: #2e7d32;"></span>';
        } elseif ($hacienda_status === 'rechazado') {
            echo ' <span class="dashicons dashicons-dismiss" style="color: #c62828;"></span>';
        }
    }

    /**
     * Get human-readable label for local status
     *
     * @param string $status Status code
     * @return string Status label
     */
    private static function get_status_label($status) {
        $labels = [
            'pending' => __('Pendiente', 'fe-woo'),
            'sent' => __('Enviada', 'fe-woo'),
            'failed' => __('Fallida', 'fe-woo'),
        ];

        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    /**
     * Get human-readable label for Hacienda status
     *
     * @param string $status Status code from Hacienda
     * @return string Status label
     */
    private static function get_hacienda_status_label($status) {
        $labels = [
            'aceptado' => __('Aceptado', 'fe-woo'),
            'rechazado' => __('Rechazado', 'fe-woo'),
            'procesando' => __('Procesando', 'fe-woo'),
            'error' => __('Error', 'fe-woo'),
        ];

        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    /**
     * Get human-readable label for queue status
     *
     * @param string $status Queue status code
     * @return string Status label
     */
    private static function get_queue_status_label($status) {
        $labels = [
            FE_Woo_Queue::STATUS_PENDING => __('En Cola', 'fe-woo'),
            FE_Woo_Queue::STATUS_PROCESSING => __('Procesando', 'fe-woo'),
            FE_Woo_Queue::STATUS_COMPLETED => __('Procesado', 'fe-woo'),
            FE_Woo_Queue::STATUS_FAILED => __('Error', 'fe-woo'),
            FE_Woo_Queue::STATUS_RETRY => __('Reintentando', 'fe-woo'),
        ];

        return isset($labels[$status]) ? $labels[$status] : ucfirst($status);
    }

    /**
     * AJAX handler to check factura status
     */
    public static function ajax_check_factura_status() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de orden inválido', 'fe-woo')]);
        }

        $result = self::check_and_update_factura_status($order_id);

        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }

    /**
     * AJAX handler to refresh factura status (alias for check)
     */
    public static function ajax_refresh_factura_status() {
        self::ajax_check_factura_status();
    }

    /**
     * Check and update factura status for an order
     *
     * @param int $order_id Order ID
     * @return array Result array with success boolean and data
     */
    public static function check_and_update_factura_status($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Orden no encontrada', 'fe-woo'),
            ];
        }

        $clave = $order->get_meta('_fe_woo_factura_clave');

        if (empty($clave)) {
            return [
                'success' => false,
                'message' => __('No se encontró clave de factura para esta orden', 'fe-woo'),
            ];
        }

        // Query Hacienda API
        $api_client = new FE_Woo_API_Client();
        $response = $api_client->query_invoice_status($clave);

        // Update last checked timestamp
        $order->update_meta_data('_fe_woo_status_last_checked', current_time('mysql'));

        if (!$response['success']) {
            $order->save();
            return [
                'success' => false,
                'message' => $response['message'] ?? __('Error al consultar estado', 'fe-woo'),
            ];
        }

        // Extract status from response
        $hacienda_status = isset($response['status']) ? strtolower($response['status']) : 'unknown';

        // Update order meta
        $order->update_meta_data('_fe_woo_hacienda_status', $hacienda_status);
        $order->update_meta_data('_fe_woo_hacienda_response', $response['data']);
        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Factura status checked: %s', 'fe-woo'),
                self::get_hacienda_status_label($hacienda_status)
            )
        );

        return [
            'success' => true,
            'message' => sprintf(
                __('Estado actualizado: %s', 'fe-woo'),
                self::get_hacienda_status_label($hacienda_status)
            ),
            'status' => $hacienda_status,
            'status_label' => self::get_hacienda_status_label($hacienda_status),
        ];
    }

    /**
     * AJAX handler to manually execute factura generation
     *
     * Processes the invoice immediately (synchronously), not via queue
     * Only allows execution if order status is "completed"
     */
    /**
     * Validate that the order's receptor metadata is complete enough to
     * generate a factura electrónica. Returns a list of human-readable labels
     * for missing/invalid fields. Empty array means valid.
     *
     * Critical fields (always required):
     *   - Tipo de Identificación, Número de Identificación
     *   - Nombre Completo o Razón Social
     *   - Correo Electrónico para Factura
     *   - Provincia, Cantón, Distrito, Otras Señas (XSD UbicacionType)
     *
     * Conditional:
     *   - Código de actividad económica when id_type is "02" (Cédula Jurídica).
     *
     * Telefono is intentionally optional: XSD v4.4 marks it minOccurs=0 and
     * the checkout/POS forms allow it blank.
     *
     * @param WC_Order $order
     * @return array<int,string> Labels of missing fields (empty if valid).
     */
    private static function validate_receptor_data($order) {
        $missing = [];

        // Si el checkbox FE no está marcado la orden va como Tiquete Electrónico
        // (TiqueteElectronico v4.4) que NO emite bloque Receptor — saltar la
        // validación entera. El downstream (process_order_immediately) ya
        // elige factura vs tiquete a partir de esta misma meta.
        if ($order->get_meta('_fe_woo_require_factura') !== 'yes') {
            return [];
        }

        $required = [
            '_fe_woo_id_type'       => __('Tipo de Identificación', 'fe-woo'),
            '_fe_woo_id_number'     => __('Número de Identificación', 'fe-woo'),
            '_fe_woo_full_name'     => __('Nombre Completo o Razón Social', 'fe-woo'),
            '_fe_woo_invoice_email' => __('Correo Electrónico para Factura', 'fe-woo'),
            '_fe_woo_provincia'     => __('Provincia', 'fe-woo'),
            '_fe_woo_canton'        => __('Cantón', 'fe-woo'),
            '_fe_woo_distrito'      => __('Distrito', 'fe-woo'),
            // _fe_woo_otras_senas: NO requerido aquí. build_otras_senas_effective()
            // concatena un suffix invisible al emitir el XML, así que el meta
            // del cliente puede estar vacío sin riesgo de violar el XSD v4.4.
        ];

        foreach ($required as $meta_key => $label) {
            $value = trim((string) $order->get_meta($meta_key));
            if ($value === '') {
                $missing[] = $label;
            }
        }

        // Activity code receptor — opcional según XSD v4.4 (CodigoActividadReceptor
        // tiene minOccurs=0). Hacienda valida solo el CodigoActividadEmisor en
        // el comprobante. Auto-rellenarlo desde el catálogo de autocompletar
        // produjo rechazos -411 (catálogo de autocompletar ≠ catálogo de
        // recepción). Lo dejamos opcional: se emite solo si el cliente lo
        // escribe conscientemente.

        // If all three location codes are present, validate the combination
        // exists in the catalog so we don't ship junk to Hacienda.
        $prov = (string) $order->get_meta('_fe_woo_provincia');
        $cant = (string) $order->get_meta('_fe_woo_canton');
        $dist = (string) $order->get_meta('_fe_woo_distrito');
        if ($prov !== '' && $cant !== '' && $dist !== ''
            && class_exists('FE_Woo_CR_Locations')
            && !FE_Woo_CR_Locations::validate($prov, $cant, $dist)
        ) {
            $missing[] = __('Combinación de provincia/cantón/distrito no es válida', 'fe-woo');
        }

        // Otras Señas: validamos el VALOR EFECTIVO (cliente + suffix, truncado
        // a 250) que se enviará a Hacienda, no el meta crudo. Por design el
        // efectivo siempre cumple 5 ≤ length ≤ 250, pero validamos por defensa.
        $otras_effective = FE_Woo_Factura_Generator::build_otras_senas_effective(
            $order->get_meta('_fe_woo_otras_senas')
        );
        $otras_len = function_exists('mb_strlen') ? mb_strlen($otras_effective) : strlen($otras_effective);
        if ($otras_len < 5 || $otras_len > 250) {
            $missing[] = __('Otras Señas (longitud inválida)', 'fe-woo');
        }

        return $missing;
    }

    public static function ajax_manual_execute_factura() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de orden inválido', 'fe-woo')]);
        }

        // Lock antes de cualquier mutación para serializar concurrencia entre
        // tabs/refresh. Si la orden ya tiene una operación FE en proceso
        // (Reintentar / Ejecutar / reexecute), abortamos limpio.
        if (class_exists('FE_Woo_Order_Lock')) {
            if (!FE_Woo_Order_Lock::acquire($order_id, 'manual_execute')) {
                $existing = FE_Woo_Order_Lock::inspect($order_id);
                wp_send_json_error([
                    'message' => sprintf(
                        __('La orden #%d ya tiene una operación FE en proceso (%s). Espera unos segundos y recarga la página.', 'fe-woo'),
                        $order_id,
                        $existing['operation'] ?? 'unknown'
                    ),
                    'lock_remaining' => $existing['remaining'] ?? 0,
                ]);
            }
            // shutdown hook: garantiza liberación incluso si wp_die corta el script.
            register_shutdown_function(function () use ($order_id) {
                FE_Woo_Order_Lock::release($order_id);
            });
        }

        // Verify order exists and status is completed
        $order = wc_get_order($order_id);

        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'fe-woo')]);
        }

        if ($order->get_status() !== 'completed') {
            wp_send_json_error([
                'message' => sprintf(
                    __('La facturación electrónica solo se puede ejecutar cuando el estado de la orden sea "Completado". Estado actual: %s', 'fe-woo'),
                    wc_get_order_status_name($order->get_status())
                ),
            ]);
        }

        // Pre-flight: validar que el Receptor tiene todos los datos requeridos
        // ANTES de tocar la cola o llamar a Hacienda. Si falta algo respondemos
        // con la lista de campos faltantes y no se gasta una llamada.
        $missing = self::validate_receptor_data($order);
        if (!empty($missing)) {
            wp_send_json_error([
                'message' => __('No se puede generar la factura electrónica. Faltan datos del receptor:', 'fe-woo')
                    . ' ' . implode(', ', $missing) . '.',
                'missing_fields' => $missing,
            ]);
        }

        // Process order immediately (this will also remove from queue if exists).
        // skip_lock=true: el lock ya fue adquirido más arriba con FE_Woo_Order_Lock::acquire().
        $result = FE_Woo_Queue_Processor::process_order_immediately($order_id, false, true);

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'clave' => isset($result['clave']) ? $result['clave'] : null,
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * AJAX handler for the "Volver a consultar a Hacienda" button.
     *
     * Purely a re-query — it does NOT resend or regenerate anything.
     * If the document is "rechazado" the verdict stays rechazado unless
     * Hacienda itself changed it server-side. To retry a rejected
     * document with new data, the operator fixes the emisor config and
     * uses the "Ejecutar" button (which goes through the queue and
     * generates a fresh clave).
     */
    public static function ajax_recheck_status() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de orden inválido', 'fe-woo')]);
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'fe-woo')]);
        }

        $clave = $order->get_meta('_fe_woo_factura_clave');
        if (!$clave) {
            wp_send_json_error(['message' => __('La orden no tiene clave asociada.', 'fe-woo')]);
        }

        try {
            $client = new FE_Woo_API_Client();
            $result = $client->query_invoice_status($clave);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        if (empty($result['success'])) {
            $error_msg = isset($result['message']) ? $result['message'] : (isset($result['error']) ? $result['error'] : __('Error al consultar a Hacienda', 'fe-woo'));
            wp_send_json_error(['message' => $error_msg]);
        }

        $saved = FE_Woo_Queue_Processor::save_acuse_xml_from_response($order_id, $clave, $result);
        $payload = isset($result['data']) && is_array($result['data']) ? $result['data'] : [];
        $ind = isset($payload['ind-estado']) ? strtolower((string) $payload['ind-estado']) : '';

        $order = wc_get_order($order_id);
        wp_send_json_success([
            'saved_xml'       => $saved,
            'hacienda_status' => $order->get_meta('_fe_woo_hacienda_status'),
            'estado_mensaje'  => $order->get_meta('_fe_woo_hacienda_estado_mensaje'),
            'detalle'         => $order->get_meta('_fe_woo_hacienda_detalle'),
            'ind_estado'      => $ind,
            'message'         => $saved
                ? __('Consulta completada. La página se actualizará.', 'fe-woo')
                : ($ind ? sprintf(__('Hacienda reporta estado: %s', 'fe-woo'), $ind) : __('Hacienda aún no tiene respuesta final.', 'fe-woo')),
        ]);
    }

    /**
     * AJAX handler for "Reintentar con datos actualizados" (rejected-only).
     *
     * A rejected comprobante has no fiscal existence and its clave is
     * final on Hacienda's side. The operator typically gets here after
     * fixing the emisor config (cédula, actividad económica, ubicación),
     * and needs to re-submit as a fresh document. We wipe the previous
     * attempt's meta and run the full immediate-processing pipeline,
     * which generates a new clave with the current emisor data, signs
     * it, POSTs it, and polls for the verdict inline.
     */
    public static function ajax_retry_with_updated_data() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        if (!$order_id) {
            wp_send_json_error(['message' => __('ID de orden inválido', 'fe-woo')]);
        }

        // Lock para serializar Reintentar contra Ejecutar/otro Reintentar.
        if (class_exists('FE_Woo_Order_Lock')) {
            if (!FE_Woo_Order_Lock::acquire($order_id, 'retry')) {
                $existing = FE_Woo_Order_Lock::inspect($order_id);
                wp_send_json_error([
                    'message' => sprintf(
                        __('La orden #%d ya tiene una operación FE en proceso (%s). Espera unos segundos y recarga la página.', 'fe-woo'),
                        $order_id,
                        $existing['operation'] ?? 'unknown'
                    ),
                    'lock_remaining' => $existing['remaining'] ?? 0,
                ]);
            }
            register_shutdown_function(function () use ($order_id) {
                FE_Woo_Order_Lock::release($order_id);
            });
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(['message' => __('Orden no encontrada', 'fe-woo')]);
        }

        // Safety check — this action only applies to rejected documents.
        // Processing or accepted orders shouldn't hit this path; if they
        // do, refuse rather than silently clobbering a valid factura.
        $current_status = strtolower((string) $order->get_meta('_fe_woo_hacienda_status'));
        if ($current_status !== 'rechazado') {
            wp_send_json_error([
                'message' => sprintf(
                    __('Solo se puede reintentar cuando el estado es "rechazado". Estado actual: %s', 'fe-woo'),
                    $current_status ?: 'desconocido'
                ),
            ]);
        }

        $meta_to_clear = [
            '_fe_woo_factura_clave',
            '_fe_woo_factura_xml',
            '_fe_woo_hacienda_status',
            '_fe_woo_hacienda_estado_mensaje',
            '_fe_woo_hacienda_detalle',
            '_fe_woo_hacienda_response',
            '_fe_woo_acuse_xml_file_path',
            '_fe_woo_acuse_file_path',
            '_fe_woo_xml_file_path',
            '_fe_woo_pdf_file_path',
            '_fe_woo_factura_sent_date',
            '_fe_woo_status_last_checked',
        ];
        foreach ($meta_to_clear as $k) {
            $order->delete_meta_data($k);
        }
        $order->save();

        try {
            // skip_lock=true: el lock ya fue adquirido más arriba con FE_Woo_Order_Lock::acquire().
            $result = FE_Woo_Queue_Processor::process_order_immediately($order_id, true, true);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }

        $order = wc_get_order($order_id);
        $new_status = $order->get_meta('_fe_woo_hacienda_status');
        wp_send_json_success([
            'hacienda_status' => $new_status,
            'estado_mensaje'  => $order->get_meta('_fe_woo_hacienda_estado_mensaje'),
            'message'         => $new_status === 'aceptado'
                ? __('Factura reprocesada y aceptada por Hacienda.', 'fe-woo')
                : __('Factura reprocesada. Revisa el nuevo estado.', 'fe-woo'),
        ]);
    }

    /**
     * AJAX handler to download all documents as ZIP
     */
    public static function ajax_download_all_documents() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $clave = isset($_POST['clave']) ? sanitize_text_field($_POST['clave']) : '';

        if (!$order_id || !$clave) {
            wp_send_json_error(['message' => __('Parámetros inválidos', 'fe-woo')]);
        }

        // Create ZIP file
        $zip_result = self::create_documents_zip($order_id, $clave);

        if (!$zip_result['success']) {
            wp_send_json_error(['message' => $zip_result['error']]);
        }

        wp_send_json_success([
            'download_url' => $zip_result['download_url'],
        ]);
    }

    /**
     * AJAX handler to download all multi-factura documents as ZIP
     */
    public static function ajax_download_all_multi_factura() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;

        if (!$order_id) {
            wp_send_json_error(['message' => __('Parámetros inválidos', 'fe-woo')]);
        }

        // Create ZIP file with all multi-factura documents
        $zip_result = self::create_multi_factura_zip($order_id);

        if (!$zip_result['success']) {
            wp_send_json_error(['message' => $zip_result['error']]);
        }

        wp_send_json_success([
            'download_url' => $zip_result['download_url'],
        ]);
    }

    /**
     * Create ZIP file with all multi-factura documents
     *
     * @param int $order_id Order ID
     * @return array Result with 'success' and 'download_url' or 'error'
     */
    private static function create_multi_factura_zip($order_id) {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'error' => __('La extensión ZIP no está disponible en este servidor', 'fe-woo'),
            ];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'success' => false,
                'error' => __('Orden no encontrada', 'fe-woo'),
            ];
        }

        // Get all generated facturas
        $facturas_generated = $order->get_meta('_fe_woo_facturas_generated');
        if (empty($facturas_generated) || !is_array($facturas_generated)) {
            return [
                'success' => false,
                'error' => __('No se encontraron facturas generadas para esta orden', 'fe-woo'),
            ];
        }

        $files_to_zip = [];

        // Collect all documents from all facturas
        foreach ($facturas_generated as $index => $factura) {
            $clave = $factura['clave'];
            $emisor_name = sanitize_file_name($factura['emisor_name']);
            $factura_type = isset($factura['type']) ? $factura['type'] : 'factura';

            // Create a prefix for the files to organize them
            $prefix = sprintf('%d-%s-%s', $index + 1, $emisor_name, $factura_type);

            // Get document paths for this factura
            $document_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $clave);

            // Add XML
            if (!empty($document_paths['xml']) && file_exists($document_paths['xml'])) {
                $files_to_zip[$prefix . '-factura.xml'] = $document_paths['xml'];
            }

            // Add PDF
            if (!empty($document_paths['pdf']) && file_exists($document_paths['pdf'])) {
                $ext = pathinfo($document_paths['pdf'], PATHINFO_EXTENSION);
                $files_to_zip[$prefix . '-factura.' . $ext] = $document_paths['pdf'];
            }

            // Add Acuse de Hacienda (AHC) — signed MensajeHacienda
            if (!empty($document_paths['acuse_xml']) && file_exists($document_paths['acuse_xml'])) {
                $files_to_zip[$prefix . '-AHC.xml'] = $document_paths['acuse_xml'];
            }
        }

        if (empty($files_to_zip)) {
            return [
                'success' => false,
                'error' => __('No se encontraron documentos para descargar', 'fe-woo'),
            ];
        }

        // Create temporary ZIP file
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'fe-woo-temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $zip_filename = 'order-' . $order_id . '-all-facturas-' . time() . '.zip';
        $zip_path = trailingslashit($temp_dir) . $zip_filename;

        // Create ZIP
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'success' => false,
                'error' => __('Error al crear archivo ZIP', 'fe-woo'),
            ];
        }

        // Add files to ZIP with organized names
        foreach ($files_to_zip as $zip_name => $file_path) {
            $zip->addFile($file_path, $zip_name);
        }

        $zip->close();

        // Generate download URL
        $download_url = add_query_arg([
            'action' => 'fe_woo_download_zip',
            'order_id' => $order_id,
            'zip_file' => $zip_filename,
            'nonce' => wp_create_nonce('fe_woo_download_zip_' . $order_id),
        ], admin_url('admin-ajax.php'));

        return [
            'success' => true,
            'download_url' => $download_url,
            'zip_path' => $zip_path,
            'files_count' => count($files_to_zip),
        ];
    }

    /**
     * Create ZIP file with all documents
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return array Result with 'success' and 'download_url' or 'error'
     */
    private static function create_documents_zip($order_id, $clave) {
        // Check if ZipArchive is available
        if (!class_exists('ZipArchive')) {
            return [
                'success' => false,
                'error' => __('La extensión ZIP no está disponible en este servidor', 'fe-woo'),
            ];
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            return [
                'success' => false,
                'error' => __('Orden no encontrada', 'fe-woo'),
            ];
        }

        // Get all document paths for the specified clave
        $document_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $clave);

        // Filter out null paths and exclude JSON response file (acuse)
        $files_to_zip = array_filter($document_paths, function($path, $key) {
            return $path !== null && file_exists($path) && $key !== 'acuse';
        }, ARRAY_FILTER_USE_BOTH);

        // Check if this is the main invoice/ticket or a note
        $main_clave = $order->get_meta('_fe_woo_factura_clave');
        $is_main_invoice = ($clave === $main_clave);

        // If this is the main invoice, also add all notes
        if ($is_main_invoice) {
            $notas = $order->get_meta('_fe_woo_notas');
            if (is_array($notas) && !empty($notas)) {
                foreach ($notas as $nota) {
                    $nota_clave = $nota['clave'];
                    $nota_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $nota_clave);

                    // Add note XML
                    if (!empty($nota_paths['xml']) && file_exists($nota_paths['xml'])) {
                        $files_to_zip['nota_' . $nota_clave . '_xml'] = $nota_paths['xml'];
                    }

                    // Add note PDF
                    if (!empty($nota_paths['pdf']) && file_exists($nota_paths['pdf'])) {
                        $files_to_zip['nota_' . $nota_clave . '_pdf'] = $nota_paths['pdf'];
                    }

                    // Add note's Acuse de Hacienda (AHC)
                    $nota_acuse = FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $nota_clave);
                    if ($nota_acuse) {
                        $files_to_zip['nota_' . $nota_clave . '_AHC'] = $nota_acuse;
                    }
                }
            }
        } else {
            // This is a note ZIP — add the Acuse de Hacienda for it.
            $nota_acuse = FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave);
            if ($nota_acuse) {
                $files_to_zip['AHC'] = $nota_acuse;
            }
        }

        if (empty($files_to_zip)) {
            return [
                'success' => false,
                'error' => __('No se encontraron documentos para esta orden', 'fe-woo'),
            ];
        }

        // Create temporary ZIP file
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'fe-woo-temp';

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }

        $zip_filename = 'order-' . $order_id . '-' . sanitize_file_name($clave) . '-' . time() . '.zip';
        $zip_path = trailingslashit($temp_dir) . $zip_filename;

        // Create ZIP
        $zip = new ZipArchive();
        if ($zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [
                'success' => false,
                'error' => __('Error al crear archivo ZIP', 'fe-woo'),
            ];
        }

        // Add files to ZIP
        foreach ($files_to_zip as $file_path) {
            $filename = basename($file_path);
            $zip->addFile($file_path, $filename);
        }

        $zip->close();

        // Generate download URL
        $download_url = add_query_arg([
            'action' => 'fe_woo_download_zip',
            'order_id' => $order_id,
            'zip_file' => $zip_filename,
            'nonce' => wp_create_nonce('fe_woo_download_zip_' . $order_id),
        ], admin_url('admin-ajax.php'));

        return [
            'success' => true,
            'download_url' => $download_url,
            'zip_path' => $zip_path,
        ];
    }

    /**
     * AJAX handler to serve ZIP file download
     */
    public static function ajax_download_zip() {
        // Verify user has permission
        if (!current_user_can('edit_shop_orders')) {
            wp_die(__('No tiene permiso para descargar este archivo.', 'fe-woo'), 403);
        }

        // Get parameters
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $zip_file = isset($_GET['zip_file']) ? sanitize_file_name($_GET['zip_file']) : '';
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'fe_woo_download_zip_' . $order_id)) {
            wp_die(__('Token de seguridad inválido.', 'fe-woo'), 403);
        }

        // Get ZIP file path
        $upload_dir = wp_upload_dir();
        $temp_dir = trailingslashit($upload_dir['basedir']) . 'fe-woo-temp';
        $zip_path = trailingslashit($temp_dir) . $zip_file;

        // Verify file exists
        if (!file_exists($zip_path)) {
            wp_die(__('Archivo ZIP no encontrado.', 'fe-woo'), 404);
        }

        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers for download
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $zip_file . '"');
        header('Content-Length: ' . filesize($zip_path));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Output file
        readfile($zip_path);

        // Delete temporary ZIP file after download
        unlink($zip_path);

        exit;
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Page hook
     */
    public static function enqueue_admin_scripts($hook) {
        // Only load on order edit pages
        if (!in_array($hook, ['post.php', 'post-new.php', 'woocommerce_page_wc-orders', 'edit.php'])) {
            return;
        }

        $screen = get_current_screen();
        if (!$screen || !in_array($screen->id, ['shop_order', 'woocommerce_page_wc-orders', 'edit-shop_order'])) {
            return;
        }

        // Enqueue JavaScript
        wp_enqueue_script(
            'fe-woo-order-admin',
            FE_WOO_PLUGIN_URL . 'assets/js/order-admin.js',
            ['jquery'],
            FE_WOO_VERSION,
            true
        );

        // Localize script with AJAX URL and nonce
        wp_localize_script('fe-woo-order-admin', 'feWooOrderAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fe_woo_admin'),
            'i18n' => [
                'executing' => __('Ejecutando facturación...', 'fe-woo'),
                'preparingZip' => __('Preparando descarga...', 'fe-woo'),
                'error' => __('Error', 'fe-woo'),
                'success' => __('Éxito', 'fe-woo'),
                'reexecuting' => __('Re-encolando...', 'fe-woo'),
                'reexecuteConfirm' => __('Esto eliminará los archivos generados (XML, PDF, acuse) y regresará la orden a la cola para volver a procesarla. ¿Continuar?', 'fe-woo'),
            ],
        ]);

        // Add inline CSS for button hover states
        wp_add_inline_style('wp-admin', '
            .fe-woo-ejecutar-factura:hover {
                background: #b71c1c !important;
                border-color: #9a0007 !important;
            }
            .fe-woo-download-all:hover {
                background: #135e96 !important;
                border-color: #135e96 !important;
                color: white !important;
            }
            .fe-woo-ejecutar-factura:disabled,
            .fe-woo-download-all:disabled,
            .fe-woo-download-nota-docs:disabled {
                opacity: 0.6;
                cursor: not-allowed;
            }
            @keyframes rotation {
                from {
                    transform: rotate(0deg);
                }
                to {
                    transform: rotate(359deg);
                }
            }
        ');
    }

    /**
     * AJAX handler for generating credit/debit notes
     *
     * Delegates to FE_Woo_Nota_Manager for actual processing.
     * Supports per-factura nota generation with emisor-specific handling.
     */
    public static function ajax_generate_nota() {
        // Verify nonce
        check_ajax_referer('fe_woo_admin', 'nonce');

        // Check permissions
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error([
                'message' => __('You do not have permission to perform this action.', 'fe-woo'),
            ]);
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            wp_send_json_error([
                'message' => $ready_status['message'],
            ]);
        }

        // Get POST data
        $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
        $note_type = isset($_POST['note_type']) ? sanitize_text_field($_POST['note_type']) : '';
        $reference_code = isset($_POST['reference_code']) ? sanitize_text_field($_POST['reference_code']) : '';
        $reason = isset($_POST['reason']) ? sanitize_text_field($_POST['reason']) : '';
        $additional_notes = isset($_POST['additional_notes']) ? sanitize_textarea_field($_POST['additional_notes']) : '';
        $referenced_clave = isset($_POST['referenced_clave']) ? sanitize_text_field($_POST['referenced_clave']) : '';
        $emisor_id = isset($_POST['emisor_id']) ? intval($_POST['emisor_id']) : 0;

        // Validate note_type early
        if (!in_array($note_type, ['nota_credito', 'nota_debito'], true)) {
            wp_send_json_error([
                'message' => __('Tipo de nota inválido.', 'fe-woo'),
            ]);
        }

        // Validate inputs
        if (!$order_id || !$note_type || !$reference_code || !$reason || !$referenced_clave) {
            wp_send_json_error([
                'message' => __('Missing required fields.', 'fe-woo'),
            ]);
        }

        // Get order
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error([
                'message' => __('Order not found.', 'fe-woo'),
            ]);
        }

        // If no emisor_id provided, resolve from the referenced factura
        if (!$emisor_id) {
            $emisor_id = FE_Woo_Nota_Manager::get_emisor_for_factura($order, $referenced_clave);
        }

        // Delegate to Nota Manager
        $result = FE_Woo_Nota_Manager::generate_nota($order, [
            'note_type' => $note_type,
            'reference_code' => $reference_code,
            'reason' => $reason,
            'additional_notes' => $additional_notes,
            'referenced_clave' => $referenced_clave,
            'emisor_id' => $emisor_id,
            'use_queue' => false, // Immediate processing for manual AJAX requests
        ]);

        if ($result['success']) {
            wp_send_json_success([
                'message' => $result['message'],
                'clave' => isset($result['clave']) ? $result['clave'] : '',
                'download_url' => isset($result['download_url']) ? $result['download_url'] : '',
                'hacienda_sent' => isset($result['hacienda_sent']) ? $result['hacienda_sent'] : false,
            ]);
        } else {
            wp_send_json_error([
                'message' => $result['message'],
            ]);
        }
    }

    /**
     * AJAX handler for downloading documents of a single note
     */
    public static function ajax_download_nota_docs() {
        check_ajax_referer('fe_woo_admin', 'nonce');

        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(['message' => __('Permiso denegado', 'fe-woo')]);
        }

        $order_id = isset($_POST['order_id']) ? absint($_POST['order_id']) : 0;
        $clave = isset($_POST['clave']) ? sanitize_text_field($_POST['clave']) : '';

        if (!$order_id || !$clave) {
            wp_send_json_error(['message' => __('Parámetros inválidos', 'fe-woo')]);
        }

        // Create ZIP file with this note's documents
        $zip_result = self::create_documents_zip($order_id, $clave);

        if (!$zip_result['success']) {
            wp_send_json_error(['message' => $zip_result['error']]);
        }

        wp_send_json_success([
            'download_url' => $zip_result['download_url'],
        ]);
    }

}
