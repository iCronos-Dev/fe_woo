<?php
/**
 * FE WooCommerce XML Validator
 *
 * Validates generated XML documents against Hacienda Costa Rica v4.4 XSDs
 * before they are submitted to Hacienda's reception endpoint.
 *
 * @package FE_Woo
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_XML_Validator Class
 */
class FE_Woo_XML_Validator {

    /**
     * Absolute path to the v4.4 schema directory.
     */
    const SCHEMA_BASE = FE_WOO_PLUGIN_DIR . 'schemas/v4.4/';

    /**
     * Map root element → XSD filename.
     */
    private static $schema_map = [
        'FacturaElectronica'    => 'FacturaElectronica_V4.4.xsd',
        'TiqueteElectronico'    => 'TiqueteElectronico_V4.4.xsd',
        'NotaCreditoElectronica' => 'NotaCreditoElectronica_V4.4.xsd',
        'NotaDebitoElectronica'  => 'NotaDebitoElectronica_V4.4.xsd',
    ];

    /**
     * Validate XML against the matching v4.4 XSD.
     *
     * @param string $xml Raw XML string.
     * @return array ['valid' => bool, 'errors' => string[]]
     */
    public static function validate($xml) {
        if (!is_string($xml) || trim($xml) === '') {
            return ['valid' => false, 'errors' => ['XML vacío o no es string']];
        }

        $doc = new DOMDocument();
        $prev_internal = libxml_use_internal_errors(true);
        libxml_clear_errors();

        // LIBXML_NONET blocks external entity fetches; DOCTYPE guard below
        // blocks XXE via local entities. Fiscal XML must not resolve entities.
        if (!$doc->loadXML($xml, LIBXML_NONET)) {
            $errors = self::collect_libxml_errors();
            libxml_use_internal_errors($prev_internal);
            return ['valid' => false, 'errors' => $errors ?: ['XML malformado']];
        }

        if ($doc->doctype !== null) {
            libxml_use_internal_errors($prev_internal);
            return ['valid' => false, 'errors' => ['DOCTYPE no permitido']];
        }

        $root_name = $doc->documentElement ? $doc->documentElement->localName : '';
        if (!isset(self::$schema_map[$root_name])) {
            libxml_use_internal_errors($prev_internal);
            return [
                'valid' => false,
                'errors' => [sprintf('Elemento raíz desconocido: "%s"', $root_name)],
            ];
        }

        $schema_path = self::SCHEMA_BASE . self::$schema_map[$root_name];
        if (!is_readable($schema_path)) {
            libxml_use_internal_errors($prev_internal);
            return [
                'valid' => false,
                'errors' => [sprintf('XSD no encontrado en %s. Descargue los esquemas oficiales v4.4 de Hacienda.', $schema_path)],
            ];
        }

        $valid = @$doc->schemaValidate($schema_path);
        $errors = self::collect_libxml_errors();
        libxml_use_internal_errors($prev_internal);

        return [
            'valid'  => (bool) $valid,
            'errors' => $valid ? [] : ($errors ?: ['Validación XSD falló sin detalles']),
        ];
    }

    /**
     * Validate or throw.
     *
     * @param string $xml Raw XML string.
     * @throws Exception When validation fails.
     */
    public static function validate_or_throw($xml) {
        $result = self::validate($xml);
        if (!$result['valid']) {
            throw new Exception('XML inválido vs XSD v4.4: ' . implode(' | ', $result['errors']));
        }
    }

    /**
     * Collect and format libxml errors accumulated since last clear.
     *
     * @return string[]
     */
    private static function collect_libxml_errors() {
        $collected = [];
        foreach (libxml_get_errors() as $err) {
            $collected[] = sprintf(
                '[line %d] %s',
                $err->line,
                trim($err->message)
            );
        }
        libxml_clear_errors();
        return $collected;
    }
}
