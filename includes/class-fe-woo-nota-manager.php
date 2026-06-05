<?php
/**
 * Nota de Credito/Debito Manager
 *
 * Centralizes all credit/debit note operations: generation, queue integration,
 * auto-cancellation, and traceability (Order <-> Factura <-> Nota).
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Nota_Manager
 *
 * Manages credit and debit note operations for electronic invoicing
 */
class FE_Woo_Nota_Manager {

    /**
     * Initialize hooks
     */
    public static function init() {
        // Auto-generate NC on order cancellation
        add_action('woocommerce_order_status_cancelled', [__CLASS__, 'auto_generate_nc_on_cancel'], 20, 1);
    }

    /**
     * Get all facturas associated with an order (unified format for single and multi)
     *
     * @param WC_Order $order Order object
     * @return array Array of factura data arrays with keys: clave, emisor_id, emisor_name, document_type, sent_date, status, hacienda_status, items (if multi)
     */
    public static function get_order_facturas($order) {
        $facturas = [];
        $is_multi = $order->get_meta('_fe_woo_multi_factura') === 'yes';
        $facturas_generated = $order->get_meta('_fe_woo_facturas_generated');

        if ($is_multi && !empty($facturas_generated) && is_array($facturas_generated)) {
            foreach ($facturas_generated as $factura) {
                $emisor_name = isset($factura['emisor_name']) ? $factura['emisor_name'] : '';
                if (empty($emisor_name) && !empty($factura['emisor_id'])) {
                    $emisor = FE_Woo_Emisor_Manager::get_emisor($factura['emisor_id']);
                    $emisor_name = $emisor ? $emisor->nombre_legal : __('Emisor desconocido', 'fe-woo');
                }

                $facturas[] = [
                    'clave' => $factura['clave'],
                    'emisor_id' => isset($factura['emisor_id']) ? (int) $factura['emisor_id'] : 0,
                    'emisor_name' => $emisor_name,
                    'document_type' => $order->get_meta('_fe_woo_document_type'),
                    'sent_date' => isset($factura['sent_date']) ? $factura['sent_date'] : $order->get_meta('_fe_woo_factura_sent_date'),
                    'status' => isset($factura['status']) ? $factura['status'] : $order->get_meta('_fe_woo_factura_status'),
                    'hacienda_status' => isset($factura['hacienda_status']) ? $factura['hacienda_status'] : $order->get_meta('_fe_woo_hacienda_status'),
                    'monto' => isset($factura['monto']) ? $factura['monto'] : null,
                    'items_count' => isset($factura['items_count']) ? $factura['items_count'] : null,
                ];
            }
        } else {
            // Single factura
            $clave = $order->get_meta('_fe_woo_factura_clave');
            if (!empty($clave)) {
                // Determine emisor_id - for single facturas, use parent emisor
                $parent_emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
                $emisor_id = $parent_emisor ? (int) $parent_emisor->id : 0;
                $emisor_name = $parent_emisor ? $parent_emisor->nombre_legal : '';

                $facturas[] = [
                    'clave' => $clave,
                    'emisor_id' => $emisor_id,
                    'emisor_name' => $emisor_name,
                    'document_type' => $order->get_meta('_fe_woo_document_type'),
                    'sent_date' => $order->get_meta('_fe_woo_factura_sent_date'),
                    'status' => $order->get_meta('_fe_woo_factura_status'),
                    'hacienda_status' => $order->get_meta('_fe_woo_hacienda_status'),
                    'monto' => null,
                    'items_count' => null,
                ];
            }
        }

        return $facturas;
    }

    /**
     * Get the emisor_id for a specific factura clave within an order
     *
     * @param WC_Order $order Order object
     * @param string   $referenced_clave Clave of the factura
     * @return int Emisor ID (0 if not found, falls back to parent)
     */
    public static function get_emisor_for_factura($order, $referenced_clave) {
        $facturas = self::get_order_facturas($order);
        foreach ($facturas as $factura) {
            if ($factura['clave'] === $referenced_clave) {
                return $factura['emisor_id'];
            }
        }

        // Fallback to parent emisor
        $parent = FE_Woo_Emisor_Manager::get_parent_emisor();
        return $parent ? (int) $parent->id : 0;
    }

    /**
     * Check if a nota already exists for a specific factura clave
     *
     * @param WC_Order $order Order object
     * @param string   $referenced_clave Clave of the factura being referenced
     * @param string   $reference_code Optional specific reference code to check (e.g. '01' for anulación)
     * @return bool True if nota already exists
     */
    public static function nota_exists_for_factura($order, $referenced_clave, $reference_code = null) {
        $notas = $order->get_meta('_fe_woo_notas');
        if (!is_array($notas) || empty($notas)) {
            return false;
        }

        foreach ($notas as $nota) {
            if (!isset($nota['referenced_clave']) || $nota['referenced_clave'] !== $referenced_clave) {
                continue;
            }

            // If no specific reference_code, any nota for this factura counts
            if ($reference_code === null) {
                return true;
            }

            // Check specific reference code
            if (isset($nota['reference_code']) && $nota['reference_code'] === $reference_code) {
                return true;
            }
        }

        // Also check queue for pending notas
        return FE_Woo_Queue::nota_exists_in_queue($order->get_id(), $referenced_clave, $reference_code);
    }

    /**
     * Generate a nota de credito or debito for a specific factura
     *
     * @param WC_Order $order Order object
     * @param array    $params {
     *     @type string $note_type       'nota_credito' or 'nota_debito'
     *     @type string $reference_code  '01'-'05', '99'
     *     @type string $reason          Max 180 chars
     *     @type string $additional_notes Optional internal notes
     *     @type string $referenced_clave Clave of the factura being referenced
     *     @type int    $emisor_id       Emisor that issued the original factura
     *     @type array  $line_items      Optional partial items
     *     @type bool   $use_queue       Whether to queue (true) or send immediately (false)
     * }
     * @return array Result with 'success', 'message', 'clave' keys
     */
    public static function generate_nota($order, $params) {
        $note_type = $params['note_type'];
        $reference_code = $params['reference_code'];
        $reason = $params['reason'];
        $additional_notes = isset($params['additional_notes']) ? $params['additional_notes'] : '';
        $referenced_clave = $params['referenced_clave'];
        $emisor_id = isset($params['emisor_id']) ? (int) $params['emisor_id'] : 0;
        $line_items = isset($params['line_items']) ? $params['line_items'] : null;
        $use_queue = isset($params['use_queue']) ? $params['use_queue'] : false;

        // Validate inputs
        if (!in_array($note_type, ['nota_credito', 'nota_debito'], true)) {
            return ['success' => false, 'message' => __('Tipo de nota inválido.', 'fe-woo')];
        }

        if (empty($referenced_clave) || empty($reference_code) || empty($reason)) {
            return ['success' => false, 'message' => __('Faltan campos requeridos.', 'fe-woo')];
        }

        // Resolve emisor
        $emisor = null;
        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        }
        if (!$emisor) {
            $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
        }
        if (!$emisor) {
            return ['success' => false, 'message' => __('No hay emisor configurado.', 'fe-woo')];
        }
        $emisor_id = (int) $emisor->id;

        // Determine referenced document details
        $referenced_date = self::get_factura_sent_date($order, $referenced_clave);
        $original_doc_type = $order->get_meta('_fe_woo_document_type');
        $referenced_type = ($original_doc_type === 'factura') ? '01' : '04';

        // Prepare reference data
        $reference_data = [
            'referenced_clave' => $referenced_clave,
            'referenced_date' => $referenced_date,
            'referenced_type' => $referenced_type,
            'reference_code' => $reference_code,
            'reference_reason' => $reason,
        ];

        // If using queue, add to queue and return
        if ($use_queue) {
            $queue_data = [
                'note_type' => $note_type,
                'reference_code' => $reference_code,
                'reason' => $reason,
                'additional_notes' => $additional_notes,
                'referenced_clave' => $referenced_clave,
                'emisor_id' => $emisor_id,
                'reference_data' => $reference_data,
            ];

            $queue_id = FE_Woo_Queue::add_nota_to_queue($order->get_id(), $queue_data, $note_type, $emisor_id);

            if ($queue_id) {
                $order->add_order_note(
                    sprintf(
                        __('Nota de %s en cola de procesamiento para factura %s', 'fe-woo'),
                        $note_type === 'nota_credito' ? __('Crédito', 'fe-woo') : __('Débito', 'fe-woo'),
                        substr($referenced_clave, -8)
                    )
                );
                return [
                    'success' => true,
                    'message' => __('Nota agregada a la cola de procesamiento.', 'fe-woo'),
                    'queued' => true,
                    'queue_id' => $queue_id,
                ];
            }

            return ['success' => false, 'message' => __('Error al agregar nota a la cola.', 'fe-woo')];
        }

        // Immediate processing
        return self::process_nota($order, [
            'note_type' => $note_type,
            'reference_data' => $reference_data,
            'emisor_id' => $emisor_id,
            'emisor' => $emisor,
            'reason' => $reason,
            'additional_notes' => $additional_notes,
            'reference_code' => $reference_code,
            'referenced_clave' => $referenced_clave,
            'line_items' => $line_items,
        ]);
    }

    /**
     * Process a nota immediately (generate XML, send to Hacienda, save metadata)
     *
     * @param WC_Order $order
     * @param array    $params {
     *     @type string $note_type        Nota type (nota_credito or nota_debito)
     *     @type array  $reference_data   Reference data for the nota
     *     @type int    $emisor_id        Emisor ID
     *     @type object $emisor           Emisor object
     *     @type string $reason           Reason for the nota
     *     @type string $additional_notes Additional notes
     *     @type string $reference_code   Reference code
     *     @type string $referenced_clave Referenced factura clave
     *     @type array  $line_items       Optional line items
     * }
     * @return array Result
     */
    public static function process_nota($order, $params) {
        $note_type = $params['note_type'];
        $reference_data = $params['reference_data'];
        $emisor_id = $params['emisor_id'];
        $emisor = $params['emisor'];
        $reason = $params['reason'];
        $additional_notes = $params['additional_notes'];
        $reference_code = $params['reference_code'];
        $referenced_clave = $params['referenced_clave'];
        $line_items = isset($params['line_items']) ? $params['line_items'] : null;
        $order_id = $order->get_id();

        // For multi-emisor orders, auto-resolve line_items when not provided.
        // This covers both immediate and queue-processed notas.
        if ($line_items === null) {
            $is_multi = $order->get_meta('_fe_woo_multi_factura') === 'yes';
            if ($is_multi && class_exists('FE_Woo_Multi_Factura_Generator')) {
                $items_by_emisor = FE_Woo_Multi_Factura_Generator::get_order_items_by_emisor($order);
                if (isset($items_by_emisor[$emisor_id])) {
                    $line_items = $items_by_emisor[$emisor_id];
                } else {
                    return [
                        'success' => false,
                        'message' => sprintf(
                            __('No se encontraron productos para el emisor %d en esta orden.', 'fe-woo'),
                            $emisor_id
                        ),
                    ];
                }
            }
        }

        try {
            // Generate note XML with emisor
            $result = FE_Woo_Factura_Generator::generate_nota_from_order(
                $order,
                $note_type,
                $reference_data,
                $emisor_id,
                $line_items
            );

            if (!$result['success']) {
                throw new Exception($result['error']);
            }

            $nota_clave = $result['clave'];
            $nota_xml = $result['xml'];

            // Sign note XML with emisor's XAdES-EPES certificate before validation.
            try {
                $nota_xml = FE_Woo_XML_Signer::sign($nota_xml, $emisor->certificate_path, $emisor->certificate_pin);
            } catch (Exception $e) {
                FE_Woo_XML_Signer::dump_unsigned_xml($order_id, $nota_clave, $result['xml']);
                throw new Exception('Error al firmar nota: ' . $e->getMessage());
            }

            // Validate XML against Hacienda v4.4 XSD before submission.
            $validation = FE_Woo_XML_Validator::validate($nota_xml);
            if (!$validation['valid']) {
                throw new Exception('XML inválido vs XSD v4.4: ' . implode(' | ', $validation['errors']));
            }

            // Save XML file
            FE_Woo_Document_Storage::save_xml($order_id, $nota_xml, $nota_clave);

            // Generate and save PDF with emisor data
            $emisor_pdf_data = [
                'nombre_legal' => $emisor->nombre_legal,
                'cedula' => $emisor->cedula_juridica,
                'telefono' => $emisor->telefono ?? '',
                'email' => $emisor->email ?? '',
            ];
            $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $nota_clave, $note_type, false, $line_items, $emisor_pdf_data);
            if ($pdf_result['success']) {
                $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                FE_Woo_Document_Storage::save_pdf($order_id, $pdf_result['pdf_content'], $nota_clave, $is_html);
            }

            // Send to Hacienda using emisor-specific credentials
            $api_client = new FE_Woo_API_Client();
            $hacienda_response = $api_client->send_invoice_with_emisor($nota_xml, $emisor);

            if ($hacienda_response['success']) {
                // Save Hacienda response as JSON (acuse)
                $acuse_result = FE_Woo_Document_Storage::save_acuse($order_id, $hacienda_response, $nota_clave);
                if ($acuse_result['success']) {
                    $order->update_meta_data('_fe_woo_nota_' . $nota_clave . '_acuse_file_path', $acuse_result['file_path']);
                }

                // Persist the signed MensajeHacienda (AHC-) if Hacienda
                // returned it with the POST response; otherwise the
                // consulta poll will pick it up on the next run.
                FE_Woo_Queue_Processor::save_acuse_xml_from_response($order_id, $nota_clave, $hacienda_response);

                $order->add_order_note(
                    sprintf(
                        __('Nota enviada a Hacienda exitosamente. Clave: %s', 'fe-woo'),
                        $nota_clave
                    )
                );
            } else {
                $error_msg = isset($hacienda_response['error']) ? $hacienda_response['error'] : $hacienda_response['message'];
                $order->add_order_note(
                    sprintf(
                        __('Error al enviar nota a Hacienda. Clave: %s. Error: %s', 'fe-woo'),
                        $nota_clave,
                        $error_msg
                    )
                );
            }

            // Save nota to order metadata
            $notas = $order->get_meta('_fe_woo_notas');
            if (!is_array($notas)) {
                $notas = [];
            }

            $document_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $nota_clave);
            $notas[] = [
                'clave' => $nota_clave,
                'type' => $note_type,
                'reference_code' => $reference_code,
                'reason' => $reason,
                'additional_notes' => $additional_notes,
                'referenced_clave' => $referenced_clave,
                'emisor_id' => $emisor_id,
                'created_at' => current_time('mysql'),
                'xml_path' => $document_paths['xml'],
                'pdf_path' => isset($document_paths['pdf']) ? $document_paths['pdf'] : null,
                'hacienda_sent' => $hacienda_response['success'],
                'hacienda_status' => $hacienda_response['success'] ? 'sent' : 'failed',
            ];

            $order->update_meta_data('_fe_woo_notas', $notas);
            $order->save();

            $note_type_label = $note_type === 'nota_credito' ? __('Crédito', 'fe-woo') : __('Débito', 'fe-woo');
            $order->add_order_note(
                sprintf(
                    __('Nota de %s generada. Clave: %s. Razón: %s', 'fe-woo'),
                    $note_type_label,
                    $nota_clave,
                    $reason
                )
            );

            $success_message = sprintf(
                __('Nota de %s generada exitosamente.', 'fe-woo'),
                $note_type_label
            );

            if ($hacienda_response['success']) {
                $success_message .= ' ' . __('Enviada a Hacienda.', 'fe-woo');
            } else {
                $success_message .= ' ' . __('No se pudo enviar a Hacienda. Revisa las notas de la orden para más detalles.', 'fe-woo');
            }

            // Send email to customer with nota documents only if Hacienda accepted
            if ($hacienda_response['success']) {
                self::send_nota_email($order, $nota_clave, $note_type);
            }

            return [
                'success' => true,
                'message' => $success_message,
                'clave' => $nota_clave,
                'hacienda_sent' => $hacienda_response['success'],
                'download_url' => FE_Woo_Document_Storage::get_download_url($order_id, $nota_clave, 'xml'),
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Error al generar nota: %s', 'fe-woo'),
                    $e->getMessage()
                ),
            ];
        }
    }

    /**
     * Auto-generate NC (code 01) for all facturas when order is cancelled
     *
     * Each NC is processed independently via queue. Failures don't affect other NCs.
     *
     * @param int $order_id Order ID
     */
    public static function auto_generate_nc_on_cancel($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        $facturas = self::get_order_facturas($order);
        if (empty($facturas)) {
            return;
        }

        // Check if system is ready
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            $order->add_order_note(
                sprintf(
                    __('No se pudieron generar Notas de Crédito automáticas: %s', 'fe-woo'),
                    $ready_status['message']
                )
            );
            return;
        }

        $queued_count = 0;
        $skipped_count = 0;
        $error_count = 0;

        foreach ($facturas as $factura) {
            try {
                $referenced_clave = $factura['clave'];

                // Idempotency: skip if NC with code 01 already exists for this factura
                if (self::nota_exists_for_factura($order, $referenced_clave, '01')) {
                    $skipped_count++;
                    continue;
                }

                $result = self::generate_nota($order, [
                    'note_type' => 'nota_credito',
                    'reference_code' => '01', // Anula documento de referencia
                    'reason' => __('Anulación por cancelación de orden', 'fe-woo'),
                    'additional_notes' => sprintf(__('Cancelación automática de orden #%d', 'fe-woo'), $order_id),
                    'referenced_clave' => $referenced_clave,
                    'emisor_id' => $factura['emisor_id'],
                    'use_queue' => true,
                ]);

                if ($result['success']) {
                    $queued_count++;
                } else {
                    $error_count++;
                    self::log(sprintf(
                        'Failed to queue NC for order #%d, factura %s: %s',
                        $order_id,
                        substr($referenced_clave, -8),
                        $result['message']
                    ), 'error');
                }
            } catch (Exception $e) {
                $error_count++;
                self::log(sprintf(
                    'Exception generating NC for order #%d, factura %s: %s',
                    $order_id,
                    isset($referenced_clave) ? substr($referenced_clave, -8) : 'unknown',
                    $e->getMessage()
                ), 'error');
            }
        }

        // Add summary order note
        $parts = [];
        if ($queued_count > 0) {
            $parts[] = sprintf(__('%d NC en cola', 'fe-woo'), $queued_count);
        }
        if ($skipped_count > 0) {
            $parts[] = sprintf(__('%d ya existentes', 'fe-woo'), $skipped_count);
        }
        if ($error_count > 0) {
            $parts[] = sprintf(__('%d con error', 'fe-woo'), $error_count);
        }

        // Only add order note if something new was queued or errored (avoid noise on re-cancel)
        if ($queued_count > 0 || $error_count > 0) {
            $order->add_order_note(
                sprintf(
                    __('Notas de Crédito automáticas por cancelación: %s. Serán procesadas en la próxima ejecución de la cola.', 'fe-woo'),
                    implode(', ', $parts)
                )
            );
        }
    }

    /**
     * Get the sent date for a specific factura clave
     *
     * @param WC_Order $order Order object
     * @param string   $referenced_clave Factura clave
     * @return string Date string
     */
    public static function get_factura_sent_date($order, $referenced_clave) {
        // Check multi-factura data first
        $facturas_generated = $order->get_meta('_fe_woo_facturas_generated');
        if (!empty($facturas_generated) && is_array($facturas_generated)) {
            foreach ($facturas_generated as $factura) {
                if (isset($factura['clave']) && $factura['clave'] === $referenced_clave && !empty($factura['sent_date'])) {
                    return $factura['sent_date'];
                }
            }
        }

        // Fall back to single factura sent date
        $sent_date = $order->get_meta('_fe_woo_factura_sent_date');
        if (!empty($sent_date)) {
            return $sent_date;
        }

        // Last resort: order creation date
        $created = $order->get_date_created();
        return $created ? $created->format('Y-m-d H:i:s') : current_time('mysql');
    }

    /**
     * Log nota manager messages
     *
     * @param string $message Log message
     * @param string $level   Log level (info, error, debug)
     */
    private static function log($message, $level = 'info') {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context = ['source' => 'fe-woo-nota-manager'];

        switch ($level) {
            case 'error':
                $logger->error($message, $context);
                break;
            case 'debug':
                $logger->debug($message, $context);
                break;
            default:
                $logger->info($message, $context);
        }
    }

    /**
     * Send nota de crédito/débito email to customer with document attachments
     *
     * Uses WC_Nota_Email (extends WC_Email) for branded HTML emails
     * with WooCommerce header/footer and order details.
     *
     * @param WC_Order $order Order object
     * @param string   $clave Nota clave
     * @param string   $note_type 'nota_credito' or 'nota_debito'
     */
    private static function send_nota_email($order, $clave, $note_type = 'nota_credito') {
        $note_label = ($note_type === 'nota_credito') ? 'Nota de Crédito' : 'Nota de Débito';
        $order_id = $order->get_id();

        // Get document paths from storage
        $attachments = [];
        $document_paths = FE_Woo_Document_Storage::get_document_paths($order_id, $clave);

        self::log(sprintf('Preparing %s email for order #%d', $note_label, $order_id));

        // Attach PDF file
        if (!empty($document_paths['pdf']) && file_exists($document_paths['pdf'])) {
            $attachments[] = $document_paths['pdf'];
        }

        // Attach the signed nota XML plus the Acuse de Hacienda (AHC).
        if (!empty($document_paths['xml']) && file_exists($document_paths['xml'])) {
            $attachments[] = $document_paths['xml'];
        }
        if (!empty($document_paths['acuse_xml']) && file_exists($document_paths['acuse_xml'])) {
            $attachments[] = $document_paths['acuse_xml'];
        }

        // Skip if no attachments found
        if (empty($attachments)) {
            self::log(sprintf('No attachments found for %s email, order #%d — skipping', $note_label, $order_id), 'warning');
            return;
        }

        // Send via WC_Nota_Email (branded HTML template)
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (!isset($emails['WC_Nota_Email'])) {
            self::log(sprintf('WC_Nota_Email class not found — cannot send %s email for order #%d', $note_label, $order_id), 'error');
            return;
        }

        // Guard: ensure there is a recipient before sending
        // Uses same logic as WC_Nota_Email::get_nota_recipient() — fiscal email takes priority
        $recipient = $order->get_meta('_fe_woo_invoice_email') ?: $order->get_billing_email();
        if (empty($recipient)) {
            self::log(sprintf('No recipient email found for order #%d — skipping %s email', $order_id, $note_label), 'error');
            return;
        }

        $emails['WC_Nota_Email']->trigger($order_id, $order, $clave, $note_type, $attachments);
        self::log(sprintf('%s email triggered for %s on order #%d with %d attachments (delivery depends on mail server)', $note_label, $recipient, $order_id, count($attachments)));
        $order->add_order_note(
            sprintf(
                /* translators: %1$s: note label, %2$s: recipient email, %3$d: attachment count */
                __('Email de %1$s programado para %2$s con %3$d adjuntos', 'fe-woo'),
                $note_label,
                $recipient,
                count($attachments)
            )
        );
    }
}
