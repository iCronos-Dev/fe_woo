<?php
/**
 * Multi-Factura Generator Class
 *
 * Handles the logic for generating multiple invoices per order based on emisores
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Class FE_Woo_Multi_Factura_Generator
 *
 * Analyzes orders and determines how to split them into multiple facturas
 */
class FE_Woo_Multi_Factura_Generator {

    /**
     * Analyze order and determine facturas to generate
     *
     * @param WC_Order $order Order object
     * @return array Array with 'multiple' boolean and 'facturas' array
     */
    public static function generate_facturas_for_order($order) {
        if (!$order) {
            return [
                'multiple' => false,
                'facturas' => [],
                'error' => __('Orden no encontrada', 'fe-woo'),
            ];
        }

        // Group items by emisor
        $items_by_emisor = self::get_order_items_by_emisor($order);

        $facturas_to_generate = [];

        $first = true;
        foreach ($items_by_emisor as $emisor_id => $items) {
            // Skip if no items
            if (empty($items)) {
                continue;
            }

            /**
             * Allow integrations to skip the factura for a specific emisor on
             * this order — e.g. the parent theme's per-event "Pausar Factura
             * Electrónica" flag, which suppresses the invoice for the emisor
             * group that contains a paused product without affecting the
             * invoices of other emisores in the same order.
             *
             * Returning truthy skips the entire emisor group: no factura is
             * pushed and `include_shipping` is preserved for the next group
             * that survives the filter (so shipping always lands on the first
             * actually-generated factura, never on a skipped one).
             *
             * @param bool       $skip      Default false (do not skip).
             * @param int|string $emisor_id Emisor ID, or 'unknown' if no
             *                              emisor could be resolved.
             * @param array      $items     Order line items grouped to this emisor.
             * @param WC_Order   $order     Full order, for additional context.
             */
            $skip = (bool) apply_filters('fe_woo_should_skip_emisor_factura', false, $emisor_id, $items, $order);
            if ($skip) {
                continue;
            }

            $facturas_to_generate[] = [
                'emisor_id' => $emisor_id,
                'items' => $items,
                'type' => 'standard',
                'description' => __('Factura', 'fe-woo'),
                'include_shipping' => $first, // First factura includes shipping costs
            ];
            $first = false;
        }

        // Check if we have multiple facturas
        $is_multiple = count($facturas_to_generate) > 1;

        return [
            'multiple' => $is_multiple,
            'facturas' => $facturas_to_generate,
            'total_facturas' => count($facturas_to_generate),
        ];
    }

    /**
     * Group order items by emisor
     *
     * @param WC_Order $order Order object
     * @return array Array of items grouped by emisor_id
     */
    public static function get_order_items_by_emisor($order) {
        $grouped = [];

        foreach ($order->get_items() as $item_id => $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            // Skip products with tax_status 'none' - they should not be included in electronic invoices
            if ($product->get_tax_status() === 'none') {
                continue;
            }

            $product_id = $product->get_id();

            // Get product's emisor (will return default if not assigned)
            $emisor_id = FE_Woo_Product_Emisor::get_product_emisor_id($product_id);

            if (!$emisor_id) {
                // Fallback to default emisor
                $parent = FE_Woo_Emisor_Manager::get_parent_emisor();
                $emisor_id = $parent ? $parent->id : 'unknown';
            }

            // Group by emisor
            if (!isset($grouped[$emisor_id])) {
                $grouped[$emisor_id] = [];
            }
            $grouped[$emisor_id][] = $item;
        }

        return $grouped;
    }

    /**
     * Calculate total for a set of items
     *
     * @param array $items Order items
     * @return float Total amount
     */
    public static function calculate_items_total($items) {
        $total = 0;

        foreach ($items as $item) {
            $total += $item->get_total() + $item->get_total_tax();
        }

        return $total;
    }

    /**
     * Calculate subtotal for a set of items
     *
     * @param array $items Order items
     * @return float Subtotal amount (without tax)
     */
    public static function calculate_items_subtotal($items) {
        $subtotal = 0;

        foreach ($items as $item) {
            $subtotal += $item->get_subtotal();
        }

        return $subtotal;
    }

    /**
     * Calculate tax for a set of items
     *
     * @param array $items Order items
     * @return float Tax amount
     */
    public static function calculate_items_tax($items) {
        $tax = 0;

        foreach ($items as $item) {
            $tax += $item->get_total_tax();
        }

        return $tax;
    }

    /**
     * Get items summary for logging
     *
     * @param array $items Order items
     * @return string Items summary
     */
    public static function get_items_summary($items) {
        $summary = [];

        foreach ($items as $item) {
            $product = $item->get_product();
            $product_name = $product ? $product->get_name() : $item->get_name();
            $summary[] = sprintf(
                '%s x%d (₡%s)',
                $product_name,
                $item->get_quantity(),
                number_format($item->get_total() + $item->get_total_tax(), 2)
            );
        }

        return implode(', ', $summary);
    }

    /**
     * Validate factura data before generation
     *
     * @param array $factura_data Factura data
     * @return array Validation result with 'valid' boolean and 'errors' array
     */
    public static function validate_factura_data($factura_data) {
        $errors = [];

        if (empty($factura_data['emisor_id'])) {
            $errors[] = __('Emisor ID es requerido', 'fe-woo');
        } else {
            // Verify emisor exists and is active
            $emisor = FE_Woo_Emisor_Manager::get_emisor($factura_data['emisor_id']);
            if (!$emisor) {
                $errors[] = __('Emisor no encontrado', 'fe-woo');
            } elseif (!$emisor->active) {
                $errors[] = __('El emisor está inactivo', 'fe-woo');
            }
        }

        if (empty($factura_data['items']) || !is_array($factura_data['items'])) {
            $errors[] = __('La factura debe tener al menos un item', 'fe-woo');
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * Log multi-factura processing
     *
     * @param WC_Order $order          Order object
     * @param array    $facturas_data  Facturas data
     * @param string   $stage          Processing stage
     */
    public static function log_processing($order, $facturas_data, $stage = 'analysis') {
        if (!function_exists('wc_get_logger')) {
            return;
        }

        $logger = wc_get_logger();
        $context = ['source' => 'fe-woo-multi-factura'];

        $message = sprintf(
            'Multi-factura %s - Order #%d: %d factura(s) to generate',
            $stage,
            $order->get_id(),
            count($facturas_data)
        );

        foreach ($facturas_data as $index => $factura) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($factura['emisor_id']);
            $emisor_name = $emisor ? $emisor->nombre_legal : 'Unknown';

            $message .= sprintf(
                "\n  Factura %d: Emisor #%d (%s) - %s - %d items - Total: ₡%s",
                $index + 1,
                $factura['emisor_id'],
                $emisor_name,
                $factura['type'],
                count($factura['items']),
                number_format(self::calculate_items_total($factura['items']), 2)
            );
        }

        $logger->info($message, $context);
    }

    /**
     * Get factura filename
     *
     * @param WC_Order $order      Order object
     * @param int      $emisor_id  Emisor ID
     * @param string   $type       Factura type
     * @param int      $index      Factura index (for multiple)
     * @return string Filename
     */
    public static function get_factura_filename($order, $emisor_id, $type, $index = 1) {
        $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        $emisor_slug = $emisor ? sanitize_title($emisor->nombre_legal) : 'emisor-' . $emisor_id;

        return sprintf(
            'order-%d-%s-%s-%d',
            $order->get_id(),
            $emisor_slug,
            $type,
            $index
        );
    }
}
