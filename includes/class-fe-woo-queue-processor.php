<?php
/**
 * FE WooCommerce Queue Processor
 *
 * Processes the factura queue via cron job
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Queue_Processor Class
 *
 * Handles cron-based processing of factura queue
 */
class FE_Woo_Queue_Processor {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'fe_woo_process_queue';

    /**
     * Cron hook para el health check (T-4 v1.19.0): detectar y resetear items
     * varados en "processing" + notificar al admin si hay items failed-permanentes.
     */
    const HEALTH_CHECK_HOOK = 'fe_woo_queue_health_check';

    /**
     * Hook used to poll Hacienda for the signed MensajeHacienda XML when the
     * initial POST /recepcion response didn't include `respuesta-xml`. One
     * single-shot event is scheduled per (order_id, clave) on the queue path;
     * handler reschedules itself with backoff until it gets the XML or gives up.
     */
    const POLL_ACUSE_HOOK = 'fe_woo_poll_acuse_xml';

    const POLL_MAX_ATTEMPTS = 8;

    /**
     * Hook para envío async de email multi-factura.
     *
     * Antes (≤ v1.13.0): process_multi_factura llamaba send_multi_factura_email
     * inline al final del procesamiento. Cada email tarda 2-4 min por SMTP, y
     * para 2 emisores el tick gastaba 4-8 min bloqueando el resto del queue.
     * Ahora process_multi_factura agenda este hook a +5s; el tick termina
     * rápido y los emails se envían en su propio cron event.
     */
    const MULTI_FACTURA_EMAIL_HOOK = 'fe_woo_send_multi_factura_email';

    /**
     * Initialize the processor
     */
    public static function init() {
        // Schedule cron if not already scheduled (hourly)
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }

        // Health check cron — corre cada hora con offset de 30min relativo al
        // process_queue para no pisar la misma ventana de ejecución.
        if (!wp_next_scheduled(self::HEALTH_CHECK_HOOK)) {
            wp_schedule_event(time() + 1800, 'hourly', self::HEALTH_CHECK_HOOK);
        }

        // Hook into cron
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
        add_action(self::HEALTH_CHECK_HOOK, [FE_Woo_Queue::class, 'health_check']);

        // Single-event polling for the signed MensajeHacienda acuse XML.
        add_action(self::POLL_ACUSE_HOOK, [__CLASS__, 'poll_acuse_xml'], 10, 3);

        // Async sender para emails multi-factura (v1.14.0 — fix B-5).
        add_action(self::MULTI_FACTURA_EMAIL_HOOK, [__CLASS__, 'send_multi_factura_email_async'], 10, 1);
    }

    /**
     * Decode Hacienda's `respuesta-xml` (base64) from an API response envelope
     * and persist it as AHC-{clave}.xml. Returns true when the XML was written.
     *
     * The response shape this understands is the one returned by
     * FE_Woo_API_Client::send_invoice / query_invoice_status — a wrapper with
     * `data` holding Hacienda's raw JSON. We also accept the raw JSON directly
     * (used by the backfill CLI).
     *
     * @param int    $order_id
     * @param string $clave
     * @param array  $response API response (either wrapped or raw Hacienda payload)
     * @return bool True if acuse XML was extracted and saved.
     */
    public static function save_acuse_xml_from_response($order_id, $clave, $response) {
        if (!is_array($response)) {
            return false;
        }

        // Unwrap: responses from our client put Hacienda's body under `data`.
        $payload = isset($response['data']) && is_array($response['data']) ? $response['data'] : $response;

        // Hacienda's field is `respuesta-xml`. Some internal paths may use the
        // PHP-safe alias `respuesta_xml`; accept both so the backfill helper
        // doesn't need to rename keys.
        $b64 = null;
        if (!empty($payload['respuesta-xml'])) {
            $b64 = $payload['respuesta-xml'];
        } elseif (!empty($payload['respuesta_xml'])) {
            $b64 = $payload['respuesta_xml'];
        }

        if (empty($b64) || !is_string($b64)) {
            return false;
        }

        $xml = base64_decode($b64, true);
        if ($xml === false || strpos($xml, '<MensajeHacienda') === false) {
            // Treat as absent; don't persist garbage. Hacienda is expected to
            // send a well-formed MensajeHacienda once the doc reaches a final
            // state — anything else is likely a partial/error payload.
            return false;
        }

        $result = FE_Woo_Document_Storage::save_acuse_xml($order_id, $xml, $clave);
        if (!$result['success']) {
            self::log(sprintf('Failed to save acuse XML for order #%d: %s', $order_id, $result['error']), 'error');
            return false;
        }

        // Also pull `ind-estado` off the same payload so the order UI reflects
        // the final Hacienda state (aceptado/rechazado) instead of staying
        // frozen at "procesando" from the initial POST response.
        $ind_estado = null;
        if (!empty($payload['ind-estado'])) {
            $ind_estado = strtolower((string) $payload['ind-estado']);
        }

        // Extract the human-readable detail from the MensajeHacienda XML so
        // we can surface it in the order admin (Hacienda's rejection reasons
        // live in DetalleMensaje, not the outer JSON).
        $hacienda_detail = self::extract_acuse_detail($xml);

        $order = wc_get_order($order_id);
        $previous_status = $order ? (string) $order->get_meta('_fe_woo_hacienda_status') : '';

        // Decide the canonical status. The signed MensajeHacienda
        // (EstadoMensaje inside the AHC XML) is Hacienda's authoritative
        // verdict — the JSON wrapper's `ind-estado` can lag behind on
        // the first call after processing completes. Prefer EstadoMensaje
        // when it resolves to a terminal state; fall back to ind-estado
        // otherwise.
        $effective_status = null;
        $xml_estado = !empty($hacienda_detail['estado_mensaje']) ? strtolower($hacienda_detail['estado_mensaje']) : '';
        if (in_array($xml_estado, ['aceptado', 'rechazado'], true)) {
            $effective_status = $xml_estado;
        } elseif ($ind_estado && in_array($ind_estado, ['aceptado', 'rechazado', 'procesando', 'recibido'], true)) {
            $effective_status = $ind_estado;
        } elseif (in_array($xml_estado, ['procesando', 'recibido'], true)) {
            $effective_status = $xml_estado;
        }

        if ($order) {
            $order->update_meta_data('_fe_woo_acuse_xml_file_path', $result['file_path']);
            if ($effective_status) {
                $order->update_meta_data('_fe_woo_hacienda_status', $effective_status);
                $order->update_meta_data('_fe_woo_last_status_check', current_time('mysql'));
            }
            if (!empty($hacienda_detail['detalle'])) {
                $order->update_meta_data('_fe_woo_hacienda_detalle', $hacienda_detail['detalle']);
            }
            if (!empty($hacienda_detail['estado_mensaje'])) {
                $order->update_meta_data('_fe_woo_hacienda_estado_mensaje', $hacienda_detail['estado_mensaje']);
            }
            $order->save();

            // Document label for notes/email (factura/tiquete/nota).
            $doc_type  = $order->get_meta('_fe_woo_document_type') ?: 'tiquete';
            $doc_label = ($doc_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';

            // Notas y email se disparan exactamente una vez por transición
            // a estado terminal. El gate previous_status !== effective_status
            // previene duplicados cuando el cron + el polling JS + el botón
            // de re-consulta apuntan al mismo veredicto.
            // Reflejar el estado terminal en el emission log para que
            // find-orphans pueda distinguir aceptados, rechazados y los que
            // quedaron en procesando.
            if ($effective_status && class_exists('FE_Woo_Emission_Log')) {
                FE_Woo_Emission_Log::update_status($clave, $effective_status);
            }

            if ($effective_status === 'aceptado' && $previous_status !== 'aceptado') {
                $order->add_order_note(sprintf(
                    /* translators: 1: tipo de documento (Factura/Tiquete/Nota), 2: clave numérica de Hacienda */
                    __('%1$s aceptada por Hacienda. Clave: %2$s', 'fe-woo'),
                    $doc_label,
                    $clave
                ));
                try {
                    self::send_factura_email($order, $clave, $doc_type);
                } catch (Exception $e) {
                    self::log(sprintf('Email send failed for order #%d: %s', $order_id, $e->getMessage()), 'error');
                }
            } elseif ($effective_status === 'rechazado' && $previous_status !== 'rechazado') {
                $detalle_corto = !empty($hacienda_detail['detalle'])
                    ? mb_substr((string) $hacienda_detail['detalle'], 0, 500)
                    : '';
                $order->add_order_note(sprintf(
                    /* translators: 1: tipo de documento, 2: clave, 3: motivo de rechazo (truncado) */
                    __('%1$s rechazada por Hacienda. Clave: %2$s. Motivo: %3$s', 'fe-woo'),
                    $doc_label,
                    $clave,
                    $detalle_corto ?: __('(sin detalle)', 'fe-woo')
                ));
            }
        }

        self::log(sprintf('Acuse XML saved for order #%d at %s (estado=%s)', $order_id, $result['file_path'], $effective_status ?: 'n/a'));
        return true;
    }

    /**
     * Pull EstadoMensaje + DetalleMensaje out of a signed MensajeHacienda
     * XML string. Returns empty strings if parsing fails — callers should
     * treat missing fields as "unknown" rather than letting the whole
     * acuse pipeline fail over a parse error.
     *
     * @param string $xml Raw MensajeHacienda XML (base64-decoded).
     * @return array{detalle:string,estado_mensaje:string}
     */
    private static function extract_acuse_detail($xml) {
        $out = ['detalle' => '', 'estado_mensaje' => ''];
        $doc = new DOMDocument();
        // Squelch warnings — we'd rather fall back silently than surface
        // a libxml notice in the queue logs.
        if (!@$doc->loadXML($xml, LIBXML_NONET)) {
            return $out;
        }
        $xp = new DOMXPath($doc);
        $xp->registerNamespace('mh', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeHacienda');
        $estado = $xp->query('//mh:EstadoMensaje')->item(0);
        $detalle = $xp->query('//mh:DetalleMensaje')->item(0);
        if ($estado)  { $out['estado_mensaje'] = trim($estado->textContent); }
        if ($detalle) { $out['detalle']        = trim($detalle->textContent); }
        return $out;
    }

    /**
     * Query Hacienda for the current ind-estado and update the order meta.
     * Used when the AHC- XML is already on disk but the order status on our
     * side never got refreshed from "procesando". Also re-populates the
     * EstadoMensaje/DetalleMensaje meta from the on-disk AHC when the
     * consulta endpoint only returns ind-estado without `respuesta-xml`.
     */
    private static function refresh_hacienda_status_from_api($order, $clave) {
        $dirty = false;

        // First: always refresh the EstadoMensaje/DetalleMensaje meta from
        // the on-disk AHC if we have it. The MensajeHacienda XML is the
        // authoritative source for the rejection reason, and it doesn't
        // need a network call. (Hacienda's consulta endpoint also returns
        // the XML, but only on the first response after processing
        // completes — subsequent calls often omit `respuesta-xml`.)
        $ahc_path = FE_Woo_Document_Storage::get_acuse_xml_path($order->get_id(), $clave);
        if ($ahc_path && file_exists($ahc_path)) {
            $detail = self::extract_acuse_detail(file_get_contents($ahc_path));
            if (!empty($detail['detalle'])) {
                $order->update_meta_data('_fe_woo_hacienda_detalle', $detail['detalle']);
                $dirty = true;
            }
            if (!empty($detail['estado_mensaje'])) {
                $order->update_meta_data('_fe_woo_hacienda_estado_mensaje', $detail['estado_mensaje']);
                // Also map EstadoMensaje → ind-estado if the order's local
                // status is still stuck on something pre-final. Mapping is
                // straightforward: "Aceptado"/"Rechazado"/"Procesando".
                $mapped = strtolower($detail['estado_mensaje']);
                if (in_array($mapped, ['aceptado', 'rechazado', 'procesando', 'recibido'], true)) {
                    $current = strtolower((string) $order->get_meta('_fe_woo_hacienda_status'));
                    if ($current !== $mapped) {
                        $order->update_meta_data('_fe_woo_hacienda_status', $mapped);
                    }
                }
                $dirty = true;
            }
        }

        // Second: hit the consulta endpoint for the latest ind-estado. If
        // the token is stale or the call fails we still have whatever we
        // pulled from the on-disk AHC above.
        try {
            $client = new FE_Woo_API_Client();
            $result = $client->query_invoice_status($clave);
            $payload = isset($result['data']) && is_array($result['data']) ? $result['data'] : null;
            if ($payload && !empty($payload['ind-estado'])) {
                $estado = strtolower((string) $payload['ind-estado']);
                if (in_array($estado, ['aceptado', 'rechazado', 'procesando', 'recibido'], true)) {
                    $order->update_meta_data('_fe_woo_hacienda_status', $estado);
                    $order->update_meta_data('_fe_woo_last_status_check', current_time('mysql'));
                    $dirty = true;
                }
            }
        } catch (Exception $e) {
            // Ignore — the on-disk AHC meta still got written above.
        }

        if ($dirty) {
            $order->save();
        }
    }

    /**
     * Poll Hacienda's consulta endpoint for the signed MensajeHacienda XML.
     * Scheduled as a single event; reschedules itself with exponential backoff
     * (1m, 2m, 5m, 10m, 20m, 40m, 1h, 2h) up to POLL_MAX_ATTEMPTS, or gives up
     * silently if the consulta endpoint rejects auth (403) since that indicates
     * a configuration gap, not a transient failure.
     *
     * @param int    $order_id
     * @param string $clave
     * @param int    $attempt 1-based
     */
    /**
     * @deprecated 1.10.0 Bloqueaba la request HTTP del admin hasta 96s
     * (sleep [1,2,3] + 3 timeouts de 30s) y causaba 504 en Pantheon
     * (límite 60s). El polling del acuse se delegó al frontend (browser
     * hace polling vía recheck_status) y al cron `poll_acuse_xml` como
     * red de seguridad. La función se conserva por compatibilidad con
     * extensiones que pudieran llamarla, pero el flujo normal ya no la
     * invoca. Para correr una consulta única y rápida, usar
     * `query_invoice_status` directamente desde el cliente API.
     */
    public static function poll_acuse_xml_inline($order_id, $clave) {
        // Max 3 attempts so the inline wait stays bounded and the UI
        // request doesn't drag on. Total worst case = 1 + 2 + 3 = 6 s,
        // which fits comfortably under the AJAX 120s budget and avoids
        // any risk of runaway loops. Slower responses are picked up by
        // the scheduled backoff poll below.
        $delays = [1, 2, 3];

        foreach ($delays as $i => $seconds) {
            sleep($seconds);

            try {
                $client = new FE_Woo_API_Client();
                $result = $client->query_invoice_status($clave);
            } catch (Exception $e) {
                continue;
            }

            $payload = isset($result['data']) && is_array($result['data']) ? $result['data'] : null;
            $estado = $payload && !empty($payload['ind-estado']) ? strtolower((string) $payload['ind-estado']) : '';

            // Persist whatever came back (ind-estado + possibly respuesta-xml).
            // save_acuse_xml_from_response handles the AHC + meta writes.
            self::save_acuse_xml_from_response($order_id, $clave, $result);

            // If we have the signed XML OR Hacienda reached a terminal state,
            // we're done polling inline — the admin page can render the final
            // state immediately.
            if (FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave)
                || in_array($estado, ['aceptado', 'rechazado'], true)) {
                return;
            }
        }

        // Still not final after ~12 s — hand off to the cron-backed backoff
        // poll so the UI catches up whenever Hacienda finishes processing.
        wp_schedule_single_event(time() + 60, self::POLL_ACUSE_HOOK, [$order_id, $clave, 1]);
    }

    public static function poll_acuse_xml($order_id, $clave, $attempt = 1) {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // If the XML is already on disk we can skip the GET, but we still
        // need to make sure the order's Hacienda status reflects the final
        // state and the EstadoMensaje/DetalleMensaje meta fields are
        // populated. The refresh call handles both.
        if (FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave)) {
            $current = strtolower((string) $order->get_meta('_fe_woo_hacienda_status'));
            $has_detail = $order->get_meta('_fe_woo_hacienda_detalle') !== '';
            if (!$has_detail || in_array($current, ['', 'procesando', 'recibido'], true)) {
                self::refresh_hacienda_status_from_api($order, $clave);
            }
            return;
        }

        try {
            $client = new FE_Woo_API_Client();
            $result = $client->query_invoice_status($clave);
        } catch (Exception $e) {
            self::log(sprintf('poll_acuse_xml: query failed for order #%d: %s', $order_id, $e->getMessage()), 'error');
            $result = ['success' => false];
        }

        // Intentar guardar el AHC primero. Si respuesta-xml viene en la
        // misma respuesta, esto escribe el archivo, resuelve el status
        // terminal y agrega la nota terminal — todo con previous_status
        // correcto leído desde DB pre-actualización. Si lo hacemos al
        // revés (intermediate update primero), save_acuse_xml_from_response
        // ve previous_status='aceptado' y silencia la nota + el email.
        $saved = self::save_acuse_xml_from_response($order_id, $clave, $result);
        if ($saved) {
            return;
        }

        // AHC todavía no disponible. Surface el ind-estado intermedio
        // ("recibido"/"procesando") que Hacienda haya dado para que el
        // panel admin no se vea estancado.
        $payload = isset($result['data']) && is_array($result['data']) ? $result['data'] : null;
        if ($payload && !empty($payload['ind-estado'])) {
            $estado = strtolower((string) $payload['ind-estado']);
            if (in_array($estado, ['aceptado', 'rechazado', 'procesando', 'recibido'], true)) {
                $order->update_meta_data('_fe_woo_hacienda_status', $estado);
                $order->update_meta_data('_fe_woo_last_status_check', current_time('mysql'));
                $order->save();
            }
        }

        if ($attempt >= self::POLL_MAX_ATTEMPTS) {
            self::log(sprintf('poll_acuse_xml: giving up on order #%d after %d attempts', $order_id, $attempt));
            return;
        }

        $backoff = [60, 120, 300, 600, 1200, 2400, 3600, 7200];
        $delay = $backoff[$attempt - 1] ?? 3600;
        wp_schedule_single_event(time() + $delay, self::POLL_ACUSE_HOOK, [$order_id, $clave, $attempt + 1]);
    }

    /**
     * Process the queue (called by cron).
     *
     * Thin wrapper around `process_items()`. The cron path acquires the
     * global queue-processing transient; manual callers (e.g. a per-event
     * AJAX batch in the parent theme) should call `process_items()` directly
     * with `acquire_global_lock = false` and manage their own narrower mutex.
     */
    public static function process_queue() {
        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            self::log('Queue processing paused by configuration', 'debug');
            return;
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            self::log('Queue processing skipped: ' . $ready_status['message'], 'error');
            return;
        }

        // Acquire the global queue-processing transient BEFORE we read state
        // (stale recover + pending SELECT). If we deferred this to inside
        // process_items(), two cron ticks could overlap in the window between
        // get_pending_items() and the transient set, both believing they hold
        // the lock and re-processing the same rows.
        if (get_transient('fe_woo_queue_processing')) {
            self::log('process_queue: global lock held, skipping cron tick', 'debug');
            return;
        }
        set_transient('fe_woo_queue_processing', true, 300);

        try {
            // Recover items varados en `processing` de cron ticks anteriores
            // que fueron interrumpidos (timeout, redeploy, fatal). Sin esto
            // el item queda bloqueado indefinidamente — el transient lock
            // expira a 5 min pero el row no se libera solo.
            $recovered = FE_Woo_Queue::recover_stale_processing_items(10);
            if ($recovered > 0) {
                self::log(sprintf('Recovered %d stale processing items to retry', $recovered));
            }

            $items = FE_Woo_Queue::get_pending_items(10);

            // Lock already held — tell process_items() not to re-acquire.
            self::process_items($items, [
                'acquire_global_lock' => false,
                'source'              => 'cron',
            ]);
        } finally {
            delete_transient('fe_woo_queue_processing');
        }
    }

    /**
     * Process an explicit list of queue items.
     *
     * Public entry point used by both the cron (via `process_queue()`) and
     * manual batch callers (e.g. theme AJAX batches). Each item still goes
     * through the per-order `FE_Woo_Order_Lock` inside `process_item()`, so
     * concurrent callers are safe from double-processing the same order.
     *
     * @security Callers are responsible for capability/nonce checks before
     *           invoking this method. It performs no auth of its own and
     *           assumes `$items` originated from a trusted queue query
     *           (e.g. `FE_Woo_Queue::get_pending_items*`). Do NOT expose this
     *           directly to an AJAX/REST endpoint without an explicit
     *           `current_user_can()` + `check_admin_referer()` gate in the
     *           handler.
     *
     * @param array $items Queue rows (from get_pending_items*).
     * @param array $opts  Optional:
     *                     - 'acquire_global_lock' (bool, default true):
     *                       whether to take the global `fe_woo_queue_processing`
     *                       transient. The cron sets true; manual batches
     *                       deliberately set false so they can run alongside
     *                       the cron.
     *                     - 'source' (string, default 'manual'):
     *                       free-form label used in log lines for traceability.
     * @return array {
     *     @type int      $attempted Items inspected.
     *     @type int      $processed Items that completed successfully.
     *     @type int      $failed    Items where process_item threw.
     *     @type int      $skipped   Items skipped (lock contention or short-circuit).
     *     @type string[] $errors    Up to 10 truncated error messages.
     * }
     */
    public static function process_items(array $items, array $opts = []) {
        $opts = array_merge([
            'acquire_global_lock' => true,
            'source'              => 'manual',
        ], $opts);

        $stats = [
            'attempted' => 0,
            'processed' => 0,
            'failed'    => 0,
            'skipped'   => 0,
            'errors'    => [],
        ];

        if (empty($items)) {
            return $stats;
        }

        if ($opts['acquire_global_lock']) {
            if (get_transient('fe_woo_queue_processing')) {
                // Cron already running. Caller decides what to do with this.
                $stats['skipped'] = count($items);
                $stats['errors'][] = 'global_lock_held';
                return $stats;
            }
            set_transient('fe_woo_queue_processing', true, 300);
        }

        try {
            // Expose the current source label to FE_Woo_Factura_Generator so
            // its `fe_woo_emission_datetime` filter sees `$ctx['source']`.
            // Inside the try so the finally clears the static even if the
            // string cast or set_emission_source itself throws — leaking the
            // override would change FechaEmision semantics for later
            // emissions in the same PHP request.
            if (class_exists('FE_Woo_Factura_Generator')) {
                FE_Woo_Factura_Generator::set_emission_source((string) $opts['source']);
            }

            foreach ($items as $item) {
                $stats['attempted']++;

                try {
                    $outcome = self::process_item($item);
                } catch (Exception $e) {
                    // process_item handles its own catch+mark_failed; this
                    // outer catch is defense-in-depth for unexpected throws.
                    $outcome = 'failed';
                    if (count($stats['errors']) < 10) {
                        $stats['errors'][] = sprintf('queue#%d: %s', $item->id, substr($e->getMessage(), 0, 200));
                    }
                }

                switch ($outcome) {
                    case 'processed':
                        $stats['processed']++;
                        break;
                    case 'failed':
                        $stats['failed']++;
                        break;
                    case 'skipped_locked':
                    default:
                        $stats['skipped']++;
                        break;
                }
            }
        } finally {
            if (class_exists('FE_Woo_Factura_Generator')) {
                FE_Woo_Factura_Generator::set_emission_source(null);
            }
            if ($opts['acquire_global_lock']) {
                delete_transient('fe_woo_queue_processing');
            }
        }

        self::log(sprintf(
            'process_items(source=%s): attempted=%d processed=%d failed=%d skipped=%d',
            $opts['source'],
            $stats['attempted'],
            $stats['processed'],
            $stats['failed'],
            $stats['skipped']
        ));

        return $stats;
    }

    /**
     * Process a single queue item.
     *
     * @param object $item Queue item.
     * @return string Outcome: 'processed' | 'failed' | 'skipped_locked'.
     */
    private static function process_item($item) {
        // Per-order lock: serializa el cron worker contra mutaciones manuales
        // (Reintentar / Ejecutar / reexecute_invoice), que ya adquieren el
        // mismo FE_Woo_Order_Lock. Sin esto, cron y manual pueden correr
        // concurrentes sobre la misma orden y consumir 2 consecutivos.
        //
        // Si el lock está tomado: NO marcamos processing ni consumimos
        // intento. El item permanece pending y el próximo tick lo intentará
        // cuando el holder libere. TTL alto (300s) cubre Hacienda timeout +
        // sign + retries dentro del worker.
        $lock_acquired = false;
        if (class_exists('FE_Woo_Order_Lock')) {
            if (!FE_Woo_Order_Lock::acquire($item->order_id, 'cron_queue', 300)) {
                $existing = FE_Woo_Order_Lock::inspect($item->order_id);
                self::log(sprintf(
                    'process_item: skipping queue item #%d for order #%d — lock held by %s (%ds remaining)',
                    $item->id,
                    $item->order_id,
                    isset($existing['operation']) ? $existing['operation'] : 'unknown',
                    isset($existing['remaining']) ? $existing['remaining'] : 0
                ));
                return 'skipped_locked';
            }
            $lock_acquired = true;
        }

        // Mark as processing
        FE_Woo_Queue::mark_processing($item->id);

        // Log start
        self::log(sprintf('Processing queue item #%d for order #%d', $item->id, $item->order_id));

        $outcome = 'processed';

        try {
            // Get order
            $order = wc_get_order($item->order_id);

            if (!$order) {
                throw new Exception('Order not found');
            }

            // Get document type from queue item
            $document_type = isset($item->document_type) ? $item->document_type : 'tiquete';

            // Route to appropriate processor based on document type
            if (in_array($document_type, ['nota_credito', 'nota_debito'], true)) {
                // Process credit/debit note via Nota Manager
                self::process_nota_item($order, $item, $document_type);
            } else {
                // Check if this order requires multi-factura processing
                $multi_factura_result = FE_Woo_Multi_Factura_Generator::generate_facturas_for_order($order);

                if (isset($multi_factura_result['error'])) {
                    throw new Exception($multi_factura_result['error']);
                }

                // Check if multiple facturas are needed
                if ($multi_factura_result['multiple']) {
                    // Process multiple facturas
                    self::process_multi_factura($order, $item, $document_type, $multi_factura_result);
                } else {
                    // Single factura processing (original logic)
                    self::process_single_factura($order, $item, $document_type, $multi_factura_result);
                }
            }

        } catch (Exception $e) {
            $outcome = 'failed';

            // Mark as failed
            $error_message = $e->getMessage();
            FE_Woo_Queue::mark_failed($item->id, $error_message, true);

            // Log error
            $doc_type = isset($document_type) ? $document_type : 'tiquete';
            self::log(sprintf('Failed to process order #%d (%s): %s', $item->order_id, $doc_type, $error_message), 'error');

            // Add order note
            if (isset($order) && $order) {
                $doc_label_map = [
                    'factura' => 'Factura Electrónica',
                    'tiquete' => 'Tiquete Electrónico',
                    'nota_credito' => 'Nota de Crédito',
                    'nota_debito' => 'Nota de Débito',
                ];
                $doc_label = isset($doc_label_map[$doc_type]) ? $doc_label_map[$doc_type] : 'Documento';
                $order->add_order_note(
                    sprintf(
                        __('Error al generar %s: %s', 'fe-woo'),
                        $doc_label,
                        $error_message
                    )
                );
            }
        } finally {
            if ($lock_acquired && class_exists('FE_Woo_Order_Lock')) {
                FE_Woo_Order_Lock::release($item->order_id);
            }
        }

        return $outcome;
    }

    /**
     * Process a nota (credit/debit note) queue item
     *
     * Delegates to FE_Woo_Nota_Manager::process_nota() which handles
     * XML generation, Hacienda submission, and metadata storage.
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type 'nota_credito' or 'nota_debito'
     */
    private static function process_nota_item($order, $item, $document_type) {
        // Parse nota data from queue item
        $nota_data = json_decode($item->factura_data, true);
        if (empty($nota_data)) {
            throw new Exception('Invalid nota data in queue item');
        }

        $note_type = isset($nota_data['note_type']) ? $nota_data['note_type'] : $document_type;
        $emisor_id = isset($nota_data['emisor_id']) ? (int) $nota_data['emisor_id'] : (isset($item->emisor_id) ? (int) $item->emisor_id : 0);
        $referenced_clave = isset($nota_data['referenced_clave']) ? $nota_data['referenced_clave'] : '';
        $reference_code = isset($nota_data['reference_code']) ? $nota_data['reference_code'] : '';
        $reason = isset($nota_data['reason']) ? $nota_data['reason'] : '';
        $additional_notes = isset($nota_data['additional_notes']) ? $nota_data['additional_notes'] : '';

        // Resolve emisor
        $emisor = null;
        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        }
        if (!$emisor) {
            $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
        }
        if (!$emisor) {
            throw new Exception(__('No hay emisor configurado para procesar esta nota.', 'fe-woo'));
        }

        if (empty($emisor->api_username) || empty($emisor->api_password)) {
            throw new Exception(sprintf(
                __('El emisor "%s" no tiene credenciales de API configuradas.', 'fe-woo'),
                $emisor->nombre_legal
            ));
        }

        // Build reference data
        $reference_data = isset($nota_data['reference_data']) ? $nota_data['reference_data'] : [
            'referenced_clave' => $referenced_clave,
            'referenced_date' => FE_Woo_Nota_Manager::get_factura_sent_date($order, $referenced_clave),
            'referenced_type' => ($order->get_meta('_fe_woo_document_type') === 'factura') ? '01' : '04',
            'reference_code' => $reference_code,
            'reference_reason' => $reason,
        ];

        self::log(sprintf('Processing nota queue item #%d for order #%d - Type: %s, Emisor: %s, Ref: %s',
            $item->id,
            $order->get_id(),
            $note_type,
            $emisor->nombre_legal,
            substr($referenced_clave, -8)
        ));

        // Delegate to Nota Manager (use_queue=false since we ARE the queue processor)
        $result = FE_Woo_Nota_Manager::process_nota($order, [
            'note_type' => $note_type,
            'reference_data' => $reference_data,
            'emisor_id' => (int) $emisor->id,
            'emisor' => $emisor,
            'reason' => $reason,
            'additional_notes' => $additional_notes,
            'reference_code' => $reference_code,
            'referenced_clave' => $referenced_clave,
        ]);

        if ($result['success']) {
            FE_Woo_Queue::mark_completed($item->id, $result['clave'], '', ['nota' => true]);
            self::log(sprintf('Nota processed successfully for order #%d. Clave: %s', $order->get_id(), $result['clave']));
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Process single factura (original logic)
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     */
    private static function process_single_factura($order, $item, $document_type, $multi_factura_result) {
        // Get the first (and only) factura data
        $factura_data = $multi_factura_result['facturas'][0];
        $emisor_id = $factura_data['emisor_id'];
        $line_items = $factura_data['items'];

        try {
            // Get the emisor object for this factura
            $emisor = null;
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
            }
            if (!$emisor) {
                $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
            }
            if (!$emisor) {
                throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
            }

            // Validate emisor has required credentials before proceeding
            if (empty($emisor->api_username) || empty($emisor->api_password)) {
                throw new Exception(sprintf(
                    __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                    $emisor->nombre_legal
                ));
            }

            // Validate exoneración if applicable (only for factura and only for parent/default emisor)
            if ($document_type === 'factura' && !empty($emisor->is_parent) && class_exists('FE_Woo_Exoneracion') && $order->get_meta('_fe_woo_has_exoneracion') === 'yes') {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($item->order_id);
                if (!$validation['valid']) {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_REJECTED_VALIDATION);
                    $order->save();
                    throw new Exception('Exoneración validation failed: ' . implode(', ', $validation['errors']));
                } else {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_VALID);
                    $order->save();
                }
            }

            // Pre-flight: validar coherencia código-tarifa-monto antes de
            // consumir un consecutivo del contador. Hacienda guarda los
            // consecutivos rechazados igual que los aceptados, así que cada
            // intento fallido nos cuesta uno y deja huella permanente en su
            // sistema. Detectar los errores de configuración acá evita eso.
            $emisor_data_for_validation = FE_Woo_Factura_Generator::prepare_emisor_data($emisor);
            $apply_exon_pre = !empty($emisor->is_parent);
            $preflight = FE_Woo_Factura_Generator::validate_invoice_data($order, $document_type, $emisor_data_for_validation, $line_items, $apply_exon_pre);
            if (!$preflight['valid']) {
                throw new Exception(
                    __('Pre-flight: el documento sería rechazado por Hacienda. Corrige antes de reintentar:', 'fe-woo')
                    . "\n• " . implode("\n• ", $preflight['errors'])
                );
            }

            // === Recuperación de clave pending de un intento previo ===
            //
            // Si la orden tiene `_fe_woo_factura_clave_pending`, significa que un
            // intento anterior consumió consecutivo y generó la clave, pero falló
            // antes de confirmar (POST/red/server crash). Antes de regenerar
            // (= quemar otro consecutivo), preguntamos a Hacienda si recibió la
            // clave previa:
            //   - Sí (cualquier estado): no re-POST, procesamos veredicto.
            //   - 404: clave nunca llegó → safe re-POST con misma clave.
            //   - Error/timeout: throw para retry siguiente tick (clave preservada).
            $skip_post = false;
            $clave = null;
            $xml = null;
            $response = null;
            $pending_clave = (string) $order->get_meta('_fe_woo_factura_clave_pending');

            if ($pending_clave !== '') {
                self::log(sprintf(
                    'process_single_factura: recovering pending clave %s for order #%d',
                    $pending_clave,
                    $order->get_id()
                ));

                $api_client_check = new FE_Woo_API_Client();
                // v1.29.4: el emisor ya está resuelto en este scope (línea ~679).
                // Usar la variante explícita evita un re-lookup en el resolver
                // y deja claro qué credenciales se usan para verificar la clave.
                $check = $api_client_check->query_invoice_status_with_emisor($pending_clave, $emisor);

                if (!empty($check['success'])) {
                    self::log(sprintf(
                        'process_single_factura: Hacienda has clave %s — recovering without resend',
                        $pending_clave
                    ));
                    $clave = $pending_clave;
                    $response = [
                        'success' => true,
                        'data' => isset($check['data']) ? $check['data'] : [],
                    ];
                    $skip_post = true;
                } elseif (!empty($check['not_found'])) {
                    self::log(sprintf(
                        'process_single_factura: Hacienda 404 for clave %s — rebuilding XML and re-posting',
                        $pending_clave
                    ));
                    $rebuild = FE_Woo_Factura_Generator::rebuild_xml_for_clave(
                        $order,
                        $document_type,
                        $emisor_id,
                        $line_items,
                        $pending_clave,
                        false
                    );
                    if (empty($rebuild['success'])) {
                        throw new Exception('Failed to rebuild XML for pending clave: ' . (isset($rebuild['error']) ? $rebuild['error'] : 'unknown'));
                    }
                    $clave = $pending_clave;
                    $xml = $rebuild['xml'];
                    // skip_post permanece false → continúa al sign + POST.
                } else {
                    throw new Exception(sprintf(
                        'No se pudo verificar el estado de la clave pending %s en Hacienda: %s',
                        $pending_clave,
                        isset($check['message']) ? $check['message'] : 'unknown'
                    ));
                }
            }

            // === Flujo normal: generar nueva clave ===
            if ($clave === null) {
                // Generate factura or tiquete XML with specific emisor and line items
                $include_shipping = !empty($factura_data['include_shipping']);
                $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items, $include_shipping);

                if (!$result['success']) {
                    // If skipped because all items are non-taxable, mark as completed and return
                    if (!empty($result['skipped'])) {
                        FE_Woo_Queue::mark_completed($item->id, '', '', ['skipped' => true]);
                        $order->add_order_note(__('No se generó documento electrónico: todos los productos tienen estado de impuesto "ninguno".', 'fe-woo'));
                        self::log(sprintf('Order #%d skipped - all items have tax_status none', $item->order_id));
                        return;
                    }
                    throw new Exception($result['error']);
                }

                $clave = $result['clave'];
                $xml = $result['xml'];

                // Persistir pending ANTES de sign+POST. Si algo falla entre acá
                // y el promote-a-confirmado, el siguiente tick recuperará desde
                // este meta vía la rama de "Recuperación" arriba.
                $order->update_meta_data('_fe_woo_factura_clave_pending', $clave);
                $order->save();
            }

            // === Sign + POST (skip si recovery vía Hacienda exitoso) ===
            if (!$skip_post) {
                $xml = self::sign_and_validate($xml, $emisor, $order->get_id(), $clave);

                // Send to Hacienda using emisor's specific credentials
                $api_client = new FE_Woo_API_Client();
                $response = $api_client->send_invoice_with_emisor($xml, $emisor);

                if (!$response['success']) {
                    // Include detailed error for connection/authentication failures
                    $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                    if (isset($response['error_detail'])) {
                        $error_message .= ' - ' . $response['error_detail'];
                    }
                    throw new Exception($error_message);
                }
            }

            // Do NOT mark as completed yet. The queue item stays in
            // "processing" until we know Hacienda's final verdict — only
            // aceptado is considered success. Rejected / procesando /
            // recibido all get their own handling further down.

            // Save documents to filesystem (skip si recovery vía Hacienda no
            // re-firmó: el XML del intento previo ya está en disco con su path).
            if ($xml !== null) {
                $xml_result = FE_Woo_Document_Storage::save_xml($item->order_id, $xml, $clave);
                if ($xml_result['success']) {
                    $order->update_meta_data('_fe_woo_xml_file_path', $xml_result['file_path']);
                }
            }

            // Save Hacienda response as JSON (for reference)
            $acuse_result = FE_Woo_Document_Storage::save_acuse($item->order_id, $response, $clave);
            if ($acuse_result['success']) {
                $order->update_meta_data('_fe_woo_acuse_file_path', $acuse_result['file_path']);
            }

            // Persist el MensajeHacienda firmado. La respuesta del envío a
            // veces ya trae el acuse firmado (best-effort). Si no, agendamos
            // el cron de polling con backoff en lugar de bloquear el worker
            // del queue. Esto libera el lock del transient antes y permite
            // que el cron tick procese más items por hora sin riesgo de
            // consumir 96s+ por orden.
            self::save_acuse_xml_from_response($item->order_id, $clave, $response);
            if (!FE_Woo_Document_Storage::get_acuse_xml_path($item->order_id, $clave)) {
                if (!wp_next_scheduled(self::POLL_ACUSE_HOOK, [$item->order_id, $clave, 1])) {
                    wp_schedule_single_event(time() + 30, self::POLL_ACUSE_HOOK, [$item->order_id, $clave, 1]);
                }
            }

            // Generate and save PDF (exonerations only apply to parent/default emisor)
            $apply_exoneracion = $emisor && !empty($emisor->is_parent);
            $emisor_pdf_data = $emisor ? FE_Woo_Factura_Generator::prepare_emisor_data($emisor) : null;
            $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
            if ($pdf_result['success']) {
                $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                $pdf_save_result = FE_Woo_Document_Storage::save_pdf($item->order_id, $pdf_result['pdf_content'], $clave, $is_html);
                if ($pdf_save_result['success']) {
                    $order->update_meta_data('_fe_woo_pdf_file_path', $pdf_save_result['file_path']);
                    $format = $is_html ? 'HTML' : 'PDF';
                    self::log(sprintf('%s document generated and saved for order #%d', $format, $item->order_id), 'debug');
                } else {
                    self::log(sprintf('Failed to save PDF for order #%d: %s', $item->order_id, $pdf_save_result['error']), 'error');
                }
            } else {
                self::log(sprintf('Failed to generate PDF for order #%d: %s', $item->order_id, $pdf_result['error']), 'error');
            }

            // Update order meta. save_acuse_xml_from_response (llamado arriba)
            // puede haber resuelto _fe_woo_hacienda_status en una $order fresca
            // de DB cuando respuesta-xml llegó en la misma respuesta del envío.
            // No debemos leer el meta de la copia in-memory estale — sobreescribiríamos
            // "aceptado"/"rechazado" con "procesando". Reload desde DB antes de chequear.
            $order->update_meta_data('_fe_woo_document_type', $document_type);
            $order->update_meta_data('_fe_woo_factura_clave', $clave);
            // En el path de recovery vía Hacienda (skip_post + clave ya recibida),
            // $xml es null porque no rebuild ni re-firmamos. Conservamos el XML
            // previamente persistido (si existe) en lugar de pisarlo con null.
            if ($xml !== null) {
                $order->update_meta_data('_fe_woo_factura_xml', $xml);
            }
            $order->update_meta_data('_fe_woo_factura_status', 'sent');
            $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
            // Promover pending → confirmed: la clave ya fue recibida por Hacienda
            // (sea via POST exitoso o recovery). Borrar el meta pending evita que
            // el próximo tick re-intente recovery sobre una clave ya confirmada.
            $order->delete_meta_data('_fe_woo_factura_clave_pending');
            $order->save();

            // Now read fresh — this sees whatever the poll wrote.
            $fresh = wc_get_order($item->order_id);
            $existing_hacienda_status = (string) $fresh->get_meta('_fe_woo_hacienda_status');
            if ($existing_hacienda_status === '') {
                $fresh->update_meta_data('_fe_woo_hacienda_status', 'procesando');
                $fresh->save();
            }

            // Order note reflecting Hacienda's actual verdict. Adding
            // "enviado exitosamente" before we know the verdict misleads
            // the operator when Hacienda ends up rejecting — the note
            // history would say "success" right next to a red rejection.
            // Nota del envío. Las notas de veredicto terminal (aceptada /
            // rechazada con motivo) las agrega save_acuse_xml_from_response
            // cuando llega el acuse, gateadas por previous_status para
            // evitar duplicados entre cron, polling JS y re-consulta.
            $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
            $order->add_order_note(sprintf(
                __('%s enviada a Hacienda. Clave: %s', 'fe-woo'),
                $document_label, $clave
            ));

            self::log(sprintf('Successfully sent order #%d (%s) to Hacienda. Clave: %s', $item->order_id, $document_type, $clave));

            // Email is sent from save_acuse_xml_from_response() once Hacienda
            // confirms "aceptado". Sending here (before the poll resolves)
            // would ship documents for invoices that may still be rejected.

            // El envío fue exitoso (tenemos clave). El veredicto de Hacienda
            // lo resuelve el cron poll_acuse_xml en background, no el queue.
            // Marcamos el queue item como completed para evitar que el siguiente
            // tick re-pickup el item (STATUS_RETRY entra en get_pending_items)
            // y regenere con NUEVA clave, generando un documento duplicado en
            // Hacienda.
            //
            // Único caso especial: si Hacienda devolvió rechazo en línea (la
            // respuesta del envío ya incluyó respuesta-xml con
            // EstadoMensaje='rechazado'), marcamos failed sin auto-retry —
            // el operador debe corregir datos. Si Hacienda rechaza
            // asincrónicamente vía el cron poll_acuse_xml, _fe_woo_hacienda_status
            // queda 'rechazado' en la orden + nota terminal visible en el
            // panel admin. El admin usa "Reintentar" para crear un nuevo
            // queue item con nueva clave.
            $order = wc_get_order($item->order_id);
            $final_status = $order ? strtolower((string) $order->get_meta('_fe_woo_hacienda_status')) : '';

            if ($final_status === 'rechazado') {
                $detalle = $order ? (string) $order->get_meta('_fe_woo_hacienda_detalle') : '';
                FE_Woo_Queue::mark_failed(
                    $item->id,
                    sprintf('Rechazado por Hacienda: %s', substr($detalle ?: 'sin detalle', 0, 500)),
                    false // do NOT retry automatically
                );
            } else {
                // aceptado / procesando / recibido / vacío → envío exitoso.
                // Cerramos el queue item; el polling del acuse se resuelve aparte.
                FE_Woo_Queue::mark_completed($item->id, $clave, $xml, $response);
            }

        } catch (Exception $e) {
            // Mark as failed
            $error_message = $e->getMessage();
            FE_Woo_Queue::mark_failed($item->id, $error_message, true);

            // Log error
            $doc_type = isset($document_type) ? $document_type : 'tiquete';
            self::log(sprintf('Failed to process order #%d (%s): %s', $item->order_id, $doc_type, $error_message), 'error');

            // Add order note
            if (isset($order) && $order) {
                $doc_label = ($doc_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
                $order->add_order_note(
                    sprintf(
                        __('Error al generar %s: %s', 'fe-woo'),
                        $doc_label,
                        $error_message
                    )
                );
            }
        }
    }

    /**
     * Send factura or tiquete email to customer
     *
     * Uses WC_Factura_Email (extends WC_Email) for branded HTML emails
     * with WooCommerce header/footer and order details.
     *
     * @param WC_Order $order Order object
     * @param string   $clave Invoice clave
     * @param string   $document_type Document type (factura or tiquete)
     */
    private static function send_factura_email($order, $clave, $document_type = 'tiquete') {
        $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';

        // Collect attachments
        $attachments = [];
        $document_paths = FE_Woo_Document_Storage::get_document_paths($order->get_id(), $clave);

        self::log(sprintf('Preparing %s email for order #%d', $document_label, $order->get_id()));
        self::log(sprintf('Document paths retrieved: PDF=%s, XML=%s, Acuse=%s',
            isset($document_paths['pdf']) ? 'YES' : 'NO',
            isset($document_paths['xml']) ? 'YES' : 'NO',
            isset($document_paths['acuse']) ? 'YES' : 'NO'
        ));

        // Attach PDF file (most important for the customer)
        if (!empty($document_paths['pdf']) && file_exists($document_paths['pdf'])) {
            $attachments[] = $document_paths['pdf'];
            self::log(sprintf('  - PDF: %s', $document_paths['pdf']));
        }

        // Attach XML file (original signed document). This is the only XML the
        // recipient's email-to-invoice service (GTI, etc.) should parse.
        if (!empty($document_paths['xml']) && file_exists($document_paths['xml'])) {
            $attachments[] = $document_paths['xml'];
            self::log(sprintf('  - XML: %s', $document_paths['xml']));
        }

        // Also attach the Acuse de Hacienda (AHC) — the signed
        // MensajeHacienda that Hacienda returns confirming acceptance.
        // Recipients' email-to-invoice services typically want both the
        // original signed factura and the government acknowledgement
        // alongside the PDF.
        if (!empty($document_paths['acuse_xml']) && file_exists($document_paths['acuse_xml'])) {
            $attachments[] = $document_paths['acuse_xml'];
            self::log(sprintf('  - AHC: %s', $document_paths['acuse_xml']));
        }

        self::log(sprintf('Total attachments prepared: %d', count($attachments)));

        // Send via WC_Factura_Email (branded HTML template)
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (!isset($emails['WC_Factura_Email'])) {
            self::log(sprintf('WC_Factura_Email class not found — cannot send %s email for order #%d', $document_label, $order->get_id()), 'error');
            return;
        }

        // Determine recipient (factura uses invoice email, tiquete uses billing email)
        $recipient = ($document_type === 'factura')
            ? ($order->get_meta('_fe_woo_invoice_email') ?: $order->get_billing_email())
            : $order->get_billing_email();

        if (empty($recipient)) {
            self::log(sprintf('No recipient email found for order #%d — skipping %s email', $order->get_id(), $document_label), 'error');
            return;
        }

        $emails['WC_Factura_Email']->trigger($order->get_id(), $order, $clave, $document_type, $attachments);
        self::log(sprintf('%s email triggered for %s on order #%d with %d attachments (delivery depends on mail server)', $document_label, $recipient, $order->get_id(), count($attachments)));
    }

    /**
     * Process multiple facturas for a single order
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     */
    private static function process_multi_factura($order, $item, $document_type, $multi_factura_result) {
        // Delegate to shared helper; on failure it throws so process_item catches it
        $result = self::generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, 'start');

        // Mark queue item as completed.
        // Fix B-4 (v1.14.0): la columna `clave` es varchar(50). Una clave
        // individual ocupa 50 chars; concatenar 2+ claves con implode genera
        // 100+ chars y el UPDATE falla silenciosamente (item queda en
        // STATUS_PROCESSING, C-2 lo recupera, próximo tick re-genera = duplicación).
        // Guardamos la primera clave en el campo `clave` y la lista completa
        // en el JSON `data` para traceability.
        FE_Woo_Queue::mark_completed($item->id, $result['first_clave'], '', [
            'multi_factura' => true,
            'all_claves'    => $result['all_claves'],
        ]);

        // Update order meta
        self::save_multi_factura_order_meta($order, $document_type, $result);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('%d Facturas Electrónicas enviadas exitosamente.', 'fe-woo'),
                count($result['facturas_generated'])
            )
        );

        self::log(sprintf('Successfully processed multi-factura for order #%d. Generated %d facturas.', $order->get_id(), count($result['facturas_generated'])));

        // Email asíncrono (Fix B-5 v1.14.0) — evita bloquear el cron tick
        // por 2-4 min/email. El meta _fe_woo_facturas_generated quedó
        // persistido por save_multi_factura_order_meta arriba, así que el
        // handler async puede leerlo cuando dispare.
        if (!wp_next_scheduled(self::MULTI_FACTURA_EMAIL_HOOK, [$order->get_id()])) {
            wp_schedule_single_event(time() + 5, self::MULTI_FACTURA_EMAIL_HOOK, [$order->get_id()]);
        }
    }

    /**
     * Async handler para envío de email multi-factura (Fix B-5 v1.14.0).
     *
     * Lee facturas_generated y document_type de la order meta y delega al
     * sender existente. Llamado por wp_schedule_single_event agendado desde
     * process_multi_factura.
     *
     * @param int $order_id
     */
    public static function send_multi_factura_email_async($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) {
            self::log(sprintf('send_multi_factura_email_async: order #%d not found', $order_id), 'error');
            return;
        }

        $facturas_generated = $order->get_meta('_fe_woo_facturas_generated');
        if (empty($facturas_generated) || !is_array($facturas_generated)) {
            self::log(sprintf('send_multi_factura_email_async: no facturas_generated meta for order #%d', $order_id), 'error');
            return;
        }

        $document_type = $order->get_meta('_fe_woo_document_type') ?: 'tiquete';
        self::send_multi_factura_email($order, $facturas_generated, $document_type);
    }

    /**
     * Core multi-factura generation and sending logic (shared by queue and immediate modes)
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @param string   $log_stage Log stage label
     * @return array Array with 'facturas_generated', 'all_claves', 'first_clave'
     * @throws Exception On failure
     */
    private static function generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, $log_stage = 'start') {
        $facturas_generated = [];
        $all_claves = [];
        $first_clave = null;
        $order_id = $order->get_id();

        // Check for partial retry: skip emisores whose facturas were already sent
        $already_sent_emisor_ids = [];
        $is_partial_retry = $order->get_meta('_fe_woo_multi_factura_partial') === 'yes';

        if ($is_partial_retry) {
            $previous_facturas = $order->get_meta('_fe_woo_facturas_generated');
            if (!empty($previous_facturas) && is_array($previous_facturas)) {
                foreach ($previous_facturas as $prev_factura) {
                    if (!empty($prev_factura['status']) && $prev_factura['status'] === 'sent') {
                        $already_sent_emisor_ids[] = (int) $prev_factura['emisor_id'];
                        // Carry over previously sent facturas into results
                        $facturas_generated[] = $prev_factura;
                        $all_claves[] = $prev_factura['clave'];
                        if ($first_clave === null) {
                            $first_clave = $prev_factura['clave'];
                        }
                    }
                }

                if (!empty($already_sent_emisor_ids)) {
                    self::log(sprintf(
                        'Partial retry for order #%d: skipping %d already-sent emisor(es): %s',
                        $order_id,
                        count($already_sent_emisor_ids),
                        implode(', ', $already_sent_emisor_ids)
                    ));
                }
            }
        }

        // Filter out already-sent facturas
        $facturas_to_process = $multi_factura_result['facturas'];
        if (!empty($already_sent_emisor_ids)) {
            $facturas_to_process = array_filter($facturas_to_process, function ($factura_data) use ($already_sent_emisor_ids) {
                return !in_array((int) $factura_data['emisor_id'], $already_sent_emisor_ids, true);
            });
            $facturas_to_process = array_values($facturas_to_process); // Re-index
        }

        $total_facturas = count($facturas_to_process) + count($already_sent_emisor_ids);
        self::log(sprintf('Processing multi-factura for order #%d: %d facturas to generate (%d already sent)', $order_id, count($facturas_to_process), count($already_sent_emisor_ids)));

        FE_Woo_Multi_Factura_Generator::log_processing($order, $multi_factura_result['facturas'], $log_stage);

        // Create a single API client instance to reuse across all facturas
        $api_client = new FE_Woo_API_Client();

        foreach ($facturas_to_process as $index => $factura_data) {
            try {
                $emisor_id = $factura_data['emisor_id'];
                $line_items = $factura_data['items'];
                $factura_type = $factura_data['type'];

                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
                }
                if (!$emisor) {
                    throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
                }

                if (empty($emisor->api_username) || empty($emisor->api_password)) {
                    throw new Exception(sprintf(
                        __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                        $emisor->nombre_legal
                    ));
                }

                self::log(sprintf('  Generating factura %d/%d - Emisor: %s (%d items)',
                    $index + 1 + count($already_sent_emisor_ids),
                    $total_facturas,
                    $emisor->nombre_legal,
                    count($line_items)
                ));

                $include_shipping = !empty($factura_data['include_shipping']);

                // Pre-flight: validar coherencia código-tarifa-monto antes de
                // consumir un consecutivo (ver explicación en single-factura).
                $emisor_data_for_validation = FE_Woo_Factura_Generator::prepare_emisor_data($emisor);
                $apply_exon_pre_mf = !empty($emisor->is_parent);
                $preflight = FE_Woo_Factura_Generator::validate_invoice_data($order, $document_type, $emisor_data_for_validation, $line_items, $apply_exon_pre_mf);
                if (!$preflight['valid']) {
                    throw new Exception(sprintf(
                        __('Pre-flight (factura %d emisor %s): el documento sería rechazado por Hacienda. Corrige antes de reintentar:', 'fe-woo'),
                        $index + 1, $emisor->nombre_legal
                    ) . "\n• " . implode("\n• ", $preflight['errors']));
                }

                $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items, $include_shipping);

                if (!$result['success']) {
                    // If skipped because all items for this emisor are non-taxable, skip this factura
                    if (!empty($result['skipped'])) {
                        self::log(sprintf('  Factura %d/%d skipped - all items for emisor %s have tax_status none',
                            $index + 1, $total_facturas, $emisor->nombre_legal));
                        continue;
                    }
                    throw new Exception($result['error']);
                }

                $clave = $result['clave'];
                $xml = $result['xml'];

                if ($first_clave === null) {
                    $first_clave = $clave;
                }

                $xml = self::sign_and_validate($xml, $emisor, $order->get_id(), $clave);

                $response = $api_client->send_invoice_with_emisor($xml, $emisor);

                if (!$response['success']) {
                    $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                    if (isset($response['error_detail'])) {
                        $error_message .= ' - ' . $response['error_detail'];
                    }
                    throw new Exception($error_message);
                }

                // Save documents
                $xml_result = FE_Woo_Document_Storage::save_xml($order_id, $xml, $clave);
                FE_Woo_Document_Storage::save_acuse($order_id, $response, $clave);
                self::save_acuse_xml_from_response($order_id, $clave, $response);
                if (!FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave)) {
                    if (!wp_next_scheduled(self::POLL_ACUSE_HOOK, [$order_id, $clave, 1])) {
                        wp_schedule_single_event(time() + 30, self::POLL_ACUSE_HOOK, [$order_id, $clave, 1]);
                    }
                }

                // Generate PDF (exonerations only apply to parent/default emisor)
                $pdf_path = '';
                $emisor_pdf_data = FE_Woo_Factura_Generator::prepare_emisor_data($emisor);
                $apply_exoneracion = $emisor && !empty($emisor->is_parent);
                $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
                if ($pdf_result['success']) {
                    $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                    $pdf_save_result = FE_Woo_Document_Storage::save_pdf($order_id, $pdf_result['pdf_content'], $clave, $is_html);
                    if ($pdf_save_result['success']) {
                        $pdf_path = $pdf_save_result['file_path'] ?? '';
                    }
                }

                $facturas_generated[] = [
                    'emisor_id' => $emisor_id,
                    'emisor_name' => $emisor->nombre_legal,
                    'clave' => $clave,
                    'xml_path' => $xml_result['file_path'] ?? '',
                    'pdf_path' => $pdf_path,
                    'monto' => FE_Woo_Multi_Factura_Generator::calculate_items_total($line_items),
                    'type' => $factura_type,
                    'items_count' => count($line_items),
                    'status' => 'sent',
                    'hacienda_status' => 'procesando',
                    'sent_date' => current_time('mysql'),
                ];

                $all_claves[] = $clave;

                self::log(sprintf('  Factura %d generated successfully. Clave: %s', $index + 1 + count($already_sent_emisor_ids), $clave));

            } catch (Exception $e) {
                // Save partial results so already-sent facturas are tracked
                $new_facturas_count = count($facturas_generated) - count($already_sent_emisor_ids);
                if ($new_facturas_count > 0 || !empty($already_sent_emisor_ids)) {
                    self::log(sprintf('  Partial multi-factura: %d/%d facturas sent before failure for order #%d',
                        count($facturas_generated),
                        $total_facturas,
                        $order_id
                    ), 'error');

                    $order->update_meta_data('_fe_woo_multi_factura', 'yes');
                    $order->update_meta_data('_fe_woo_multi_factura_partial', 'yes');
                    $order->update_meta_data('_fe_woo_facturas_generated', $facturas_generated);
                    $order->update_meta_data('_fe_woo_facturas_count', count($facturas_generated));
                    $order->update_meta_data('_fe_woo_facturas_expected', $total_facturas);
                    $order->save();
                }

                throw new Exception(sprintf('Failed to generate factura %d/%d for emisor #%d: %s',
                    $index + 1 + count($already_sent_emisor_ids),
                    $total_facturas,
                    $emisor_id,
                    $e->getMessage()
                ));
            }
        }

        // Clear partial retry flag on full success
        if ($is_partial_retry) {
            $order->delete_meta_data('_fe_woo_multi_factura_partial');
            $order->save();
            self::log(sprintf('Partial retry completed successfully for order #%d. Cleared partial flag.', $order_id));
        }

        return [
            'facturas_generated' => $facturas_generated,
            'all_claves' => $all_claves,
            'first_clave' => $first_clave,
        ];
    }

    /**
     * Save multi-factura metadata to order
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $result Result from generate_and_send_multi_facturas
     */
    private static function save_multi_factura_order_meta($order, $document_type, $result) {
        $order->update_meta_data('_fe_woo_multi_factura', 'yes');
        $order->update_meta_data('_fe_woo_facturas_generated', $result['facturas_generated']);
        $order->update_meta_data('_fe_woo_facturas_count', count($result['facturas_generated']));
        $order->update_meta_data('_fe_woo_document_type', $document_type);
        $order->update_meta_data('_fe_woo_factura_clave', $result['first_clave']);
        $order->update_meta_data('_fe_woo_factura_status', 'sent');
        $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
        if ((string) $order->get_meta('_fe_woo_hacienda_status') === '') {
            $order->update_meta_data('_fe_woo_hacienda_status', 'procesando');
        }
        $order->save();
    }

    /**
     * Send multi-factura email to customer
     *
     * Uses WC_Multi_Factura_Email (extends WC_Email) for branded HTML emails.
     * Sends a separate email for each factura with its documents attached.
     *
     * @param WC_Order $order Order object
     * @param array    $facturas_generated Generated facturas data
     * @param string   $document_type Document type
     */
    private static function send_multi_factura_email($order, $facturas_generated, $document_type = 'tiquete') {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (!isset($emails['WC_Multi_Factura_Email'])) {
            self::log(sprintf('WC_Multi_Factura_Email class not found — cannot send multi-factura emails for order #%d', $order->get_id()), 'error');
            return;
        }

        // Determine recipient (factura uses invoice email, tiquete uses billing email)
        $recipient = ($document_type === 'factura')
            ? ($order->get_meta('_fe_woo_invoice_email') ?: $order->get_billing_email())
            : $order->get_billing_email();

        if (empty($recipient)) {
            self::log(sprintf('No recipient email found for order #%d — skipping multi-factura emails', $order->get_id()), 'error');
            return;
        }

        self::log(sprintf(
            'Triggering %d separate multi-factura emails for order #%d to %s',
            count($facturas_generated),
            $order->get_id(),
            $recipient
        ));

        $emails_triggered = 0;

        // Send a separate email for each factura
        foreach ($facturas_generated as $factura) {
            // Get document paths for this factura
            $attachments = [];
            $document_paths = FE_Woo_Document_Storage::get_document_paths($order->get_id(), $factura['clave']);

            // Add PDF
            if (!empty($document_paths['pdf']) && file_exists($document_paths['pdf'])) {
                $attachments[] = $document_paths['pdf'];
            }

            // Add signed XML
            if (!empty($document_paths['xml']) && file_exists($document_paths['xml'])) {
                $attachments[] = $document_paths['xml'];
            }

            // Add AHC (signed MensajeHacienda acknowledgement)
            if (!empty($document_paths['acuse_xml']) && file_exists($document_paths['acuse_xml'])) {
                $attachments[] = $document_paths['acuse_xml'];
            }

            // Send email for this factura via WC_Multi_Factura_Email
            $emails['WC_Multi_Factura_Email']->trigger(
                $order->get_id(),
                $order,
                $factura,
                $document_type,
                $attachments
            );

            $emails_triggered++;
            self::log(sprintf(
                'Multi-factura email triggered for factura %s (emisor: %s) to %s on order #%d with %d attachments',
                $factura['clave'],
                $factura['emisor_name'],
                $recipient,
                $order->get_id(),
                count($attachments)
            ));
        }

        self::log(sprintf(
            'Multi-factura email summary for order #%d: %d triggered (delivery depends on mail server)',
            $order->get_id(),
            $emails_triggered
        ));
    }

    /**
     * Log message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, error, debug)
     */
    private static function log($message, $level = 'info') {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-queue-processor'];

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

    /**
     * Process a single order immediately (synchronously)
     *
     * This bypasses the queue system and processes the order directly
     * Handles both single and multi-factura orders
     *
     * @param int  $order_id Order ID
     * @param bool $force Force regeneration even if invoice already exists
     * @return array Result with success boolean and message
     */
    public static function process_order_immediately($order_id, $force = false, $skip_lock = false) {
        // Lock defensivo: serializa mutaciones concurrentes sobre la misma orden.
        //
        // Callers que ya adquirieron el lock (ajax_retry_with_updated_data,
        // ajax_manual_execute_factura) deben pasar $skip_lock=true para evitar
        // un acquire redundante que fallaría con UNIQUE KEY violation.
        //
        // Callers sin lock (CLI directos, código interno) caen en el camino
        // por defecto: si la orden ya está bloqueada, RECHAZAMOS — no
        // proceder asumiendo que el caller "sabe lo que hace" porque eso
        // permite concurrencia silenciosa y comprobantes huérfanos.
        $owns_lock = false;
        if (!$skip_lock && class_exists('FE_Woo_Order_Lock')) {
            if (!FE_Woo_Order_Lock::acquire($order_id, $force ? 'reexecute' : 'process_immediate', 180)) {
                $existing = FE_Woo_Order_Lock::inspect($order_id);
                return [
                    'success' => false,
                    'message' => sprintf(
                        __('Orden #%d ya tiene una operación FE en proceso (%s, %ds restantes). Espera o intenta de nuevo en unos segundos.', 'fe-woo'),
                        $order_id,
                        $existing['operation'] ?? 'unknown',
                        $existing['remaining'] ?? 0
                    ),
                ];
            }
            $owns_lock = true;
        }

        try {
            return self::process_order_immediately_inner($order_id, $force);
        } finally {
            if ($owns_lock && class_exists('FE_Woo_Order_Lock')) {
                FE_Woo_Order_Lock::release($order_id);
            }
        }
    }

    private static function process_order_immediately_inner($order_id, $force = false) {
        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            return [
                'success' => false,
                'message' => __('El procesamiento de facturas está pausado. Por favor, reactive el procesamiento en Configuración de FE para continuar.', 'fe-woo'),
            ];
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            return [
                'success' => false,
                'message' => $ready_status['message'],
            ];
        }

        // Get order
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Order not found', 'fe-woo'),
            ];
        }

        // Check if factura already exists
        $existing_clave = $order->get_meta('_fe_woo_factura_clave');
        if (!empty($existing_clave) && !$force) {
            return [
                'success' => false,
                'message' => __('Esta orden ya tiene una factura electrónica generada.', 'fe-woo'),
            ];
        }

        // If forcing regeneration, clear old invoice data first
        if ($force && !empty($existing_clave)) {
            self::log(sprintf('Forcing regeneration for order #%d (previous clave: %s)', $order_id, $existing_clave));
            self::clear_invoice_data($order_id);
            // clear_invoice_data() borra meta usando su propia instancia $order
            // y la persiste. Nuestra $order de arriba quedó stale: aún tiene
            // los meta_id viejos en memoria, y un update_meta_data + save()
            // posterior haría UPDATE WHERE meta_id = X — pero ese row ya no
            // existe, así que afecta 0 filas y la nueva clave se pierde
            // silenciosamente (verificado empíricamente en orden 16176 dev).
            // Recargar es la única forma segura tras una mutación lateral.
            $order = wc_get_order($order_id);
        }

        // Remove from queue if exists
        FE_Woo_Queue::remove_from_queue($order_id);

        // Determine document type
        $document_type = ($order->get_meta('_fe_woo_require_factura') === 'yes') ? 'factura' : 'tiquete';

        self::log(sprintf('Processing order #%d immediately (manual execution)', $order_id));

        // Check if this order requires multi-factura processing
        $multi_factura_result = FE_Woo_Multi_Factura_Generator::generate_facturas_for_order($order);

        if (isset($multi_factura_result['error'])) {
            return [
                'success' => false,
                'message' => $multi_factura_result['error'],
            ];
        }

        // Check if multiple facturas are needed
        if ($multi_factura_result['multiple']) {
            // Process multiple facturas
            return self::process_multi_factura_immediately($order, $document_type, $multi_factura_result);
        } else {
            // Single factura processing
            return self::process_single_factura_immediately($order, $document_type, $multi_factura_result);
        }
    }

    /**
     * Process single factura immediately
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @return array Result with success boolean and message
     */
    private static function process_single_factura_immediately($order, $document_type, $multi_factura_result) {
        $order_id = $order->get_id();

        // Get the first (and only) factura data
        $factura_data = $multi_factura_result['facturas'][0];
        $emisor_id = $factura_data['emisor_id'];
        $line_items = $factura_data['items'];

        try {
            // Get the emisor object for this factura
            $emisor = null;
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
            }
            if (!$emisor) {
                $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
            }
            if (!$emisor) {
                throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
            }

            // Validate emisor has required credentials before proceeding
            if (empty($emisor->api_username) || empty($emisor->api_password)) {
                throw new Exception(sprintf(
                    __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                    $emisor->nombre_legal
                ));
            }

            // Validate exoneración if applicable (only for factura and only for parent/default emisor)
            if ($document_type === 'factura' && !empty($emisor->is_parent) && class_exists('FE_Woo_Exoneracion') && $order->get_meta('_fe_woo_has_exoneracion') === 'yes') {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($order_id);
                if (!$validation['valid']) {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_REJECTED_VALIDATION);
                    $order->save();
                    throw new Exception('Exoneración validation failed: ' . implode(', ', $validation['errors']));
                } else {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_VALID);
                    $order->save();
                }
            }

            // Pre-flight: validar coherencia código-tarifa-monto antes de
            // consumir un consecutivo (ver explicación en single-factura cron).
            $emisor_data_for_validation = FE_Woo_Factura_Generator::prepare_emisor_data($emisor);
            $apply_exon_pre_imm = !empty($emisor->is_parent);
            $preflight = FE_Woo_Factura_Generator::validate_invoice_data($order, $document_type, $emisor_data_for_validation, $line_items, $apply_exon_pre_imm);
            if (!$preflight['valid']) {
                return [
                    'success' => false,
                    'message' => __('Pre-flight: el documento sería rechazado por Hacienda. Corrige antes de reintentar:', 'fe-woo')
                        . "\n• " . implode("\n• ", $preflight['errors']),
                ];
            }

            // Generate factura or tiquete XML with specific emisor and line items
            $include_shipping = !empty($factura_data['include_shipping']);
            $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items, $include_shipping);

            if (!$result['success']) {
                // If skipped because all items are non-taxable, return gracefully
                if (!empty($result['skipped'])) {
                    $order->add_order_note(__('No se generó documento electrónico: todos los productos tienen estado de impuesto "ninguno".', 'fe-woo'));
                    self::log(sprintf('Order #%d skipped (immediate) - all items have tax_status none', $order_id));
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => $result['error'],
                    ];
                }
                throw new Exception($result['error']);
            }

            $clave = $result['clave'];
            $xml = $result['xml'];

            $xml = self::sign_and_validate($xml, $emisor, (int) $order_id, $clave);

            // Send to Hacienda using emisor's specific credentials
            $api_client = new FE_Woo_API_Client();
            $response = $api_client->send_invoice_with_emisor($xml, $emisor);

            if (!$response['success']) {
                // Include detailed error for connection/authentication failures
                $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                if (isset($response['error_detail'])) {
                    $error_message .= ' - ' . $response['error_detail'];
                }
                throw new Exception($error_message);
            }

            // Save documents to filesystem
            $xml_result = FE_Woo_Document_Storage::save_xml($order_id, $xml, $clave);
            if ($xml_result['success']) {
                $order->update_meta_data('_fe_woo_xml_file_path', $xml_result['file_path']);
            }

            // Save Hacienda response as JSON (for reference)
            $acuse_result = FE_Woo_Document_Storage::save_acuse($order_id, $response, $clave);
            if ($acuse_result['success']) {
                $order->update_meta_data('_fe_woo_acuse_file_path', $acuse_result['file_path']);
            }

            // Signed MensajeHacienda XML — best-effort: la respuesta del envío
            // a veces ya trae el acuse firmado.
            self::save_acuse_xml_from_response($order_id, $clave, $response);

            // Si Hacienda no devolvió el acuse en la misma respuesta del envío,
            // programar el cron de polling en background. NO bloqueamos la
            // request HTTP del admin — el browser hará polling vía recheck_status
            // hasta ver veredicto, y este cron es la red de seguridad si el
            // browser se cierra antes de que llegue el veredicto.
            if (!FE_Woo_Document_Storage::get_acuse_xml_path($order_id, $clave)) {
                if (!wp_next_scheduled(self::POLL_ACUSE_HOOK, [$order_id, $clave, 1])) {
                    wp_schedule_single_event(time() + 30, self::POLL_ACUSE_HOOK, [$order_id, $clave, 1]);
                }
            }

            // Generate and save PDF (exonerations only apply to parent/default emisor)
            $apply_exoneracion = $emisor && !empty($emisor->is_parent);
            $emisor_pdf_data = $emisor ? FE_Woo_Factura_Generator::prepare_emisor_data($emisor) : null;
            $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
            if ($pdf_result['success']) {
                $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                $pdf_save_result = FE_Woo_Document_Storage::save_pdf($order_id, $pdf_result['pdf_content'], $clave, $is_html);
                if ($pdf_save_result['success']) {
                    $order->update_meta_data('_fe_woo_pdf_file_path', $pdf_save_result['file_path']);
                }
            }

            // Update order meta. The $order instance here is stale from
            // the method start — save_acuse_xml_from_response wrote
            // _fe_woo_hacienda_status via its own fresh order load, so we
            // must re-read from DB before deciding whether to default to
            // "procesando".
            $order->update_meta_data('_fe_woo_document_type', $document_type);
            $order->update_meta_data('_fe_woo_factura_clave', $clave);
            $order->update_meta_data('_fe_woo_factura_xml', $xml);
            $order->update_meta_data('_fe_woo_factura_status', 'sent');
            $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
            $order->save();

            $fresh = wc_get_order($order_id);
            if ((string) $fresh->get_meta('_fe_woo_hacienda_status') === '') {
                $fresh->update_meta_data('_fe_woo_hacienda_status', 'procesando');
                $fresh->save();
            }

            // Nota del envío. Las notas de veredicto terminal (aceptada /
            // rechazada con motivo) las agrega save_acuse_xml_from_response
            // cuando llega el acuse, gateadas por previous_status para
            // evitar duplicados entre cron, polling JS y re-consulta.
            $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
            $order->add_order_note(sprintf(
                __('%s enviada a Hacienda. Clave: %s', 'fe-woo'),
                $document_label, $clave
            ));

            $fresh_for_note = wc_get_order($order_id);
            $verdict = strtolower((string) $fresh_for_note->get_meta('_fe_woo_hacienda_status'));
            self::log(sprintf('Processed order #%d immediately (%s). Clave: %s. Verdict: %s', $order_id, $document_type, $clave, $verdict ?: 'unknown'));

            // Send email to customer
            self::send_factura_email($order, $clave, $document_type);

            // Payload para el frontend: si el veredicto ya es terminal, el
            // browser no necesita arrancar polling. Si no, pending=true y el
            // JS hace polling vía recheck_status hasta verlo terminal.
            $is_terminal = in_array($verdict, ['aceptado', 'rechazado'], true);

            return [
                'success'        => true,
                'message'        => sprintf(
                    __('%s generado exitosamente. Clave: %s', 'fe-woo'),
                    $document_label,
                    $clave
                ),
                'clave'          => $clave,
                'order_id'       => $order_id,
                'pending'        => !$is_terminal,
                'current_status' => $verdict ?: 'procesando',
            ];

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $doc_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';

            // Log error
            self::log(sprintf('Failed to process order #%d immediately: %s', $order_id, $error_message), 'error');

            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Error al generar %s (ejecución manual): %s', 'fe-woo'),
                    $doc_label,
                    $error_message
                )
            );

            return [
                'success' => false,
                'message' => sprintf(
                    __('Error al generar %s: %s', 'fe-woo'),
                    $doc_label,
                    $error_message
                ),
            ];
        }
    }

    /**
     * Process multiple facturas immediately (for manual execution)
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @return array Result with success boolean and message
     */
    private static function process_multi_factura_immediately($order, $document_type, $multi_factura_result) {
        $order_id = $order->get_id();

        try {
            $result = self::generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, 'immediate_start');
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            self::log($error_message, 'error');

            $order->add_order_note(
                sprintf(
                    __('Error al generar multi-factura (ejecución manual): %s', 'fe-woo'),
                    $error_message
                )
            );

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        // Update order meta
        self::save_multi_factura_order_meta($order, $document_type, $result);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('%d Facturas Electrónicas generadas y enviadas exitosamente (ejecución manual).', 'fe-woo'),
                count($result['facturas_generated'])
            )
        );

        self::log(sprintf('Successfully processed multi-factura immediately for order #%d. Generated %d facturas.', $order_id, count($result['facturas_generated'])));

        // Send emails
        self::send_multi_factura_email($order, $result['facturas_generated'], $document_type);

        return [
            'success' => true,
            'message' => sprintf(
                __('%d Facturas generadas exitosamente.', 'fe-woo'),
                count($result['facturas_generated'])
            ),
            'claves' => $result['all_claves'],
            'multi_factura' => true,
        ];
    }

    /**
     * Manual queue processing (for testing or manual trigger)
     *
     * @return array Result with processed count
     */
    public static function manual_process() {
        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            return [
                'success' => false,
                'processed' => 0,
                'message' => __('El procesamiento de facturas está pausado. Por favor, reactive el procesamiento en Configuración de FE para continuar.', 'fe-woo'),
            ];
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            return [
                'success' => false,
                'processed' => 0,
                'message' => $ready_status['message'],
            ];
        }

        $items = FE_Woo_Queue::get_pending_items(10);
        $processed = 0;

        foreach ($items as $item) {
            self::process_item($item);
            $processed++;
        }

        return [
            'success' => true,
            'processed' => $processed,
            'message' => sprintf(__('%d items processed', 'fe-woo'), $processed),
        ];
    }

    /**
     * Clear invoice data from order
     *
     * This removes all invoice-related metadata to allow regeneration
     *
     * @param int $order_id Order ID
     */
    public static function clear_invoice_data($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Get old clave for note
        $old_clave = $order->get_meta('_fe_woo_factura_clave');

        // Clear all invoice-related meta
        $meta_keys = [
            '_fe_woo_factura_clave',
            '_fe_woo_factura_clave_pending',
            '_fe_woo_factura_xml',
            '_fe_woo_factura_status',
            '_fe_woo_factura_sent_date',
            '_fe_woo_hacienda_status',
            '_fe_woo_hacienda_response',
            '_fe_woo_status_last_checked',
            '_fe_woo_xml_file_path',
            '_fe_woo_pdf_file_path',
            '_fe_woo_acuse_file_path',
            '_fe_woo_acuse_xml_file_path',
            '_fe_woo_hacienda_detalle',
            '_fe_woo_hacienda_estado_mensaje',
            '_fe_woo_document_type',
            '_fe_woo_multi_factura',
            '_fe_woo_multi_factura_partial',
            '_fe_woo_facturas_generated',
            '_fe_woo_facturas_count',
            '_fe_woo_facturas_expected',
        ];

        foreach ($meta_keys as $key) {
            $order->delete_meta_data($key);
        }

        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Datos de factura electrónica limpiados para regeneración. Clave anterior: %s', 'fe-woo'),
                $old_clave
            )
        );

        // Remove from queue if exists
        FE_Woo_Queue::remove_from_queue($order_id);

        self::log(sprintf('Cleared invoice data for order #%d (old clave: %s)', $order_id, $old_clave));
    }

    /**
     * Regenerate invoice for an order with updated CABYS codes
     *
     * This clears old invoice data and regenerates with current product CABYS codes
     *
     * @param int $order_id Order ID
     * @return array Result with success boolean and message
     */
    public static function regenerate_invoice($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Order not found', 'fe-woo'),
            ];
        }

        // Check if invoice exists
        $existing_clave = $order->get_meta('_fe_woo_factura_clave');
        if (empty($existing_clave)) {
            return [
                'success' => false,
                'message' => __('No hay factura electrónica para regenerar. Use el botón EJECUTAR en su lugar.', 'fe-woo'),
            ];
        }

        self::log(sprintf('Regenerating invoice for order #%d (previous clave: %s)', $order_id, $existing_clave));

        // Process with force flag
        return self::process_order_immediately($order_id, true);
    }

    /**
     * Re-execute an invoice by wiping artifacts and re-queuing the order.
     *
     * Unlike regenerate_invoice() this does NOT run synchronously — it deletes
     * the stored document files, clears invoice metadata and any existing queue
     * entry, then re-enqueues the order as pending so it is picked up by the
     * next queue processor run (manual or cron).
     *
     * @param int $order_id Order ID
     * @return array Result with success boolean, message and optional queue_id
     */
    public static function reexecute_invoice($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Orden no encontrada', 'fe-woo'),
            ];
        }

        if ($order instanceof \WC_Order_Refund || $order->get_type() !== 'shop_order') {
            return [
                'success' => false,
                'message' => sprintf(
                    __('Orden #%d es tipo %s; sólo shop_order genera factura electrónica.', 'fe-woo'),
                    $order_id,
                    $order->get_type()
                ),
            ];
        }

        $previous_clave = $order->get_meta('_fe_woo_factura_clave');

        FE_Woo_Document_Storage::delete_order_documents($order_id);

        self::clear_invoice_data($order_id);

        $document_type = ($order->get_meta('_fe_woo_require_factura') === 'yes') ? 'factura' : 'tiquete';

        $queue_id = FE_Woo_Queue::add_to_queue($order_id, null, $document_type);

        if (!$queue_id) {
            return [
                'success' => false,
                'message' => __('No se pudo re-encolar la factura.', 'fe-woo'),
            ];
        }

        $order->add_order_note(
            sprintf(
                __('Factura electrónica re-encolada: archivos eliminados y orden enviada a la cola para procesamiento. Clave anterior: %s', 'fe-woo'),
                $previous_clave ?: __('(ninguna)', 'fe-woo')
            )
        );

        self::log(sprintf('Re-executed invoice for order #%d (queue_id=%d, previous clave: %s)', $order_id, $queue_id, $previous_clave));

        return [
            'success' => true,
            'message' => __('Factura re-encolada. Se procesará en el próximo ciclo de la cola.', 'fe-woo'),
            'queue_id' => $queue_id,
        ];
    }

    /**
     * Sign an XML with the emisor's XAdES-EPES certificate and validate it
     * against the Hacienda v4.4 XSD. Dumps the unsigned XML for debugging
     * when signing fails (only when WP_DEBUG is on).
     *
     * Extracted from the three call sites in the queue processor to avoid
     * 14-line duplication that would drift on future edits.
     *
     * @param string $xml Unsigned XML.
     * @param object $emisor Emisor row (must have `certificate_path` + `certificate_pin`).
     * @param int    $order_id Order ID for debug dumps.
     * @param string $clave Invoice clave (or placeholder).
     * @return string Signed XML.
     * @throws Exception On signing or validation failure.
     */
    private static function sign_and_validate($xml, $emisor, $order_id, $clave) {
        $unsigned = $xml;
        try {
            $signed = FE_Woo_XML_Signer::sign($xml, $emisor->certificate_path, $emisor->certificate_pin);
        } catch (Exception $e) {
            FE_Woo_XML_Signer::dump_unsigned_xml((int) $order_id, (string) $clave, $unsigned);
            throw new Exception('Error al firmar XML: ' . $e->getMessage());
        }
        FE_Woo_XML_Validator::validate_or_throw($signed);
        return $signed;
    }
}
