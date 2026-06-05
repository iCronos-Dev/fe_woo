<?php
/**
 * Customer proforma email (HTML)
 *
 * This template can be overridden by copying it to yourtheme/woocommerce/emails/customer-proforma.php.
 *
 * @package FE_Woo/Templates/Emails
 * @version 1.0.0
 */

use Automattic\WooCommerce\Utilities\FeaturesUtil;

if (!defined('ABSPATH')) {
    exit;
}

$email_improvements_enabled = class_exists('Automattic\WooCommerce\Utilities\FeaturesUtil') && FeaturesUtil::feature_is_enabled('email_improvements');

/*
 * @hooked WC_Emails::email_header() Output the email header
 */
do_action('woocommerce_email_header', $email_heading, $email); ?>

<?php echo $email_improvements_enabled ? '<div class="email-introduction">' : ''; ?>
<p>
<?php
if (!empty($order->get_billing_first_name())) {
    /* translators: %s: Customer first name */
    printf(esc_html__('Hola %s,', 'fe-woo'), esc_html($order->get_billing_first_name()));
} else {
    echo esc_html__('Hola,', 'fe-woo');
}
?>
</p>
<p><?php esc_html_e('Gracias por tu pedido. A continuación encontrarás los detalles de tu proforma.', 'fe-woo'); ?></p>
<?php if ($email_improvements_enabled) : ?>
    <p><?php esc_html_e('Esta es una cotización preliminar; para obtener tu factura electrónica oficial, por favor procede con el pago.', 'fe-woo'); ?></p>
<?php endif; ?>
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
