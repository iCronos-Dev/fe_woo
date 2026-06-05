<?php
/**
 * Customer multi-factura email (plain text)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/plain/customer-multi-factura.php.
 *
 * @package FE_Woo/Templates/Emails/Plain
 * @version 1.0.0
 */

defined('ABSPATH') || exit;

$emisor_name = isset($factura_data['emisor_name']) ? $factura_data['emisor_name'] : '';
$factura_clave = isset($factura_data['clave']) ? $factura_data['clave'] : '';
$items_count = isset($factura_data['items_count']) ? (int) $factura_data['items_count'] : 0;

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n";
echo esc_html(wp_strip_all_tags($email_heading));
echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/* translators: %s: Customer first name */
echo sprintf(esc_html__('Estimado/a %s,', 'fe-woo'), esc_html($order->get_billing_first_name())) . "\n\n";

/* translators: %1$s: Document label, %2$s: Order number */
echo sprintf(
    esc_html__('Adjunto encontrará la %1$s correspondiente a su orden #%2$s.', 'fe-woo'),
    esc_html($document_label),
    esc_html($order->get_order_number())
) . "\n\n";

echo "===========================================\n";
if (!empty($emisor_name)) {
    echo sprintf(esc_html__('Emisor: %s', 'fe-woo'), esc_html($emisor_name)) . "\n";
}
if (!empty($type_label)) {
    echo sprintf(esc_html__('Tipo: %s', 'fe-woo'), esc_html($type_label)) . "\n";
}
if (!empty($factura_clave)) {
    echo sprintf(esc_html__('Clave: %s', 'fe-woo'), esc_html($factura_clave)) . "\n";
}
if ($items_count > 0) {
    echo sprintf(esc_html__('Líneas: %d', 'fe-woo'), $items_count) . "\n";
}
echo "===========================================\n\n";

echo esc_html__('Los documentos electrónicos (PDF y XML) correspondientes a este emisor se encuentran adjuntos a este correo.', 'fe-woo') . "\n\n";

echo "=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

echo "\n\n";

echo "\n=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=\n\n";

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ($additional_content) {
    echo esc_html(wp_strip_all_tags(wptexturize($additional_content)));
    echo "\n\n";
}

echo wp_kses_post(apply_filters('woocommerce_email_footer_text', get_option('woocommerce_email_footer_text')));
