<?php
/**
 * FE WooCommerce Factura Generator
 *
 * Generates electronic invoice XML according to Hacienda v4.4 specifications
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Factura_Generator Class
 *
 * Builds XML structure for Costa Rican electronic invoices
 */
class FE_Woo_Factura_Generator {

    /**
     * Source label for the current emission context (e.g. 'cron',
     * 'manual_operaciones_productor'). Read by `get_emission_datetime()` and
     * passed to the `fe_woo_emission_datetime` filter so callbacks can vary
     * the emission date based on processing context.
     *
     * Per-request static — multiple PHP-FPM workers do NOT share this value;
     * each request has its own. Set and cleared in a try/finally by callers
     * that want non-default semantics (notably `FE_Woo_Queue_Processor::process_items`).
     *
     * @var string|null
     */
    private static $emission_source_override = null;

    /**
     * Set (or clear with null) the current emission source override.
     *
     * Callers MUST clear this in a `finally` block — leaving it set across
     * unrelated emissions (e.g. a cron tick that runs after a manual batch
     * in the same request) would silently change FechaEmision semantics.
     *
     * @param string|null $source
     * @return void
     */
    public static function set_emission_source($source) {
        self::$emission_source_override = ($source === null) ? null : (string) $source;
    }

    /**
     * Crea un elemento XML con texto que se escapa automáticamente.
     *
     * `DOMDocument::createElement($name, $value)` NO escapa caracteres especiales:
     * un `&` en el valor lanza warning "unterminated entity reference" y deja el
     * elemento vacío, lo que rompe la validación XSD de Hacienda. Esto fue el
     * causante de los rechazos en órdenes con nombres tipo "ALFARO & JIMENEZ".
     *
     * Esta forma (createElement + appendChild(createTextNode)) es la canónica
     * para insertar contenido textual y maneja todos los caracteres especiales
     * sin que el caller tenga que escapar.
     *
     * @param DOMDocument $xml
     * @param string $name Nombre del elemento (no se escapa, se asume válido).
     * @param string|int|float|null $value Contenido textual (se escapa).
     * @return DOMElement
     */
    private static function text_element($xml, $name, $value) {
        $node = $xml->createElement($name);
        $node->appendChild($xml->createTextNode((string) ($value ?? '')));
        return $node;
    }


    /**
     * Document type codes
     */
    const DOC_TYPE_FACTURA_ELECTRONICA = '01';
    const DOC_TYPE_NOTA_DEBITO = '02';
    const DOC_TYPE_NOTA_CREDITO = '03';
    const DOC_TYPE_TIQUETE_ELECTRONICO = '04';

    /**
     * Currency codes
     */
    const CURRENCY_CRC = 'CRC';
    const CURRENCY_USD = 'USD';

    /**
     * Sales condition codes
     */
    const SALES_CONDITION_CASH = '01'; // Contado
    const SALES_CONDITION_CREDIT = '02'; // Crédito
    const SALES_CONDITION_CONSIGNMENT = '03'; // Consignación
    const SALES_CONDITION_APART = '04'; // Apartado
    const SALES_CONDITION_LEASE = '05'; // Arrendamiento
    const SALES_CONDITION_OTHER = '99'; // Otros

    /**
     * Payment method codes
     */
    const PAYMENT_CASH = '01'; // Efectivo
    const PAYMENT_CARD = '02'; // Tarjeta
    const PAYMENT_CHECK = '03'; // Cheque
    const PAYMENT_TRANSFER = '04'; // Transferencia
    const PAYMENT_OTHER = '99'; // Otros

    /**
     * Reference codes for credit/debit notes
     */
    const REFERENCE_ANULA = '01'; // Anula documento de referencia
    const REFERENCE_CORRIGE_TEXTO = '02'; // Corrige texto documento referencia
    const REFERENCE_CORRIGE_MONTO = '03'; // Corrige monto
    const REFERENCE_OTRO_DOCUMENTO = '04'; // Referencia a otro documento
    const REFERENCE_SUSTITUYE_CONTINGENCIA = '05'; // Sustituye comprobante provisional por contingencia
    const REFERENCE_OTROS = '99'; // Otros

    /**
     * Sufijo que se concatena SIEMPRE al OtrasSenas del Receptor en el XML.
     * Garantiza minLength=5 (XSD v4.4 OtrasSenas) cuando el cliente no completó
     * el campo. Se aplica solo al receptor; el emisor sigue requerido y validado.
     */
    const RECEPTOR_OTRAS_SENAS_SUFFIX = ' Otras senas para la direccion';

    /**
     * Computa el valor efectivo de <OtrasSenas> a partir del meta del order:
     *   - Trimea el input del cliente
     *   - Concatena RECEPTOR_OTRAS_SENAS_SUFFIX (siempre, vacío o no)
     *   - Trunca a 250 chars (XSD v4.4 maxLength)
     *
     * El meta del order NO se modifica — el suffix se aplica solo al emitir
     * XML y al validar. Para el cliente y el resto del sistema es invisible.
     *
     * @param string|null $client_value Valor crudo del meta `_fe_woo_otras_senas`.
     * @return string Texto listo para <OtrasSenas>, longitud entre 5 y 250.
     */
    public static function build_otras_senas_effective($client_value) {
        $trimmed = trim((string) $client_value);
        $combined = $trimmed === ''
            ? ltrim(self::RECEPTOR_OTRAS_SENAS_SUFFIX)
            : $trimmed . self::RECEPTOR_OTRAS_SENAS_SUFFIX;
        return function_exists('mb_substr')
            ? mb_substr($combined, 0, 250)
            : substr($combined, 0, 250);
    }

    /**
     * Prepare emisor data from emisor object
     *
     * @param object $emisor Emisor object from database
     * @return array Emisor data array
     */
    public static function prepare_emisor_data($emisor) {
        return [
            'nombre_legal' => $emisor->nombre_legal,
            'nombre_comercial' => $emisor->nombre_comercial ?? null,
            'cedula_juridica' => $emisor->cedula_juridica,
            'tipo_identificacion' => $emisor->tipo_identificacion ?? '02',
            'codigo_provincia' => $emisor->codigo_provincia,
            'codigo_canton' => $emisor->codigo_canton,
            'codigo_distrito' => $emisor->codigo_distrito,
            'codigo_barrio' => $emisor->codigo_barrio,
            'direccion' => $emisor->direccion,
            'telefono' => $emisor->telefono,
            'email' => $emisor->email,
            'actividad_economica' => $emisor->actividad_economica,
        ];
    }

    /**
     * Generate factura or tiquete XML from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $document_type Document type: 'factura' or 'tiquete' (default: 'tiquete')
     * @param int      $emisor_id Optional emisor ID (if null, uses parent)
     * @param array    $line_items Optional specific line items to include
     * @return array Array with 'success', 'clave', 'xml' keys
     */
    public static function generate_from_order($order, $document_type = 'tiquete', $emisor_id = null, $line_items = null, $include_shipping = false) {
        try {
            // Check if there are any taxable items — skip invoice if all items have tax_status 'none'
            $items_to_check = $line_items !== null ? $line_items : $order->get_items();
            $has_taxable_items = false;
            foreach ($items_to_check as $item) {
                $product = $item->get_product();
                if (!$product || $product->get_tax_status() !== 'none') {
                    $has_taxable_items = true;
                    break;
                }
            }
            if (!$has_taxable_items) {
                return [
                    'success' => false,
                    'error' => __('Todos los productos de esta orden tienen estado de impuesto "ninguno". No se genera documento electrónico.', 'fe-woo'),
                    'skipped' => true,
                ];
            }

            // Get emisor data
            $emisor_data = null;
            $is_parent_emisor = false; // Safe default: require explicit confirmation of parent status
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    throw new Exception("Emisor #{$emisor_id} not found");
                }
                $emisor_data = self::prepare_emisor_data($emisor);
                $is_parent_emisor = !empty($emisor->is_parent);
            } else {
                // No emisor provided — use parent emisor and confirm it IS parent
                $is_parent_emisor = (FE_Woo_Emisor_Manager::get_parent_emisor() !== null);
            }

            // Exonerations only apply to the default (parent) emisor
            $apply_exoneracion = $is_parent_emisor;

            // Generate unique clave (invoice key)
            $clave = self::generate_clave($order, $document_type, $emisor_data);

            // For partial line items, include shipping only if flagged
            $effective_include_shipping = ($line_items !== null) ? $include_shipping : true;

            // Build XML structure
            $xml = self::build_xml($order, $clave, $document_type, null, $line_items, $emisor_data, $effective_include_shipping, $apply_exoneracion);

            return [
                'success' => true,
                'clave' => $clave,
                'xml' => $xml,
                'document_type' => $document_type,
                'emisor_id' => $emisor_id,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Reconstruir el XML para una clave existente (sin consumir nuevo consecutivo).
     *
     * Usado por el retry del queue cuando una clave previamente generada quedó
     * "pending" (POST a Hacienda falló o estuvo incierto) y necesitamos volver
     * a firmar y enviar SIN regenerar el consecutivo. La clave se preserva tal
     * cual; el cuerpo del XML se reconstruye con los datos actuales de la orden
     * (que pueden haber sido corregidos por el operador entre tick y tick).
     *
     * Diferencias vs generate_from_order:
     *   - NO llama generate_clave (no consume consecutivo, no registra emisión).
     *   - NO valida la lista de items vacía (asume el caller ya validó).
     *
     * @param WC_Order $order
     * @param string   $document_type
     * @param int|null $emisor_id
     * @param array|null $line_items
     * @param string   $existing_clave
     * @param bool     $include_shipping
     * @return array Mismo shape que generate_from_order: ['success', 'clave', 'xml', ...]
     */
    public static function rebuild_xml_for_clave($order, $document_type, $emisor_id, $line_items, $existing_clave, $include_shipping = false) {
        try {
            $emisor_data = null;
            $is_parent_emisor = false;
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    throw new Exception("Emisor #{$emisor_id} not found");
                }
                $emisor_data = self::prepare_emisor_data($emisor);
                $is_parent_emisor = !empty($emisor->is_parent);
            } else {
                $is_parent_emisor = (FE_Woo_Emisor_Manager::get_parent_emisor() !== null);
            }

            $apply_exoneracion = $is_parent_emisor;
            $effective_include_shipping = ($line_items !== null) ? $include_shipping : true;

            $xml = self::build_xml(
                $order,
                $existing_clave,
                $document_type,
                null,
                $line_items,
                $emisor_data,
                $effective_include_shipping,
                $apply_exoneracion
            );

            return [
                'success' => true,
                'clave' => $existing_clave,
                'xml' => $xml,
                'document_type' => $document_type,
                'emisor_id' => $emisor_id,
                'rebuilt' => true,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate nota de crédito or nota de débito XML from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $note_type Note type: 'nota_credito' or 'nota_debito'
     * @param array    $reference_data Reference document data
     *                 - referenced_clave: Clave of the document being referenced
     *                 - referenced_date: Date of referenced document
     *                 - referenced_type: Type of referenced document (01-05, 99)
     *                 - reference_code: Reason code (01-Anula, 02-Corrige, etc.)
     *                 - reference_reason: Text description of reason
     * @param array    $line_items Optional: Array of line items for partial notes
     * @param int|null $emisor_id Optional: Emisor ID for multi-emisor support
     * @return array Array with 'success', 'clave', 'xml' keys
     */
    public static function generate_nota_from_order($order, $note_type = 'nota_credito', $reference_data = [], $emisor_id = null, $line_items = null) {
        try {
            // Validate reference data
            if (empty($reference_data['referenced_clave'])) {
                throw new Exception('Referenced document clave is required');
            }
            if (empty($reference_data['referenced_date'])) {
                throw new Exception('Referenced document date is required');
            }
            if (empty($reference_data['referenced_type'])) {
                throw new Exception('Referenced document type is required');
            }
            if (empty($reference_data['reference_code'])) {
                throw new Exception('Reference code is required');
            }
            if (empty($reference_data['reference_reason'])) {
                throw new Exception('Reference reason is required');
            }

            // Get emisor data if emisor_id provided
            $emisor_data = null;
            $is_parent_emisor = false; // Safe default: require explicit confirmation of parent status
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    throw new Exception("Emisor #{$emisor_id} not found");
                }
                $emisor_data = self::prepare_emisor_data($emisor);
                $is_parent_emisor = !empty($emisor->is_parent);
            } else {
                // No emisor provided — use parent emisor and confirm it IS parent
                $is_parent_emisor = (FE_Woo_Emisor_Manager::get_parent_emisor() !== null);
            }

            // Exonerations only apply to the default (parent) emisor
            $apply_exoneracion = $is_parent_emisor;

            // Generate unique clave for the note
            $clave = self::generate_clave($order, $note_type, $emisor_data);

            // Build XML structure with emisor data
            $xml = self::build_xml($order, $clave, $note_type, $reference_data, $line_items, $emisor_data, true, $apply_exoneracion);

            return [
                'success' => true,
                'clave' => $clave,
                'xml' => $xml,
                'document_type' => $note_type,
                'reference_data' => $reference_data,
                'emisor_id' => $emisor_id,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Single source of truth para la fecha de emisión del comprobante.
     *
     * Tanto los componentes DDMMYY de la <Clave> como el campo <FechaEmision>
     * del XML deben derivarse de este DateTime para garantizar que la
     * codificación de fecha sea byte-equivalente entre ambos. Si divergen,
     * Hacienda rechaza con código -405 ("La fecha de la clave numérica no
     * concuerda con el campo Fecha Emisión del comprobante").
     *
     * Default: `$order->get_date_created()` en CR tz. Trade-off:
     *   - Acepta la observación -53 ("hora no coincide con hora oficial")
     *     cuando el cron worker procesa con lag > pocos minutos. -53 NO
     *     bloquea aceptación.
     *   - Evita -405 (rechazo terminal) cuando el cron cruza medianoche CR.
     *
     * Callers (e.g. manual batches del modelo hold-back del tema) pueden
     * sobreescribir el default vía el filter `fe_woo_emission_datetime`,
     * usando `$ctx['source']` para discriminar contextos. El default
     * (cron / generación inmediata sin source override) NO cambia.
     *
     * IMPORTANTE para callbacks del filter:
     *   - Retornar el DateTime EXACTO que será usado tanto por la Clave como
     *     por FechaEmision. Cualquier divergencia entre el día/mes/año del
     *     DateTime retornado y la representación serializada genera -405.
     *   - Mantener timezone America/Costa_Rica (o equivalente con offset
     *     -06:00). `setTimezone()` antes de retornar.
     *
     * @param WC_Order $order
     * @return DateTime En timezone America/Costa_Rica.
     */
    private static function get_emission_datetime($order) {
        $dt = clone $order->get_date_created();
        $dt->setTimezone(new DateTimeZone('America/Costa_Rica'));

        /**
         * Override FechaEmision based on processing context.
         *
         * Default (no callback): order creation date in CR tz. Manual
         * batches del tema (modelo hold-back) pueden retornar `now()` para
         * evitar la observación -53 cuando una orden se procesa días después
         * de su creación.
         *
         * @param DateTime $dt    Default emission datetime (order creation, CR tz).
         * @param WC_Order $order The order being emitted.
         * @param array    $ctx   {
         *     @type string|null $source Current emission source label.
         *                                'cron' for cron batches,
         *                                'manual' default for direct callers,
         *                                or any string set via
         *                                `FE_Woo_Factura_Generator::set_emission_source()`.
         * }
         */
        $dt = apply_filters('fe_woo_emission_datetime', $dt, $order, [
            'source' => self::$emission_source_override,
        ]);

        // Defensive: a buggy callback could return a non-DateTime or a string.
        // Reverting to the default keeps Clave / FechaEmision aligned and
        // avoids -405. The bug surfaces in the next valid emission anyway.
        if (!($dt instanceof DateTime) && !($dt instanceof DateTimeImmutable)) {
            $dt = clone $order->get_date_created();
            $dt->setTimezone(new DateTimeZone('America/Costa_Rica'));
            return $dt;
        }

        // Normalize DateTimeImmutable → DateTime so callers (which mutate
        // via setTimezone in some paths) stay safe. Also re-pin to CR tz in
        // case the callback drifted.
        if ($dt instanceof DateTimeImmutable) {
            $dt = DateTime::createFromImmutable($dt);
        }
        $dt->setTimezone(new DateTimeZone('America/Costa_Rica'));
        return $dt;
    }

    /**
     * Generate unique clave (invoice key)
     *
     * Format: [Country][Day][Month][Year][ID][Consecutive][Situation][Security Code]
     * Total: 50 characters
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $document_type Document type: 'factura' or 'tiquete'
     * @param array    $emisor_data Optional emisor data with 'cedula' key for multi-emisor
     * @return string 50-character clave
     */
    private static function generate_clave($order, $document_type = 'tiquete', $emisor_data = null) {
        $country = '506'; // Costa Rica (3 digits)

        // Day/month/year in the clave MUST match FechaEmision in CR local time.
        // Ambos campos derivan de get_emission_datetime() para evitar -405.
        $date = self::get_emission_datetime($order);
        $day = $date->format('d'); // 2 digits
        $month = $date->format('m'); // 2 digits
        $year = $date->format('y'); // 2 digits

        // Use emisor-specific cedula if provided, otherwise fall back to global config
        $cedula = (!empty($emisor_data['cedula_juridica']))
            ? $emisor_data['cedula_juridica']
            : FE_Woo_Hacienda_Config::get_cedula_juridica();
        $id = str_pad($cedula, 12, '0', STR_PAD_LEFT); // 12 digits

        // The 20-digit consecutive block inside the clave MUST match the
        // <NumeroConsecutivo> element byte-for-byte (Resolución DGT-R-48-2016
        // art. 4). build_xml() lee el consecutivo de vuelta del clave para
        // no consumir 2 valores del contador en una sola emisión.
        $consecutive = self::generate_consecutive($order, $document_type, $emisor_data);

        $situation = '1'; // 1 = Normal, 2 = Contingencia, 3 = Sin internet

        // Security code - random 8 digits
        $security_code = str_pad(wp_rand(1, 99999999), 8, '0', STR_PAD_LEFT); // 8 digits

        $clave = $country . $day . $month . $year . $id . $consecutive . $situation . $security_code;

        // Registrar la emisión en el log (orphan tracking).
        if (self::$last_emitted_numero !== null && class_exists('FE_Woo_Emission_Log')) {
            FE_Woo_Emission_Log::record_emission(
                $order->get_id(),
                $clave,
                self::$last_emitted_cedula,
                self::$last_emitted_sucursal,
                self::$last_emitted_terminal,
                self::$last_emitted_doctype,
                self::$last_emitted_numero
            );
            // Limpiar el stash para que un eventual reuso del generador no
            // re-loggee con valores stale.
            self::$last_emitted_numero = null;
            self::$last_emitted_cedula = null;
            self::$last_emitted_sucursal = null;
            self::$last_emitted_terminal = null;
            self::$last_emitted_doctype = null;
        }

        return $clave;
    }

    /**
     * Build XML structure for factura, tiquete, or note
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $clave Invoice key
     * @param string   $document_type Document type: 'factura', 'tiquete', 'nota_credito', 'nota_debito'
     * @param array    $reference_data Reference data for notes (optional)
     * @param array    $line_items Line items for partial notes (optional)
     * @return string XML string
     */
    private static function build_xml($order, $clave, $document_type = 'tiquete', $reference_data = [], $line_items = null, $emisor_data = null, $include_shipping = true, $apply_exoneracion = true) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Root element - different for each document type
        if ($document_type === 'factura') {
            $root = $xml->createElement('FacturaElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica');
        } elseif ($document_type === 'nota_credito') {
            $root = $xml->createElement('NotaCreditoElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaCreditoElectronica');
        } elseif ($document_type === 'nota_debito') {
            $root = $xml->createElement('NotaDebitoElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaDebitoElectronica');
        } else {
            $root = $xml->createElement('TiqueteElectronico');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico');
        }
        $root->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->appendChild($root);

        // Clave
        $root->appendChild(self::text_element($xml, 'Clave', $clave));

        // ProveedorSistemas — v4.4 requires it for every document type.
        $root->appendChild(self::text_element($xml, 'ProveedorSistemas', FE_Woo_Hacienda_Config::get_cedula_juridica()));

        // CodigoActividadEmisor — v4.4 renames the legacy CodigoActividad for all doc types.
        // XSD requires exactly 6 numeric digits (strips decimals, pads zeros).
        $activity_code_raw = (!empty($emisor_data['actividad_economica']))
            ? $emisor_data['actividad_economica']
            : FE_Woo_Hacienda_Config::get_economic_activity();
        $root->appendChild(self::text_element($xml, 'CodigoActividadEmisor', self::normalize_activity_code($activity_code_raw)));

        // CodigoActividadReceptor — only for documents that carry a Receptor.
        $receptor_activity_code = $order->get_meta('_fe_woo_activity_code');
        if (!empty($receptor_activity_code) && $document_type !== 'tiquete') {
            $root->appendChild(self::text_element($xml, 'CodigoActividadReceptor', self::normalize_activity_code($receptor_activity_code)));
        }

        // NumeroConsecutivo — debe coincidir byte-a-byte con la clave
        // (DGT-R-48-2016 art. 4). Lo extraemos del clave en lugar de
        // pedir un nuevo valor al contador (eso burnaría 2 consecutivos
        // por documento).
        $consecutive = self::extract_consecutivo_from_clave($clave);
        $root->appendChild(self::text_element($xml, 'NumeroConsecutivo', $consecutive));

        // FechaEmision DEBE coincidir (DDMMYY) con la fecha codificada en
        // la <Clave>. Tanto la clave como FechaEmision se derivan del helper
        // get_emission_datetime() (que usa $order->get_date_created()).
        //
        // Antes: este campo usaba `now()` en CR para evitar la observación
        // -53 ("La hora indicada no coincide con la hora oficial") que el
        // cron lag de Pantheon (~1h) podía disparar. Pero `now()` divergía
        // de la fecha de la clave cuando el cron cruzaba medianoche CR
        // (orden creada 23:55 día N → procesada 01:30 día N+1), causando
        // rechazos -405 ("La fecha de la clave numérica no concuerda con el
        // campo Fecha Emisión del comprobante") — rechazo terminal, falla
        // toda la cola de la orden. -53 es no-bloqueante (sale en aceptado).
        // -405 era bloqueante. Ver get_emission_datetime() para detalles.
        $fecha_emision = self::get_emission_datetime($order);
        $root->appendChild(self::text_element($xml, 'FechaEmision', $fecha_emision->format('c')));

        // Emisor (Sender - Company)
        $emisor = self::build_emisor($xml, $emisor_data);
        $root->appendChild($emisor);

        // Receptor — not applicable for Tiquete.
        if ($document_type !== 'tiquete') {
            $receptor = self::build_receptor($xml, $order);
            $root->appendChild($receptor);
        }

        // CondicionVenta
        $root->appendChild(self::text_element($xml, 'CondicionVenta', self::get_sales_condition($order)));

        // DetalleServicio (Line Items)
        $detalle = self::build_detalle_servicio($xml, $order, $line_items, $include_shipping, $apply_exoneracion, $document_type);
        $root->appendChild($detalle);

        // ResumenFactura — v4.4 requires MedioPago and CodigoTipoMoneda inside ResumenFactura.
        $resumen = self::build_resumen($xml, $order, $line_items, $include_shipping, $apply_exoneracion);
        $root->appendChild($resumen);

        // InformacionReferencia (only for notes)
        if ($document_type === 'nota_credito' || $document_type === 'nota_debito') {
            $info_referencia = self::build_informacion_referencia($xml, $reference_data);
            $root->appendChild($info_referencia);
        }

        return $xml->saveXML();
    }

    /**
     * Generate consecutive number
     *
     * Toma un valor secuencial atómico del contador `wp_fe_woo_consecutivos`
     * por (cedula_emisor, sucursal, terminal, document_type). Cada llamada
     * consume un consecutivo en Hacienda — no es reentrante. Por eso
     * `build_xml` debe leer el consecutivo del clave (substr 21..40) en lugar
     * de llamar este método de nuevo.
     *
     * @param WC_Order   $order         WooCommerce order (solo para fallback de cédula y logging).
     * @param string     $document_type Document type: 'factura', 'tiquete', 'nota_credito', 'nota_debito'.
     * @param array|null $emisor_data   Emisor ['cedula_juridica' => ...]. Si null, usa la config global.
     * @return string Consecutive number (20 dígitos).
     * @throws Exception Si el contador falla.
     */
    private static function generate_consecutive($order, $document_type = 'tiquete', $emisor_data = null) {
        // Format: SSSTTTTTDDNNNNNNNNNN (exactly 20 digits per XSD v4.4 NumeroConsecutivoType \d{20,20})
        // SSS     = Sucursal (3 digits)
        // TTTTT   = Terminal/Punto de venta (5 digits) — v4.4 amplió este campo de 3 a 5.
        // DD      = Document Type (2 digits): 01=Factura, 02=Nota Débito, 03=Nota Crédito, 04=Tiquete
        // NNNNNNNNNN = Consecutivo (10 digits)

        $sucursal = '088';
        $terminal = '00001';

        $doc_type_map = [
            'factura' => self::DOC_TYPE_FACTURA_ELECTRONICA,
            'nota_debito' => self::DOC_TYPE_NOTA_DEBITO,
            'nota_credito' => self::DOC_TYPE_NOTA_CREDITO,
            'tiquete' => self::DOC_TYPE_TIQUETE_ELECTRONICO,
        ];
        $doc_type_code = isset($doc_type_map[$document_type]) ? $doc_type_map[$document_type] : self::DOC_TYPE_TIQUETE_ELECTRONICO;

        // El consecutivo es por emisor: cada cédula tiene su propia secuencia.
        // Paddeamos a 12 dígitos igual que en `generate_clave` para que el
        // counter use la misma forma canónica que la clave (y el backfill,
        // que extrae la cédula de claves históricas, encuentre la misma
        // fila).
        $cedula_raw = (!empty($emisor_data['cedula_juridica']))
            ? $emisor_data['cedula_juridica']
            : FE_Woo_Hacienda_Config::get_cedula_juridica();
        $cedula_digits = preg_replace('/\D/', '', (string) $cedula_raw);
        if ($cedula_digits === '') {
            throw new Exception('No hay cédula del emisor configurada — no se puede generar consecutivo.');
        }
        $cedula = str_pad($cedula_digits, 12, '0', STR_PAD_LEFT);

        if (!class_exists('FE_Woo_Consecutivo_Counter')) {
            require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-consecutivo-counter.php';
        }

        $numero = FE_Woo_Consecutivo_Counter::next_value($cedula, $sucursal, $terminal, $doc_type_code);
        $consecutive = str_pad((string) $numero, 10, '0', STR_PAD_LEFT);

        $result = $sucursal . $terminal . $doc_type_code . $consecutive;

        // v4.4: NumeroConsecutivo must be exactly 20 numeric digits.
        if (!preg_match('/^\d{20}$/', $result)) {
            throw new Exception(sprintf(
                'NumeroConsecutivo inválido: "%s" (debe ser exactamente 20 dígitos numéricos)',
                $result
            ));
        }

        // Stash el consecutivo numérico para que `generate_clave` registre la
        // emisión en el log una vez tenga la clave completa. Lo guardamos en
        // estática privada porque generate_clave llama a generate_consecutive
        // y luego inmediatamente arma la clave — no hay request paralela en
        // el medio.
        self::$last_emitted_numero = (int) $numero;
        self::$last_emitted_cedula = $cedula;
        self::$last_emitted_sucursal = $sucursal;
        self::$last_emitted_terminal = $terminal;
        self::$last_emitted_doctype = $doc_type_code;

        return $result;
    }

    /**
     * Stash de la última emisión, leído por `generate_clave` para el log.
     * No es para uso público — es plumbing entre generate_consecutive y
     * generate_clave dentro del mismo flujo síncrono.
     */
    private static $last_emitted_numero = null;
    private static $last_emitted_cedula = null;
    private static $last_emitted_sucursal = null;
    private static $last_emitted_terminal = null;
    private static $last_emitted_doctype = null;

    /**
     * Extraer el NumeroConsecutivo (20 dígitos) de una clave de 50 caracteres.
     * El consecutivo vive en las posiciones 21..40 (después de país+fecha+cedula).
     *
     * Esto es crítico para no doble-consumir el contador: `generate_clave`
     * llama `generate_consecutive` (consume 1) y luego `build_xml` debe
     * extraer el mismo valor del clave en lugar de pedir otro.
     *
     * @param string $clave Clave de 50 caracteres.
     * @return string Consecutivo de 20 dígitos.
     */
    private static function extract_consecutivo_from_clave($clave) {
        if (!is_string($clave) || strlen($clave) !== 50) {
            throw new Exception(sprintf(
                'Clave inválida (esperado 50 chars, recibido %d): "%s"',
                is_string($clave) ? strlen($clave) : 0,
                substr((string) $clave, 0, 20)
            ));
        }
        $consecutivo = substr($clave, 21, 20);
        if (!preg_match('/^\d{20}$/', $consecutivo)) {
            throw new Exception(sprintf('Consecutivo extraído no numérico: "%s"', $consecutivo));
        }
        return $consecutivo;
    }

    /**
     * Build Emisor (sender/company) element
     *
     * @param DOMDocument $xml XML document
     * @param array       $emisor_data Optional emisor data (if null, uses config)
     * @return DOMElement Emisor element
     */
    private static function build_emisor($xml, $emisor_data = null) {
        $emisor = $xml->createElement('Emisor');

        // Normalize data source: if no emisor_data, build from config
        if (!$emisor_data) {
            $location = FE_Woo_Hacienda_Config::get_location_codes();
            $emisor_data = [
                'nombre_legal' => FE_Woo_Hacienda_Config::get_company_name(),
                'cedula_juridica' => FE_Woo_Hacienda_Config::get_cedula_juridica(),
                'tipo_identificacion' => '02',
                'codigo_provincia' => $location['province'],
                'codigo_canton' => $location['canton'],
                'codigo_distrito' => $location['district'],
                'codigo_barrio' => $location['neighborhood'],
                'direccion' => FE_Woo_Hacienda_Config::get_address(),
                'telefono' => FE_Woo_Hacienda_Config::get_phone(),
                'email' => FE_Woo_Hacienda_Config::get_email(),
            ];
        }

        // Nombre
        $emisor->appendChild(self::text_element($xml, 'Nombre', $emisor_data['nombre_legal']));

        // Identificacion
        $identificacion = $xml->createElement('Identificacion');
        $identificacion->appendChild(self::text_element($xml, 'Tipo', $emisor_data['tipo_identificacion'] ?? '02'));
        $identificacion->appendChild(self::text_element($xml, 'Numero', $emisor_data['cedula_juridica']));
        $emisor->appendChild($identificacion);

        // NombreComercial — XSD v4.4 lo declara minOccurs=0, maxLength=80.
        // Posición en EmisorType: Nombre → Identificacion → [Registrofiscal8707]
        // → [NombreComercial] → Ubicacion. Va después de Identificacion. Defensa
        // !empty() para emisores legacy con NULL antes de que el admin edite.
        if (!empty($emisor_data['nombre_comercial'])) {
            $nombre_comercial = function_exists('mb_substr')
                ? mb_substr($emisor_data['nombre_comercial'], 0, 80)
                : substr($emisor_data['nombre_comercial'], 0, 80);
            $emisor->appendChild(self::text_element($xml, 'NombreComercial', $nombre_comercial));
        }

        // Ubicacion — v4.4 XSD requires fixed widths for each location code.
        $ubicacion = $xml->createElement('Ubicacion');
        $ubicacion->appendChild(self::text_element($xml, 'Provincia', self::zero_pad($emisor_data['codigo_provincia'], 1)));
        $ubicacion->appendChild(self::text_element($xml, 'Canton',    self::zero_pad($emisor_data['codigo_canton'], 2)));
        $ubicacion->appendChild(self::text_element($xml, 'Distrito',  self::zero_pad($emisor_data['codigo_distrito'], 2)));
        if (!empty($emisor_data['codigo_barrio'])) {
            $ubicacion->appendChild(self::text_element($xml, 'Barrio', self::zero_pad($emisor_data['codigo_barrio'], 5)));
        }

        // OtrasSenas — XSD v4.4 minLength=5 / maxLength=250. Defense-in-depth:
        // pre-flight (validate_emisor_fields) ya cubre callers a través de
        // queue-processor, pero algunos paths (tests, llamadas directas a
        // generate_from_order sin queue) podrían saltarse el pre-flight.
        // Throw acá produce un error legible en lugar de un "XML inválido vs
        // XSD" críptico.
        $direccion = trim((string) ($emisor_data['direccion'] ?? ''));
        $direccion_len = function_exists('mb_strlen') ? mb_strlen($direccion) : strlen($direccion);
        if ($direccion_len < 5) {
            throw new Exception(sprintf(
                __('Emisor: la dirección "%s" tiene %d caracteres. Hacienda exige mínimo 5 (XSD v4.4 OtrasSenas). Edítala en FE → Emisores.', 'fe-woo'),
                $direccion,
                $direccion_len
            ));
        }
        if ($direccion_len > 250) {
            $direccion = function_exists('mb_substr') ? mb_substr($direccion, 0, 250) : substr($direccion, 0, 250);
        }
        $ubicacion->appendChild(self::text_element($xml, 'OtrasSenas', $direccion));
        $emisor->appendChild($ubicacion);

        // Telefono
        if (!empty($emisor_data['telefono'])) {
            $telefono = $xml->createElement('Telefono');
            $telefono->appendChild(self::text_element($xml, 'CodigoPais', '506'));
            $telefono->appendChild(self::text_element($xml, 'NumTelefono', $emisor_data['telefono']));
            $emisor->appendChild($telefono);
        }

        // CorreoElectronico
        if (!empty($emisor_data['email'])) {
            $emisor->appendChild(self::text_element($xml, 'CorreoElectronico', $emisor_data['email']));
        }

        return $emisor;
    }

    /**
     * Build Receptor (receiver/customer) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @return DOMElement Receptor element
     */
    private static function build_receptor($xml, $order) {
        $receptor = $xml->createElement('Receptor');

        // Get customer factura data from order meta
        $full_name = trim((string) $order->get_meta('_fe_woo_full_name'));
        $id_type = $order->get_meta('_fe_woo_id_type');
        $id_number = $order->get_meta('_fe_woo_id_number');
        $email = $order->get_meta('_fe_woo_invoice_email');
        $phone = $order->get_meta('_fe_woo_phone');

        // Legacy fallback: versiones previas del plugin guardaban el nombre
        // en _fe_woo_name. Lo respetamos si _fe_woo_full_name está vacío.
        if ($full_name === '') {
            $full_name = trim((string) $order->get_meta('_fe_woo_name'));
        }

        // Fallback: reconstruct Nombre from billing data if meta is empty
        // (e.g. factura toggled from admin without filling "Nombre Completo o Razón Social").
        if ($full_name === '') {
            $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $billing_company = trim((string) $order->get_billing_company());
            if ($billing_company !== '' && $billing_name !== '') {
                $full_name = $billing_company . ' (' . $billing_name . ')';
            } elseif ($billing_company !== '') {
                $full_name = $billing_company;
            } else {
                $full_name = $billing_name;
            }
        }

        if ($full_name === '') {
            throw new Exception(__('El Nombre del Receptor está vacío. Complete "Nombre Completo o Razón Social" en la sección Factura Electrónica del pedido o los datos de facturación del cliente (nombre/apellido o empresa).', 'fe-woo'));
        }

        // XSD v4.4 limita Nombre a 100 caracteres.
        if (function_exists('mb_substr')) {
            $full_name = mb_substr($full_name, 0, 100);
        } else {
            $full_name = substr($full_name, 0, 100);
        }

        // Nombre
        $receptor->appendChild(self::text_element($xml, 'Nombre', $full_name));

        // Identificacion
        $identificacion = $xml->createElement('Identificacion');
        $identificacion->appendChild(self::text_element($xml, 'Tipo', $id_type));
        $identificacion->appendChild(self::text_element($xml, 'Numero', preg_replace('/[^0-9A-Za-z]/', '', $id_number)));
        $receptor->appendChild($identificacion);

        // Ubicacion (optional under v4.4) — emit when provincia/canton/distrito
        // are present and validate against the CR Locations catalog. OtrasSenas
        // siempre incluye el sufijo del sistema (RECEPTOR_OTRAS_SENAS_SUFFIX),
        // así que el cliente puede dejar vacío sin romper minLength=5 del XSD.
        // Truncamos a 250 si el concatenado lo supera.
        $r_provincia   = (string) $order->get_meta('_fe_woo_provincia');
        $r_canton      = (string) $order->get_meta('_fe_woo_canton');
        $r_distrito    = (string) $order->get_meta('_fe_woo_distrito');
        $r_otras_senas = (string) $order->get_meta('_fe_woo_otras_senas');
        $r_barrio      = trim((string) $order->get_meta('_fe_woo_barrio'));

        $r_otras_senas_trim = trim($r_otras_senas);
        if (
            $r_provincia !== ''
            && $r_canton !== ''
            && $r_distrito !== ''
            && class_exists('FE_Woo_CR_Locations')
            && FE_Woo_CR_Locations::validate($r_provincia, $r_canton, $r_distrito)
        ) {
            $ubicacion = $xml->createElement('Ubicacion');
            $ubicacion->appendChild(self::text_element($xml, 'Provincia', self::zero_pad($r_provincia, 1)));
            $ubicacion->appendChild(self::text_element($xml, 'Canton',    self::zero_pad($r_canton,    2)));
            $ubicacion->appendChild(self::text_element($xml, 'Distrito',  self::zero_pad($r_distrito,  2)));

            // Barrio fallback "Desconocido" — fix H-2 v1.15.0.
            $barrio_value = $r_barrio !== '' ? $r_barrio : 'Desconocido';
            $ubicacion->appendChild(self::text_element($xml, 'Barrio', $barrio_value));

            $otras_final = self::build_otras_senas_effective($r_otras_senas);
            $ubicacion->appendChild(self::text_element($xml, 'OtrasSenas', $otras_final));

            $receptor->appendChild($ubicacion);
        }

        // Telefono — v4.4 exige Telefono antes de CorreoElectronico dentro de ReceptorType.
        if (!empty($phone)) {
            $telefono = $xml->createElement('Telefono');
            $telefono->appendChild(self::text_element($xml, 'CodigoPais', '506'));
            $telefono->appendChild(self::text_element($xml, 'NumTelefono', $phone));
            $receptor->appendChild($telefono);
        }

        // CorreoElectronico
        if (!empty($email)) {
            $receptor->appendChild(self::text_element($xml, 'CorreoElectronico', $email));
        }

        return $receptor;
    }

    /**
     * Build DetalleServicio (line items) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param array       $line_items Optional line items for partial notes
     * @return DOMElement DetalleServicio element
     */
    private static function build_detalle_servicio($xml, $order, $line_items = null, $include_shipping = true, $apply_exoneracion = true, $document_type = 'tiquete') {
        $detalle_servicio = $xml->createElement('DetalleServicio');

        // TipoTransaccion solo aplica a Factura/NotaCredito/NotaDebito (XSD v4.4).
        // El XSD de TiqueteElectronico NO incluye TipoTransaccion en LineaDetalle.
        $emit_tipo_transaccion = ($document_type !== 'tiquete');

        $line_number = 1;

        // Use custom line items if provided (for partial notes), otherwise use all order items
        $items_to_process = $line_items !== null ? $line_items : $order->get_items();

        // Add order items
        foreach ($items_to_process as $item) {
            // Skip products with tax_status 'none' - they should not appear in electronic invoices
            $product = $item->get_product();
            if ($product && $product->get_tax_status() === 'none') {
                continue;
            }

            $linea_detalle = $xml->createElement('LineaDetalle');

            // NumeroLinea
            $linea_detalle->appendChild(self::text_element($xml, 'NumeroLinea', $line_number));

            // CodigoCABYS — v4.4 lo eleva a hijo directo obligatorio (13 dígitos exactos).
            // El CABYS se lee del producto (meta _fe_woo_cabys_code) — cada producto
            // declara su propia clasificación, independiente del WC tax class.
            $cabys_code = $product ? FE_Woo_Product_CABYS::get_product_cabys_code($product) : '';
            if (empty($cabys_code)) {
                throw new Exception(sprintf(
                    __('El producto "%s" (ID %d) no tiene código CABYS asignado. El XSD v4.4 exige CABYS en cada línea de detalle.', 'fe-woo'),
                    $product ? $product->get_name() : $item->get_name(),
                    $product ? $product->get_id() : $item->get_product_id()
                ));
            }
            $linea_detalle->appendChild(self::text_element($xml, 'CodigoCABYS', $cabys_code));

            // CodigoComercial — Tipo 04 = uso interno. Siempre se emite:
            // si el producto no tiene SKU, generamos slug del nombre (sanitize_title).
            $sku = $product ? $product->get_sku() : '';
            if (empty($sku)) {
                $name = $product ? $product->get_name() : $item->get_name();
                $sku  = sanitize_title($name);
                if (empty($sku)) {
                    $sku = 'item-' . ($product ? $product->get_id() : $line_number);
                }
            }
            // XSD v4.4 limita CodigoType a maxLength=20.
            $sku_truncated = function_exists('mb_substr') ? mb_substr($sku, 0, 20) : substr($sku, 0, 20);
            $codigo_comercial = $xml->createElement('CodigoComercial');
            $codigo_comercial->appendChild(self::text_element($xml, 'Tipo', '04'));
            $codigo_comercial->appendChild(self::text_element($xml, 'Codigo', $sku_truncated));
            $linea_detalle->appendChild($codigo_comercial);

            // Cantidad — 3 decimales (XSD fractionDigits=3).
            $linea_detalle->appendChild(self::text_element($xml, 
                'Cantidad',
                number_format((float) $item->get_quantity(), 3, '.', '')
            ));

            // UnidadMedida
            $linea_detalle->appendChild(self::text_element($xml, 'UnidadMedida', 'Unid')); // Unit

            // TipoTransaccion — per-product meta con default '01' (Venta Normal).
            // XSD position: UnidadMedida → TipoTransaccion → UnidadMedidaComercial → Detalle.
            // Solo aplica a Factura/Nota — el TiqueteElectronico XSD no la define.
            if ($emit_tipo_transaccion) {
                $linea_detalle->appendChild(self::text_element($xml, 
                    'TipoTransaccion',
                    FE_Woo_Product_Tipo_Transaccion::get_for_product($product)
                ));
            }

            // UnidadMedidaComercial — fix H-1 v1.15.0. La referencia productiva
            // (Coonatramar) la emite siempre. Para virtuales: 'Unid' fijo.
            // Para físicos: dropdown en pestaña Shipping del producto.
            $linea_detalle->appendChild(self::text_element($xml, 
                'UnidadMedidaComercial',
                FE_Woo_Product_Unidad_Medida::get_for_product($product)
            ));

            // Detalle (Product name)
            $linea_detalle->appendChild(self::text_element($xml, 'Detalle', $item->get_name()));

            // PrecioUnitario
            $unit_price = $item->get_subtotal() / $item->get_quantity();
            $linea_detalle->appendChild(self::text_element($xml, 'PrecioUnitario', number_format($unit_price, 5, '.', '')));

            // MontoTotal
            $linea_detalle->appendChild(self::text_element($xml, 'MontoTotal', number_format($item->get_subtotal(), 5, '.', '')));

            // Descuento (if any)
            if ($item->get_subtotal() != $item->get_total()) {
                $discount = $item->get_subtotal() - $item->get_total();
                $descuento = $xml->createElement('Descuento');
                $descuento->appendChild(self::text_element($xml, 'MontoDescuento', number_format($discount, 5, '.', '')));
                $descuento->appendChild(self::text_element($xml, 'NaturalezaDescuento', 'Descuento aplicado'));
                $linea_detalle->appendChild($descuento);
            }

            // SubTotal
            $linea_detalle->appendChild(self::text_element($xml, 'SubTotal', number_format($item->get_total(), 5, '.', '')));

            // Check if order has exemption - this determines the actual tax rate
            // Exonerations only apply to the default (parent) emisor
            $exon_data = null;
            if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
                $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
                if ($exon_data) {
                    $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                    if (!$validation['valid']) {
                        $exon_data = null; // Invalid exemption, treat as normal tax
                    }
                }
            }

            // Calculate tax respecting product tax_status and exemptions
            $subtotal = $item->get_total();
            $tax_info = self::calculate_item_tax_info($item, $product, $subtotal, $item->get_total_tax(), $exon_data);
            $tarifa_real = $tax_info['tarifa'];
            $tax_with_exon = $tax_info['tax'];

            // BaseImponible — monto sobre el que se calcula el impuesto.
            // En este dominio no usamos IVACobradoFabrica, así que BaseImponible = SubTotal.
            $linea_detalle->appendChild(self::text_element($xml, 'BaseImponible', number_format($subtotal, 5, '.', '')));

            // Impuesto — v4.4 lo exige siempre (minOccurs=1). Para items sin impuesto, emitir stub con Monto=0.
            $impuesto = $xml->createElement('Impuesto');
            $impuesto->appendChild(self::text_element($xml, 'Codigo', '01')); // IVA
            $impuesto->appendChild(self::text_element($xml, 'CodigoTarifaIVA', self::resolve_codigo_tarifa_iva($product, $item, $tarifa_real)));
            $impuesto->appendChild(self::text_element($xml, 'Tarifa', number_format($tarifa_real, 5, '.', '')));
            $impuesto->appendChild(self::text_element($xml, 'Monto', number_format($tax_with_exon, 5, '.', '')));
            if ($exon_data && $tarifa_real < 13) {
                $exoneracion_elem = self::build_exoneracion($xml, $order, $subtotal, $tarifa_real);
                if ($exoneracion_elem) {
                    $impuesto->appendChild($exoneracion_elem);
                }
            }
            $linea_detalle->appendChild($impuesto);

            // ImpuestoAsumidoEmisorFabrica — no aplica al dominio (siempre 0).
            $linea_detalle->appendChild(self::text_element($xml, 'ImpuestoAsumidoEmisorFabrica', '0.00000'));

            // ImpuestoNeto — impuesto efectivamente cobrado al cliente.
            $linea_detalle->appendChild(self::text_element($xml, 'ImpuestoNeto', number_format($tax_with_exon, 5, '.', '')));

            // MontoTotalLinea — use recalculated tax if exemption applies
            $line_total = $item->get_total() + $tax_with_exon;
            $linea_detalle->appendChild(self::text_element($xml, 'MontoTotalLinea', number_format($line_total, 5, '.', '')));

            $detalle_servicio->appendChild($linea_detalle);
            $line_number++;
        }

        // Add shipping as a line item if applicable and included
        if ($include_shipping && $order->get_shipping_total() > 0) {
            $linea_detalle = $xml->createElement('LineaDetalle');
            $linea_detalle->appendChild(self::text_element($xml, 'NumeroLinea', $line_number));

            // CodigoCABYS for shipping — configurable via filter; hard-fail if not set.
            $shipping_cabys = apply_filters('fe_woo_shipping_cabys_code', '', $order);
            if (empty($shipping_cabys)) {
                throw new Exception(__('No hay código CABYS configurado para el envío. Configure el filtro fe_woo_shipping_cabys_code con un código CABYS válido de 13 dígitos para servicios de transporte.', 'fe-woo'));
            }
            $linea_detalle->appendChild(self::text_element($xml, 'CodigoCABYS', $shipping_cabys));

            // CodigoComercial opcional para identificar esta línea como envío.
            $codigo_comercial = $xml->createElement('CodigoComercial');
            $codigo_comercial->appendChild(self::text_element($xml, 'Tipo', '04'));
            $codigo_comercial->appendChild(self::text_element($xml, 'Codigo', 'SHIPPING'));
            $linea_detalle->appendChild($codigo_comercial);

            $linea_detalle->appendChild(self::text_element($xml, 'Cantidad', '1.000'));
            $linea_detalle->appendChild(self::text_element($xml, 'UnidadMedida', 'Sp')); // Service
            // Shipping siempre Venta Normal — no aplica autoconsumo ni bien de capital.
            // Solo en Factura/Nota; TiqueteElectronico XSD no define TipoTransaccion.
            if ($emit_tipo_transaccion) {
                $linea_detalle->appendChild(self::text_element($xml, 'TipoTransaccion', '01'));
            }
            // UnidadMedidaComercial — fix H-1 v1.15.0. Para línea de envío usamos
            // 'Unid' (servicio sin unidad física asociada).
            $linea_detalle->appendChild(self::text_element($xml, 'UnidadMedidaComercial', 'Unid'));
            $linea_detalle->appendChild(self::text_element($xml, 'Detalle', 'Envío'));
            $linea_detalle->appendChild(self::text_element($xml, 'PrecioUnitario', number_format($order->get_shipping_total(), 5, '.', '')));
            $linea_detalle->appendChild(self::text_element($xml, 'MontoTotal', number_format($order->get_shipping_total(), 5, '.', '')));
            $linea_detalle->appendChild(self::text_element($xml, 'SubTotal', number_format($order->get_shipping_total(), 5, '.', '')));

            // Shipping tax
            $shipping_tax = $order->get_shipping_tax();
            $shipping_subtotal = $order->get_shipping_total();
            $shipping_has_tax = $shipping_tax > 0;

            $shipping_tax_info = self::calculate_item_tax_info(null, false, $shipping_subtotal, $shipping_tax, $shipping_has_tax ? $exon_data : null);
            $tarifa_shipping = $shipping_tax_info['tarifa'];
            $shipping_tax = $shipping_tax_info['tax'];

            // BaseImponible
            $linea_detalle->appendChild(self::text_element($xml, 'BaseImponible', number_format($shipping_subtotal, 5, '.', '')));

            // Impuesto — obligatorio en v4.4; emitir siempre (stub con Monto=0 si no hay impuesto).
            // Shipping no tiene WC_Order_Item con get_taxes() en formato item-line,
            // así que pasamos null y dejamos que resolve_codigo_tarifa_iva caiga al fallback
            // numérico por tarifa_shipping (excepto si llegara a tener tax_class explícito,
            // pero WC shipping methods no exponen ese hook hoy).
            $impuesto = $xml->createElement('Impuesto');
            $impuesto->appendChild(self::text_element($xml, 'Codigo', '01'));
            $impuesto->appendChild(self::text_element($xml, 'CodigoTarifaIVA', self::resolve_codigo_tarifa_iva(null, null, $tarifa_shipping)));
            $impuesto->appendChild(self::text_element($xml, 'Tarifa', number_format($tarifa_shipping, 5, '.', '')));
            $impuesto->appendChild(self::text_element($xml, 'Monto', number_format($shipping_tax, 5, '.', '')));
            if ($exon_data && $tarifa_shipping < 13) {
                $exoneracion_elem = self::build_exoneracion($xml, $order, $shipping_subtotal, $tarifa_shipping);
                if ($exoneracion_elem) {
                    $impuesto->appendChild($exoneracion_elem);
                }
            }
            $linea_detalle->appendChild($impuesto);

            // ImpuestoAsumidoEmisorFabrica — siempre 0 para este dominio.
            $linea_detalle->appendChild(self::text_element($xml, 'ImpuestoAsumidoEmisorFabrica', '0.00000'));

            // ImpuestoNeto
            $linea_detalle->appendChild(self::text_element($xml, 'ImpuestoNeto', number_format($shipping_tax, 5, '.', '')));

            $line_total = $order->get_shipping_total() + $shipping_tax;
            $linea_detalle->appendChild(self::text_element($xml, 'MontoTotalLinea', number_format($line_total, 5, '.', '')));

            $detalle_servicio->appendChild($linea_detalle);
        }

        return $detalle_servicio;
    }

    /**
     * Build ResumenFactura (invoice summary) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param array       $line_items Optional line items for partial notes
     * @return DOMElement ResumenFactura element
     */
    private static function build_resumen($xml, $order, $line_items = null, $include_shipping = true, $apply_exoneracion = true) {
        $resumen = $xml->createElement('ResumenFactura');

        // Check if order has exemption - recalculate totals if needed
        // Exonerations only apply to the default (parent) emisor
        $exon_data = null;
        if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
            $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
            if ($exon_data) {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                if (!$validation['valid']) {
                    $exon_data = null;
                }
            }
        }

        // Acumuladores: separar gravado/exento y bucketizar por CodigoTarifaIVA
        // (1 entrada por tarifa observada — incluye '10' Exento con monto 0
        // cuando hay items exentos, para reflejar fielmente el desglose).
        $subtotal_gravado = 0;
        $subtotal_exento  = 0;
        $total_tax        = 0;
        $total            = 0;
        $discount         = 0;
        $desglose_buckets = []; // [codigoTarifaIVA => sum_monto]

        // Helper: clasificar e incorporar al desglose un par (subtotal, tax_info).
        $accumulate = function ($subtotal_amount, $tax_info, $codigo_tarifa_iva) use (
            &$subtotal_gravado, &$subtotal_exento, &$desglose_buckets, &$total_tax, &$total
        ) {
            if (!empty($tax_info['is_taxable'])) {
                $subtotal_gravado += $subtotal_amount;
            } else {
                $subtotal_exento += $subtotal_amount;
            }

            if (!isset($desglose_buckets[$codigo_tarifa_iva])) {
                $desglose_buckets[$codigo_tarifa_iva] = 0;
            }
            $desglose_buckets[$codigo_tarifa_iva] += (float) $tax_info['tax'];

            $total_tax += (float) $tax_info['tax'];
        };

        // Items (partial line items o todos los de la orden)
        $items_to_process = $line_items !== null ? $line_items : $order->get_items();
        foreach ($items_to_process as $item) {
            $product = $item->get_product();
            if ($product && $product->get_tax_status() === 'none') {
                continue;
            }

            $item_subtotal = $item->get_subtotal();
            $item_total = $item->get_total();
            if ($item_subtotal != $item_total) {
                $discount += $item_subtotal - $item_total;
            }

            $tax_info = self::calculate_item_tax_info($item, $product, $item_total, $item->get_total_tax(), $exon_data);
            $codigo_tarifa = self::resolve_codigo_tarifa_iva($product, $item, $tax_info['tarifa']);

            $accumulate($item_subtotal, $tax_info, $codigo_tarifa);
            $total += $item_total + (float) $tax_info['tax'];
        }

        // Fees — solo en modo full (no partial). WC_Fee no expone tax_class; clasificamos
        // por presencia de tax y derivamos tarifa para mapear al bucket via fallback.
        if ($line_items === null) {
            foreach ($order->get_fees() as $fee) {
                $fee_total = (float) $fee->get_total();
                $fee_tax   = (float) $fee->get_total_tax();
                $is_taxable = $fee_tax > 0;
                $tarifa = 0;
                if ($is_taxable && $fee_total > 0) {
                    $tarifa = self::snap_to_valid_rate(($fee_tax / $fee_total) * 100);
                }
                $tax_info = ['tarifa' => $tarifa, 'tax' => $fee_tax, 'is_taxable' => $is_taxable];
                $codigo_tarifa = self::resolve_codigo_tarifa_iva(null, null, $tarifa);

                $accumulate($fee_total, $tax_info, $codigo_tarifa);
                $total += $fee_total + $fee_tax;
            }
        }

        // Shipping — incluido en partial mode solo si include_shipping=true; siempre en full.
        $process_shipping = ($line_items !== null) ? $include_shipping : true;
        if ($process_shipping && $order->get_shipping_total() > 0) {
            $shipping_subtotal = (float) $order->get_shipping_total();
            $original_shipping_tax = (float) $order->get_shipping_tax();
            $shipping_tax_info = self::calculate_item_tax_info(
                null,
                false,
                $shipping_subtotal,
                $original_shipping_tax,
                $original_shipping_tax > 0 ? $exon_data : null
            );
            $codigo_tarifa = self::resolve_codigo_tarifa_iva(null, null, $shipping_tax_info['tarifa']);

            $accumulate($shipping_subtotal, $shipping_tax_info, $codigo_tarifa);
            $total += $shipping_subtotal + (float) $shipping_tax_info['tax'];
        }

        // Subtotal compuesto — equivalente a la suma de ítems + fees + shipping.
        $subtotal = $subtotal_gravado + $subtotal_exento;

        // CodigoTipoMoneda — v4.4 wraps CodigoMoneda + TipoCambio inside a complex element.
        $currency = $order->get_currency();
        if ($currency === self::CURRENCY_CRC) {
            $tipo_cambio = '1.00000';
        } else {
            // Foreign currency: rate must come from settings or an integration
            // with BCCR. Filter lets the site override per-currency. Emitting
            // 1.0 for USD/EUR causes Hacienda to reject the document.
            $rate = apply_filters(
                'fe_woo_tipo_cambio',
                get_option('fe_woo_tipo_cambio_' . strtolower($currency), ''),
                $currency,
                $order
            );
            if ($rate === '' || (float) $rate <= 0 || (float) $rate === 1.0) {
                throw new Exception(sprintf(
                    'TipoCambio para %s no está configurado. Configura un valor vía el hook `fe_woo_tipo_cambio` o la opción `fe_woo_tipo_cambio_%s`.',
                    $currency,
                    strtolower($currency)
                ));
            }
            $tipo_cambio = number_format((float) $rate, 5, '.', '');
        }
        $codigo_tipo_moneda = $xml->createElement('CodigoTipoMoneda');
        $codigo_tipo_moneda->appendChild(self::text_element($xml, 'CodigoMoneda', $currency));
        $codigo_tipo_moneda->appendChild(self::text_element($xml, 'TipoCambio', $tipo_cambio));
        $resumen->appendChild($codigo_tipo_moneda);

        // TotalServGravados — solo subtotal de items con is_taxable=true.
        $resumen->appendChild(self::text_element($xml, 'TotalServGravados', number_format($subtotal_gravado, 5, '.', '')));

        // TotalServExentos — subtotal de items con is_taxable=false (tax_class de 0%).
        $resumen->appendChild(self::text_element($xml, 'TotalServExentos', number_format($subtotal_exento, 5, '.', '')));

        // TotalServExonerado (v4.4 nuevo) — out of scope: requiere distinguir items
        // con exoneración aplicada de gravados normales. Mantener en 0 hasta que
        // se modele explícitamente.
        $resumen->appendChild(self::text_element($xml, 'TotalServExonerado', '0.00000'));

        // TotalServNoSujeto (v4.4 nuevo)
        $resumen->appendChild(self::text_element($xml, 'TotalServNoSujeto', '0.00000'));

        // TotalMercanciasGravadas — out of scope: hoy todo se trata como servicio.
        $resumen->appendChild(self::text_element($xml, 'TotalMercanciasGravadas', '0.00000'));

        // TotalMercanciasExentas
        $resumen->appendChild(self::text_element($xml, 'TotalMercanciasExentas', '0.00000'));

        // TotalMercExonerada (v4.4 nuevo)
        $resumen->appendChild(self::text_element($xml, 'TotalMercExonerada', '0.00000'));

        // TotalMercNoSujeta (v4.4 nuevo)
        $resumen->appendChild(self::text_element($xml, 'TotalMercNoSujeta', '0.00000'));

        // TotalGravado
        $resumen->appendChild(self::text_element($xml, 'TotalGravado', number_format($subtotal_gravado, 5, '.', '')));

        // TotalExento
        $resumen->appendChild(self::text_element($xml, 'TotalExento', number_format($subtotal_exento, 5, '.', '')));

        // TotalExonerado (v4.4 nuevo)
        $resumen->appendChild(self::text_element($xml, 'TotalExonerado', '0.00000'));

        // TotalNoSujeto (v4.4 nuevo)
        $resumen->appendChild(self::text_element($xml, 'TotalNoSujeto', '0.00000'));

        // TotalVenta — gravado + exento.
        $resumen->appendChild(self::text_element($xml, 'TotalVenta', number_format($subtotal, 5, '.', '')));

        // TotalDescuentos
        $resumen->appendChild(self::text_element($xml, 'TotalDescuentos', number_format($discount, 5, '.', '')));

        // TotalVentaNeta
        $resumen->appendChild(self::text_element($xml, 'TotalVentaNeta', number_format($subtotal - $discount, 5, '.', '')));

        // TotalDesgloseImpuesto — un bloque por cada CodigoTarifaIVA observado
        // en el documento, INCLUYENDO Exento (CodigoTarifaIVA=10) con monto 0
        // cuando hay items exentos. Hacienda regla -487 exige consistencia entre
        // la suma de buckets y TotalImpuesto.
        foreach ($desglose_buckets as $codigo_tarifa_iva => $monto_bucket) {
            $desglose = $xml->createElement('TotalDesgloseImpuesto');
            $desglose->appendChild(self::text_element($xml, 'Codigo', '01')); // 01 = IVA (único impuesto del dominio)
            $desglose->appendChild(self::text_element($xml, 'CodigoTarifaIVA', $codigo_tarifa_iva));
            $desglose->appendChild(self::text_element($xml, 'TotalMontoImpuesto', number_format((float) $monto_bucket, 5, '.', '')));
            $resumen->appendChild($desglose);
        }

        // TotalImpuesto
        $resumen->appendChild(self::text_element($xml, 'TotalImpuesto', number_format($total_tax, 5, '.', '')));

        // TotalImpAsumEmisorFabrica — XSD v4.4 minOccurs=0. Para este dominio
        // (transporte/eventos/B2B) nunca hay productos con IVA asumido por fabricante.
        $resumen->appendChild(self::text_element($xml, 'TotalImpAsumEmisorFabrica', '0.00000'));

        // TotalIVADevuelto — XSD v4.4 minOccurs=0. Solo aplica en categorías
        // reguladas por el Poder Ejecutivo (canasta básica, primas de seguros)
        // con pago directo por tarjeta (Ley 9635). Servicios de transporte y B2B no aplican.
        $resumen->appendChild(self::text_element($xml, 'TotalIVADevuelto', '0.00000'));

        // MedioPago — v4.4 moves this into ResumenFactura as a complex element (1..4 repetitions).
        list($payment_code, $payment_label) = self::get_payment_method($order);
        $medio_pago = $xml->createElement('MedioPago');
        $medio_pago->appendChild(self::text_element($xml, 'TipoMedioPago', $payment_code));
        if ($payment_code === self::PAYMENT_OTHER && !empty($payment_label)) {
            $medio_pago->appendChild(self::text_element($xml, 'MedioPagoOtros', $payment_label));
        }
        $medio_pago->appendChild(self::text_element($xml, 'TotalMedioPago', number_format($total, 5, '.', '')));
        $resumen->appendChild($medio_pago);

        // TotalComprobante
        $resumen->appendChild(self::text_element($xml, 'TotalComprobante', number_format($total, 5, '.', '')));

        // Defensive consistency check — Hacienda rejects XML whose line sums don't match TotalComprobante.
        $expected_total = ($subtotal - $discount) + $total_tax;
        if (abs($expected_total - $total) > 0.01) {
            throw new Exception(sprintf(
                'Totales inconsistentes: suma de líneas=%.5f vs TotalComprobante=%.5f',
                $expected_total,
                $total
            ));
        }

        // Defensive check adicional — la suma de los buckets del desglose debe
        // cuadrar con TotalImpuesto. Si no, regla -487 de Hacienda rechazará.
        $buckets_sum = array_sum($desglose_buckets);
        if (abs($buckets_sum - $total_tax) > 0.01) {
            throw new Exception(sprintf(
                'Inconsistencia en desglose de impuestos: suma de buckets=%.5f vs TotalImpuesto=%.5f',
                $buckets_sum,
                $total_tax
            ));
        }

        return $resumen;
    }

    /**
     * Get sales condition based on payment method
     *
     * @param WC_Order $order WooCommerce order
     * @return string Sales condition code
     */
    public static function get_sales_condition($order) {
        // Default to cash
        return self::SALES_CONDITION_CASH;
    }

    /**
     * Human-readable label for a Hacienda sales condition code.
     *
     * @param string $code SALES_CONDITION_* (01..05, 99)
     * @return string Label en español.
     */
    public static function get_sales_condition_label($code) {
        $labels = [
            self::SALES_CONDITION_CASH        => 'Contado',
            self::SALES_CONDITION_CREDIT      => 'Crédito',
            self::SALES_CONDITION_CONSIGNMENT => 'Consignación',
            self::SALES_CONDITION_APART       => 'Apartado',
            self::SALES_CONDITION_LEASE       => 'Arrendamiento',
            self::SALES_CONDITION_OTHER       => 'Otros',
        ];
        return $labels[$code] ?? 'Contado';
    }

    /**
     * Get payment method code + label for MedioPagoOtros.
     *
     * Returns [code, otros_label]. otros_label is non-null only when code === PAYMENT_OTHER
     * y se quiere emitir un texto diferenciador en MedioPagoOtros.
     *
     * @param WC_Order $order WooCommerce order
     * @return array{0:string,1:?string} [Hacienda code, MedioPagoOtros label or null]
     */
    public static function get_payment_method($order) {
        $payment_method = $order->get_payment_method();

        $map = [
            // Default WooCommerce gateways.
            'cod'                               => [self::PAYMENT_CASH, null],
            'bacs'                              => [self::PAYMENT_TRANSFER, null],
            'cheque'                            => [self::PAYMENT_CHECK, null],
            // Site-specific gateway.
            'elevento-powertranz'               => [self::PAYMENT_CARD, null],
            // FooEvents POS — slug pattern: fooeventspos-{key} (class-fooeventspos-admin.php:2346).
            'fooeventspos-cash'                 => [self::PAYMENT_CASH, null],
            'fooeventspos-cash_on_delivery'     => [self::PAYMENT_CASH, null],
            'fooeventspos-direct_bank_transfer' => [self::PAYMENT_TRANSFER, null],
            'fooeventspos-check_payment'        => [self::PAYMENT_CHECK, null],
            'fooeventspos-split'                => [self::PAYMENT_OTHER, 'Pago combinado'],
        ];

        if (isset($map[$payment_method])) {
            return $map[$payment_method];
        }

        // Heurística por substring para variantes (Square, Stripe, PayPal, card readers, PowerTranz).
        if (strpos($payment_method, 'square')     !== false ||
            strpos($payment_method, 'stripe')     !== false ||
            strpos($payment_method, 'paypal')     !== false ||
            strpos($payment_method, 'powertranz') !== false ||
            strpos($payment_method, 'card')       !== false) {
            return [self::PAYMENT_CARD, null];
        }

        return [self::PAYMENT_OTHER, 'Otros'];
    }

    /**
     * Human-readable label for a Hacienda payment method code.
     *
     * @param string      $code        TipoMedioPago Hacienda (01..05, 99...).
     * @param string|null $otros_label Optional MedioPagoOtros descriptive text.
     * @return string Human label for display in PDF / UI.
     */
    public static function get_payment_method_label($code, $otros_label = null) {
        $labels = [
            self::PAYMENT_CASH     => 'Efectivo',
            self::PAYMENT_CARD     => 'Tarjeta',
            self::PAYMENT_CHECK    => 'Cheque',
            self::PAYMENT_TRANSFER => 'Transferencia',
            self::PAYMENT_OTHER    => 'Otros',
        ];
        $base = isset($labels[$code]) ? $labels[$code] : 'Otros';
        if ($code === self::PAYMENT_OTHER && !empty($otros_label) && $otros_label !== 'Otros') {
            return $base . ' (' . $otros_label . ')';
        }
        return $base;
    }

    /**
     * Left-pad a value with zeros to meet XSD fixed-width patterns.
     */
    private static function zero_pad($value, $width) {
        $numeric = preg_replace('/\D/', '', (string) $value);
        if ($numeric === '') {
            return str_repeat('0', $width);
        }
        // Trim leading zeros that would push the value past $width (e.g. the
        // DB stores "01" but the XSD demands 1 digit for Provincia). Falls
        // back to "0" if the whole string was zeros.
        if (strlen($numeric) > $width) {
            $trimmed = ltrim($numeric, '0');
            $numeric = $trimmed === '' ? '0' : $trimmed;
        }
        return str_pad($numeric, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Normalize an economic activity code to 6 numeric digits.
     *
     * @throws InvalidArgumentException When no digits are present.
     */
    private static function normalize_activity_code($value) {
        // v4.4 CodigoActividad is a 6-char STRING (xs:string, minLength=maxLength=6),
        // not 6 numeric digits. Hacienda's catalog stores codes in the ATV
        // display format "XXXX.Y" or "XXX.YY" — the literal dot counts as one
        // of the 6 characters. Burger King's accepted invoice emits "5610.0"
        // verbatim, so we preserve the dot here.
        //
        // Accepted inputs:
        //   "9609.0" → "9609.0" (already 6 chars)
        //   "9609"   → "9609.0" (synthesize the trailing .0 if missing)
        //   "561001" → "5610.01"(re-insert the dot when the caller stored
        //                         the code in the legacy 6-digit packed form)
        $value = trim((string) $value);
        if ($value === '') {
            throw new InvalidArgumentException(
                'Código de actividad económica no está configurado. Configurá el código en los ajustes del emisor.'
            );
        }

        // If it already matches "NNNN.N" (6 chars with a dot in position 4), use as-is.
        if (preg_match('/^\d{4}\.\d$/', $value) || preg_match('/^\d{3}\.\d{2}$/', $value)) {
            return $value;
        }

        $digits = preg_replace('/\D/', '', $value);
        if ($digits === '') {
            throw new InvalidArgumentException(
                'Código de actividad económica inválido: sin dígitos.'
            );
        }

        // "9609" (4 digits) → "9609.0"
        if (strlen($digits) === 4) {
            return $digits . '.0';
        }
        // "96090" or "009609" (5 digits) — last 4 become category, last becomes class → "NNNN.N"
        if (strlen($digits) === 5) {
            return substr($digits, 0, 4) . '.' . substr($digits, 4, 1);
        }
        // "561001" etc. (≥6 digits) → "NNNN.NN"
        if (strlen($digits) >= 6) {
            $digits = substr($digits, -6);
            return substr($digits, 0, 4) . '.' . substr($digits, 4, 2);
        }
        // Shorter than 4: pad-left zeros then retry.
        return str_pad($digits, 4, '0', STR_PAD_LEFT) . '.0';
    }

    /**
     * Resolver el `CodigoTarifaIVA` para un item del detalle.
     *
     * El rate% solo no es suficiente porque varios códigos comparten porcentaje
     * (ej. 13% puede ser '06' Transitorio o '08' Tarifa general). El admin asigna
     * explícitamente el código por `tax_rate_id` en
     * `FE_Woo_Tax_Codigo_Mapper` (Fase A). Aquí intentamos resolverlo via:
     *   1. `$item->get_taxes()` — `tax_rate_id` real con que WC calculó la orden.
     *   2. `WC_Tax::get_rates($product->get_tax_class())` — primer rate del class.
     *   3. Fallback legacy: mapeo numérico por `$tarifa_fallback`.
     *
     * @param WC_Product|false|null $product
     * @param WC_Order_Item|null    $item
     * @param int|float             $tarifa_fallback Rate % usada por map_tarifa_to_codigo_iva.
     * @return string CodigoTarifaIVA del enum (01–11).
     */
    private static function resolve_codigo_tarifa_iva($product, $item, $tarifa_fallback) {
        if (class_exists('FE_Woo_Tax_Codigo_Mapper')) {
            // 1. Item de la orden: WC almacena los tax_rate_id que efectivamente
            //    se aplicaron al calcular la orden. Es la fuente más fiel.
            if ($item && method_exists($item, 'get_taxes')) {
                $taxes = $item->get_taxes();
                if (!empty($taxes['total']) && is_array($taxes['total'])) {
                    $tax_rate_id = (int) key($taxes['total']);
                    if ($tax_rate_id > 0) {
                        $codigo = FE_Woo_Tax_Codigo_Mapper::get_codigo($tax_rate_id);
                        if ($codigo !== null && $codigo !== '') {
                            return $codigo;
                        }
                    }
                }
            }

            // 2. Resolver desde el tax_class del producto.
            if ($product && class_exists('WC_Tax')) {
                $tax_rates = WC_Tax::get_rates($product->get_tax_class());
                if (!empty($tax_rates) && is_array($tax_rates)) {
                    $tax_rate_id = (int) key($tax_rates);
                    if ($tax_rate_id > 0) {
                        $codigo = FE_Woo_Tax_Codigo_Mapper::get_codigo($tax_rate_id);
                        if ($codigo !== null && $codigo !== '') {
                            return $codigo;
                        }
                    }
                }
            }
        }

        // 3. Fallback legacy: mapeo numérico por rate%.
        return self::map_tarifa_to_codigo_iva($tarifa_fallback);
    }

    /**
     * Map numeric IVA rate to Hacienda v4.4 CodigoTarifaIVA enum.
     *
     * En este negocio (transporte de pasajeros y bienes/servicios afines) una
     * tarifa de 0% representa siempre operaciones exentas por ley, así que
     * 0 → '10' (Exento) en lugar de '01' (Tarifa 0%). Los demás porcentajes
     * mapean al código equivalente del enum.
     *
     * @param int|float $tarifa Applied rate (0, 1, 2, 4, 8, 13).
     * @return string CodigoTarifaIVA enum value.
     * @throws UnexpectedValueException When the rate is not in the Hacienda v4.4 enum.
     */
    public static function map_tarifa_to_codigo_iva($tarifa) {
        $t = (float) $tarifa;
        if ($t == 0)  return '10'; // Exento (servicios exentos por ley: transporte de pasajeros, salud, educación, etc.)
        if ($t == 1)  return '02'; // Tarifa reducida 1%
        if ($t == 2)  return '03'; // Tarifa reducida 2%
        if ($t == 4)  return '04'; // Tarifa reducida 4%
        if ($t == 8)  return '07'; // Tarifa transitoria 8%
        if ($t == 13) return '08'; // Tarifa general 13%
        throw new UnexpectedValueException(sprintf(
            'Tarifa IVA %s%% no tiene CodigoTarifaIVA mapeado en Hacienda v4.4. Ajustá la tarifa del producto a un valor soportado (0, 1, 2, 4, 8, 13).',
            number_format($t, 2)
        ));
    }

    /**
     * Build Exoneracion (tax exemption) element
     *
     * This method builds the exemption element for the XML invoice according to
     * Costa Rica's Hacienda requirements.
     *
     * Important calculation notes:
     * - The exemption rate (porcentaje) replaces the standard 13% IVA
     * - PorcentajeExoneracion in XML represents the percentage EXEMPTED, not the applied rate
     * - MontoExoneracion is the difference between standard tax (13%) and applied tax
     *
     * Example: If exemption rate is 4%:
     * - Applied IVA rate: 4%
     * - Percentage exempted: 100 - (4/13 * 100) = 69.23%
     * - Exemption amount: (subtotal * 13%) - (subtotal * 4%)
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param float       $subtotal Subtotal amount for this line
     * @param int         $tarifa_aplicada Applied tax rate (0, 1, 2, 4, or 8)
     * @return DOMElement|null Exoneracion element or null if not applicable
     */
    private static function build_exoneracion($xml, $order, $subtotal, $tarifa_aplicada) {
        // Check if order has exemption
        if (!class_exists('FE_Woo_Exoneracion')) {
            return null;
        }

        $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
        if (!$exon_data) {
            return null;
        }

        // Validate exemption before adding to XML
        $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
        if (!$validation['valid']) {
            return null;
        }

        $exoneracion = $xml->createElement('Exoneracion');

        // TipoDocumento
        $exoneracion->appendChild(self::text_element($xml, 'TipoDocumento', $exon_data['tipo']));

        // NumeroDocumento
        $exoneracion->appendChild(self::text_element($xml, 'NumeroDocumento', $exon_data['numero']));

        // NombreInstitucion
        $exoneracion->appendChild(self::text_element($xml, 'NombreInstitucion', $exon_data['institucion']));

        // FechaEmision
        $fecha_emision = DateTime::createFromFormat('Y-m-d', $exon_data['fecha_emision']);
        if ($fecha_emision) {
            $exoneracion->appendChild(self::text_element($xml, 'FechaEmision', $fecha_emision->format('Y-m-d\TH:i:sP')));
        }

        // PorcentajeExoneracion - percentage of tax that is EXEMPTED
        // Formula: 100 - (applied_rate / standard_rate * 100)
        // Example: if applied rate is 4%, then exempted percentage = 100 - (4/13*100) = 69.23%
        $standard_rate = 13;
        $percentage_exempted = $tarifa_aplicada == 0 ? 100 : (100 - ($tarifa_aplicada / $standard_rate * 100));
        $exoneracion->appendChild(self::text_element($xml, 'PorcentajeExoneracion', number_format($percentage_exempted, 2, '.', '')));

        // MontoExoneracion - amount of tax EXEMPTED (difference between standard and applied)
        // Formula: (subtotal * standard_rate%) - (subtotal * applied_rate%)
        $tax_standard = ($subtotal * $standard_rate) / 100;
        $tax_applied = ($subtotal * $tarifa_aplicada) / 100;
        $monto_exoneracion = $tax_standard - $tax_applied;
        $exoneracion->appendChild(self::text_element($xml, 'MontoExoneracion', number_format($monto_exoneracion, 5, '.', '')));

        return $exoneracion;
    }

    /**
     * Build InformacionReferencia (reference information) element for notes
     *
     * @param DOMDocument $xml XML document
     * @param array       $reference_data Reference data
     *                    - referenced_clave: Clave of referenced document
     *                    - referenced_date: Date of referenced document
     *                    - referenced_type: Type code (01-05, 99)
     *                    - reference_code: Reason code (01-05, 99)
     *                    - reference_reason: Text description
     * @return DOMElement InformacionReferencia element
     */
    private static function build_informacion_referencia($xml, $reference_data) {
        $info_ref = $xml->createElement('InformacionReferencia');

        // TipoDocIR - Type of referenced document (v4.4 renamed TipoDoc → TipoDocIR).
        $info_ref->appendChild(self::text_element($xml, 'TipoDocIR', $reference_data['referenced_type']));

        // Numero - Referenced document number (clave)
        $info_ref->appendChild(self::text_element($xml, 'Numero', $reference_data['referenced_clave']));

        // FechaEmisionIR - Referenced document emission date (v4.4 renamed FechaEmision → FechaEmisionIR).
        // Convert to ISO 8601 format if it's not already
        $fecha = $reference_data['referenced_date'];
        if ($fecha instanceof DateTime) {
            $fecha = $fecha->format('c');
        } elseif (is_string($fecha)) {
            $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $fecha);
            if (!$date_obj) {
                $date_obj = DateTime::createFromFormat('Y-m-d', $fecha);
            }
            if ($date_obj) {
                $fecha = $date_obj->format('c');
            }
        }
        $info_ref->appendChild(self::text_element($xml, 'FechaEmisionIR', $fecha));

        // Codigo - Reference code (reason)
        $info_ref->appendChild(self::text_element($xml, 'Codigo', $reference_data['reference_code']));

        // Razon - Text description of reason
        $razon = substr($reference_data['reference_reason'], 0, 180); // Max 180 characters
        $info_ref->appendChild(self::text_element($xml, 'Razon', $razon));

        return $info_ref;
    }

    /**
     * Valid tax rates accepted by Hacienda for Costa Rica
     */
    private static $valid_hacienda_rates = [0, 1, 2, 4, 8, 13];

    /**
     * Get the tax rate for a product/item, snapped to a valid Hacienda rate.
     *
     * Uses WC_Tax::get_rates() as the primary source. Falls back to calculating
     * from the item's tax/subtotal, then snaps to the nearest valid Hacienda rate.
     *
     * @param WC_Product|false $product   WooCommerce product (or false if deleted)
     * @param float            $item_total Item total (before tax)
     * @param float            $item_tax   Item tax amount
     * @return float Valid Hacienda tax rate
     */
    private static function get_tax_rate_for_item($product, $item_total, $item_tax) {
        // Try WC_Tax::get_rates() first (most reliable source)
        if ($product) {
            $tax_rates = WC_Tax::get_rates($product->get_tax_class());
            if (!empty($tax_rates)) {
                $first_rate = reset($tax_rates);
                return self::snap_to_valid_rate(floatval($first_rate['rate']));
            }
        }

        // Fallback: calculate from actual tax/subtotal
        if ($item_tax > 0 && $item_total > 0) {
            $calculated = ($item_tax / $item_total) * 100;
            return self::snap_to_valid_rate($calculated);
        }

        return 0;
    }

    /**
     * Snap a calculated tax rate to the nearest valid Hacienda rate.
     *
     * Hacienda only accepts specific rate values: 0, 1, 2, 4, 8, 13.
     *
     * @param float $rate Calculated rate
     * @return float Nearest valid Hacienda rate
     */
    private static function snap_to_valid_rate($rate) {
        $closest = 0;
        $min_diff = PHP_FLOAT_MAX;
        foreach (self::$valid_hacienda_rates as $valid) {
            $diff = abs($rate - $valid);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $valid;
            }
        }
        return $closest;
    }

    /**
     * Calculate tax info for an item, respecting tax_status and exemptions.
     *
     * @param WC_Order_Item|null $item      Order item (null for shipping)
     * @param WC_Product|false   $product   Product (or false if deleted)
     * @param float              $item_total Item total (before tax)
     * @param float              $item_tax   Original tax from WooCommerce
     * @param array|null         $exon_data  Exemption data or null
     * @return array ['tarifa' => float, 'tax' => float, 'is_taxable' => bool]
     */
    private static function calculate_item_tax_info($item, $product, $item_total, $item_tax, $exon_data) {
        // Determine if taxable: check product tax_status, or fall back to item's tax when product is deleted
        // For shipping ($item=null, $product=false), use $item_tax > 0 to determine taxability
        $is_taxable = $product
            ? $product->get_tax_status() === 'taxable'
            : $item_tax > 0;

        if (!$is_taxable) {
            return ['tarifa' => 0, 'tax' => 0, 'is_taxable' => false];
        }

        $tax_rate = self::get_tax_rate_for_item($product, $item_total, $item_tax);

        // If the product is "taxable" but its tax class resolves to a 0% rate (exempt class),
        // treat it as non-taxable: no tax applied and no exoneration
        if ($tax_rate == 0 && $item_tax == 0) {
            return ['tarifa' => 0, 'tax' => 0, 'is_taxable' => false];
        }

        // Only apply exoneration to items that actually have a tax rate > 0
        if ($exon_data && isset($exon_data['porcentaje']) && $tax_rate > 0) {
            // Taxable with exemption
            $tarifa = intval($exon_data['porcentaje']);
            $tax = ($item_total * $tarifa) / 100;
        } else {
            // Taxable without exemption - use the actual rate, do not default to 13%
            $tarifa = $tax_rate;
            $tax = $item_tax;
        }

        return ['tarifa' => $tarifa, 'tax' => $tax, 'is_taxable' => true];
    }

    /**
     * Tarifa numérica esperada para cada CodigoTarifaIVA según XSD v4.4.
     * Hacienda valida la coherencia código ↔ tarifa y rechaza con error -505
     * cuando no concuerdan (ej. código '10' Exento exige Tarifa=0).
     */
    private static $codigo_iva_to_tarifa = [
        '01' => 0.0,    // Tarifa 0% (Art. 32 num 1 RLIVA)
        '02' => 1.0,    // Reducida 1%
        '03' => 2.0,    // Reducida 2%
        '04' => 4.0,    // Reducida 4%
        '05' => 0.0,    // Transitorio 0%
        '06' => 4.0,    // Transitorio 4%
        '07' => 8.0,    // Transitorio 8%
        '08' => 13.0,   // General 13%
        '09' => 0.5,    // Reducida 0.5%
        '10' => 0.0,    // Exenta
        '11' => 0.0,    // 0% sin derecho a crédito
    ];

    /**
     * Pre-flight validation antes de consumir un consecutivo.
     *
     * Recorre las líneas del documento simulando lo que `build_xml` produciría
     * y detecta inconsistencias que Hacienda rechazaría:
     *  - Código de tarifa ↔ Tarifa numérica (error -505).
     *  - Monto del impuesto ↔ Base × Tarifa / 100 (error -45).
     *
     * Detectarlas acá evita quemar un consecutivo en Hacienda por errores de
     * configuración (tax_class del producto sin rate registrado, mapper FE
     * apuntando a un código incompatible con la rate aplicada por WC, etc.).
     *
     * @param WC_Order               $order
     * @param string                 $document_type 'tiquete'|'factura'|'nota_credito'|'nota_debito'.
     * @param array|null             $emisor_data
     * @param WC_Order_Item[]|null   $line_items    Override de líneas (multi-factura). null = todas.
     * @param bool                   $apply_exoneracion
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public static function validate_invoice_data($order, $document_type = 'tiquete', $emisor_data = null, $line_items = null, $apply_exoneracion = true) {
        $errors = [];

        // Validar campos del emisor antes de consumir un consecutivo.
        // OtrasSenas en XSD v4.4 exige minLength=5 / maxLength=250 dentro de
        // Ubicacion (campo requerido del Emisor). Un direccion de 2 chars en
        // la config dispara "[line 21] OtrasSenas underruns minimum length 5"
        // y quema consecutivo si llegamos al saveXML+validate.
        $emisor_errors = self::validate_emisor_fields($emisor_data);
        $errors = array_merge($errors, $emisor_errors);

        // Validar campos del receptor antes de consumir un consecutivo.
        // Antes el consecutivo se quemaba si build_receptor lanzaba excepción
        // o el XSD rechazaba el XML por Tipo/Numero/Nombre vacíos. Eso dejaba
        // huérfanos benignos en emission_log y avanzaba el counter sin emisión
        // real. Mover estos checks acá es práctico — el XSD completo es caro
        // y duplicaría build_xml; los campos del receptor cubren ~95% de los
        // rechazos reales por datos.
        // Tiquete no lleva Receptor (XSD), así que sólo validamos para el
        // resto de tipos de documento.
        if ($document_type !== 'tiquete') {
            $receptor_errors = self::validate_receptor_fields($order);
            $errors = array_merge($errors, $receptor_errors);
        }

        $exon_data = null;
        if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
            $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
            if ($exon_data) {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                if (!$validation['valid']) {
                    $exon_data = null;
                }
            }
        }

        $items = $line_items !== null ? $line_items : $order->get_items();
        $line_idx = 0;
        foreach ($items as $item) {
            $line_idx++;
            $product = $item->get_product();
            $subtotal = (float) $item->get_total();
            $item_tax = (float) $item->get_total_tax();

            $tax_info = self::calculate_item_tax_info($item, $product, $subtotal, $item_tax, $exon_data);
            $tarifa = (float) $tax_info['tarifa'];
            $tax    = (float) $tax_info['tax'];
            $codigo = self::resolve_codigo_tarifa_iva($product, $item, $tarifa);

            $product_label = $product ? ($product->get_name() ?: ('producto #' . $item->get_product_id())) : ('item ' . $line_idx);

            // Saltar líneas en cero: items con tax_status='none' o subtotal=0 emiten un
            // stub <Impuesto> con Monto=0 que Hacienda acepta aunque el código y la
            // tarifa no sean coherentes entre sí. Validar acá produciría falsos positivos
            // y bloquearía retries legítimos.
            if ($subtotal <= 0.001 && abs($tax) <= 0.001) {
                continue;
            }

            // Regla 1: código ↔ tarifa numérica.
            if (isset(self::$codigo_iva_to_tarifa[$codigo])) {
                $expected_tarifa = self::$codigo_iva_to_tarifa[$codigo];
                if (abs($tarifa - $expected_tarifa) > 0.001) {
                    $errors[] = sprintf(
                        __('Línea %d (%s): código de tarifa IVA "%s" exige Tarifa=%s%%, pero el cálculo da %s%%. Revisa el tax_class del producto y/o el mapeo en FE → Códigos de Tarifa IVA.', 'fe-woo'),
                        $line_idx,
                        $product_label,
                        $codigo,
                        rtrim(rtrim(number_format($expected_tarifa, 2, '.', ''), '0'), '.'),
                        rtrim(rtrim(number_format($tarifa, 2, '.', ''), '0'), '.')
                    );
                }
            } else {
                $errors[] = sprintf(
                    __('Línea %d (%s): código de tarifa IVA "%s" no está en el catálogo XSD v4.4 (válidos: 01–11).', 'fe-woo'),
                    $line_idx, $product_label, $codigo
                );
            }

            // Regla 2: Monto ≈ Base × Tarifa / 100.
            // Tolerancia: ₡1 o 1% del impuesto esperado (lo que sea mayor).
            // CRC no tiene decimales, WC redondea a enteros. ₡0.01 era
            // demasiado estricto y bloqueaba facturas que Hacienda acepta
            // (ej. Base 17699.12 × 13% = 2300.88 → WC almacena 2301).
            $expected_monto = $subtotal * $tarifa / 100;
            $tolerance = max(1.0, abs($expected_monto) * 0.01);
            if (abs($expected_monto - $tax) > $tolerance) {
                $errors[] = sprintf(
                    __('Línea %d (%s): Monto del impuesto %s no concuerda con Base %s × Tarifa %s%% = %s. Hacienda rechazaría con error -45.', 'fe-woo'),
                    $line_idx,
                    $product_label,
                    number_format($tax, 5, '.', ''),
                    number_format($subtotal, 5, '.', ''),
                    rtrim(rtrim(number_format($tarifa, 2, '.', ''), '0'), '.'),
                    number_format($expected_monto, 5, '.', '')
                );
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Pre-flight de campos del Emisor (espejo de build_emisor sin construir
     * XML). Devuelve lista de errores legibles. Vacío = válido.
     *
     * Cubre rechazos del EmisorType en XSD v4.4: OtrasSenas con minLength=5 /
     * maxLength=250. El emisor existe en TODO documento (incluido tiquete),
     * por lo que se ejecuta sin importar document_type.
     *
     * Si $emisor_data es null, el generador hace fallback a
     * FE_Woo_Hacienda_Config::get_address(); reproducimos ese fallback acá.
     *
     * @param array|null $emisor_data
     * @return string[]
     */
    private static function validate_emisor_fields($emisor_data) {
        $errors = [];

        $direccion = '';
        if (is_array($emisor_data) && isset($emisor_data['direccion'])) {
            $direccion = (string) $emisor_data['direccion'];
        } elseif (class_exists('FE_Woo_Hacienda_Config')) {
            $direccion = (string) FE_Woo_Hacienda_Config::get_address();
        }

        $direccion = trim($direccion);
        $len = function_exists('mb_strlen') ? mb_strlen($direccion) : strlen($direccion);

        if ($len === 0) {
            $errors[] = __('Emisor: falta Dirección (OtrasSenas). Completa el campo en FE → Emisores (o Configuración del emisor por defecto).', 'fe-woo');
        } elseif ($len < 5) {
            $errors[] = sprintf(
                __('Emisor: la dirección "%s" tiene %d caracteres. Hacienda exige mínimo 5 (XSD v4.4 OtrasSenas). Edítala en FE → Emisores.', 'fe-woo'),
                $direccion,
                $len
            );
        } elseif ($len > 250) {
            $errors[] = sprintf(
                __('Emisor: la dirección tiene %d caracteres. Hacienda exige máximo 250 (XSD v4.4 OtrasSenas). Edítala en FE → Emisores.', 'fe-woo'),
                $len
            );
        }

        return $errors;
    }

    /**
     * Pre-flight de campos del Receptor (espejo de build_receptor sin construir
     * XML). Devuelve lista de errores legibles. Vacío = válido.
     *
     * Cubre los rechazos típicos del XSD v4.4 ReceptorType: Tipo en enum,
     * Numero presente, Nombre derivable (meta o billing fallback). No reproduce
     * el XSD completo — es práctico, no perfecto.
     *
     * @param WC_Order $order
     * @return string[]
     */
    private static function validate_receptor_fields($order) {
        $errors = [];

        // Tipo de identificación.
        $id_type = (string) $order->get_meta('_fe_woo_id_type');
        $valid_id_types = ['01', '02', '03', '04', '05', '06'];
        if ($id_type === '') {
            $errors[] = __('Receptor: falta Tipo de Identificación. Completa el campo "Tipo" en la sección Factura Electrónica del pedido.', 'fe-woo');
        } elseif (!in_array($id_type, $valid_id_types, true)) {
            $errors[] = sprintf(
                __('Receptor: Tipo de Identificación "%s" inválido. Valores aceptados por Hacienda: %s.', 'fe-woo'),
                $id_type,
                implode(', ', $valid_id_types)
            );
        }

        // Número de identificación.
        $id_number = (string) $order->get_meta('_fe_woo_id_number');
        $id_number_clean = preg_replace('/[^0-9A-Za-z]/', '', $id_number);
        if ($id_number_clean === '') {
            $errors[] = __('Receptor: falta Número de Identificación. Completa el campo "Número" en la sección Factura Electrónica del pedido.', 'fe-woo');
        }

        // Nombre — espejo de la lógica fallback de build_receptor: meta primario,
        // legacy `_fe_woo_name`, billing first+last, billing company. Si todos
        // vienen vacíos, build_receptor lanzaría excepción y quemaría el
        // consecutivo.
        $full_name = trim((string) $order->get_meta('_fe_woo_full_name'));
        if ($full_name === '') {
            $full_name = trim((string) $order->get_meta('_fe_woo_name'));
        }
        if ($full_name === '') {
            $billing_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            $billing_company = trim((string) $order->get_billing_company());
            if ($billing_company !== '' || $billing_name !== '') {
                $full_name = $billing_company !== '' ? $billing_company : $billing_name;
            }
        }
        if ($full_name === '') {
            $errors[] = __('Receptor: falta Nombre/Razón Social. Completa "Nombre Completo o Razón Social" en la sección Factura Electrónica o los datos de facturación del cliente.', 'fe-woo');
        }

        // OtrasSenas: build_receptor concatena siempre RECEPTOR_OTRAS_SENAS_SUFFIX
        // y trunca a 250, así que el campo del cliente puede ser vacío o de
        // cualquier longitud sin riesgo de violar el XSD. No hay nada que validar
        // aquí. La coherencia geo (provincia/canton/distrito) se asegura en
        // save_ubicacion_meta y en la condición de emisión de build_receptor.

        return $errors;
    }
}
