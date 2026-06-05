<?php
/**
 * Customer multi-factura email (HTML)
 *
 * Sent for each individual factura in a multi-emisor order.
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/customer-multi-factura.php.
 *
 * @package FE_Woo/Templates/Emails
 * @version 1.0.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (!defined('ABSPATH')) {
    exit;
}

$email_improvements_enabled = class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil') && FeaturesUtil::feature_is_enabled('email_improvements');

$emisor_name = isset($factura_data['emisor_name']) ? $factura_data['emisor_name'] : '';
$factura_clave = isset($factura_data['clave']) ? $factura_data['clave'] : '';
$items_count = isset($factura_data['items_count']) ? (int) $factura_data['items_count'] : 0;

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if (!empty($order->get_billing_first_name())) {
    /* translators: %s: Customer first name */
    printf(esc_html__('Estimado/a %s,', 'fe-woo'), esc_html($order->get_billing_first_name()));
} else {
    echo esc_html__('Estimado/a cliente,', 'fe-woo');
}
?>
</p>
<p>
<?php
/* translators: %1$s: Document label, %2$s: Order number */
printf(
    esc_html__('Adjunto encontrará la %1$s correspondiente a su orden #%2$s.', 'fe-woo'),
    esc_html($document_label),
    esc_html($order->get_order_number())
);
?>
</p>

<table cellspacing="0" cellpadding="6" border="1" style="border-collapse: collapse; border: 1px solid #e5e5e5; margin: 16px 0; width: 100%;">
    <tbody>
        <?php if (!empty($emisor_name)) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5; width: 30%;"><?php esc_html_e('Emisor', 'fe-woo'); ?></th>
            <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html($emisor_name); ?></td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($type_label)) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e('Tipo', 'fe-woo'); ?></th>
            <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html($type_label); ?></td>
        </tr>
        <?php endif; ?>
        <?php if (!empty($factura_clave)) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e('Clave', 'fe-woo'); ?></th>
            <td style="padding: 12px; border: 1px solid #e5e5e5; font-family: monospace; font-size: 12px; word-break: break-all;"><?php echo esc_html($factura_clave); ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($items_count > 0) : ?>
        <tr>
            <th scope="row" style="text-align: left; padding: 12px; background-color: #f8f8f8; border: 1px solid #e5e5e5;"><?php esc_html_e('Líneas', 'fe-woo'); ?></th>
            <td style="padding: 12px; border: 1px solid #e5e5e5;"><?php echo esc_html($items_count); ?></td>
        </tr>
        <?php endif; ?>
    </tbody>
</table>

<p><?php esc_html_e('Los documentos electrónicos (PDF y XML) correspondientes a este emisor se encuentran adjuntos a este correo.', 'fe-woo'); ?></p>
<?php echo $email_improvements_enabled ? '</div>' : ''; ?>

<?php
/*
 * @hooked WC_Emails::order_details() Shows the order details table.
 * @hooked WC_Structured_Data::generate_order_data() Generates structured data.
 * @hooked WC_Structured_Data::output_structured_data() Outputs structured data.
 * @since 2.5.0
 */
do_action('woocommerce_email_order_details', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::order_meta() Shows order meta data.
 */
do_action('woocommerce_email_order_meta', $order, $sent_to_admin, $plain_text, $email);

/*
 * @hooked WC_Emails::customer_details() Shows customer details
 * @hooked WC_Emails::email_address() Shows email address
 */
do_action('woocommerce_email_customer_details', $order, $sent_to_admin, $plain_text, $email);

/**
 * Show user-defined additional content - this is set in each email's settings.
 */
if ($additional_content) {
    echo $email_improvements_enabled ? '<table border="0" cellpadding="0" cellspacing="0" width="100%" role="presentation"><tr><td class="email-additional-content">' : '';
    echo wp_kses_post(wpautop(wptexturize($additional_content)));
    echo $email_improvements_enabled ? '</td></tr></table>' : '';
}

/*
 * @hooked WC_Emails::email_footer() Output the email footer
 */
do_action('woocommerce_email_footer', $email);
