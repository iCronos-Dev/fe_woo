<?php
/**
 * Proforma Email
 *
 * Email sent to customers when their order is set to proforma status
 *
 * @package FE_Woo
 * @extends WC_Email
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Proforma_Email Class
 */
class WC_Proforma_Email extends WC_Email {

    /**
     * Tracks order IDs for which the proforma email has already been sent
     * during this PHP request. Prevents double-sending when both WooCommerce's
     * own notification hooks and our universal status-change hook fire.
     *
     * @var int[]
     */
    private static $sent_order_ids = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'customer_proforma';
        $this->customer_email = true;
        $this->title = __('Proforma', 'fe-woo');
        $this->description = __('Proforma emails are sent to customers when their order is set to proforma status.', 'fe-woo');
        $this->template_html = 'emails/customer-proforma.php';
        $this->template_plain = 'emails/plain/customer-proforma.php';
        $this->template_base = FE_WOO_PLUGIN_DIR . 'templates/';
        $this->placeholders = [
            '{order_date}' => '',
            '{order_number}' => '',
        ];

        // Triggers for this email: specific transition hooks
        add_action('woocommerce_order_status_pending_to_proforma_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_on-hold_to_proforma_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_failed_to_proforma_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_cancelled_to_proforma_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_processing_to_proforma_notification', [$this, 'trigger'], 10, 2);
        add_action('woocommerce_order_status_completed_to_proforma_notification', [$this, 'trigger'], 10, 2);
        // Generic notification hook (covers direct creation in proforma status)
        add_action('woocommerce_order_status_proforma_notification', [$this, 'trigger'], 10, 2);

        // Call parent constructor
        parent::__construct();

        // Other settings
        $this->recipient = $this->get_option('recipient', get_option('admin_email'));
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    public function get_default_subject() {
        return __('Proforma de su pedido en {site_title}', 'fe-woo');
    }

    /**
     * Get email heading.
     *
     * @return string
     */
    public function get_default_heading() {
        return __('Gracias por su pedido', 'fe-woo');
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int            $order_id The order ID.
     * @param WC_Order|false $order Order object.
     */
    public function trigger($order_id, $order = false) {
        // Prevent sending the same email twice in one request (e.g. when both
        // WooCommerce's own notification hook and our universal hook fire).
        if ($order_id && in_array((int) $order_id, self::$sent_order_ids, true)) {
            return;
        }

        $this->setup_locale();

        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if (is_a($order, 'WC_Order')) {
            $this->object = $order;
            $this->recipient = $this->object->get_billing_email();
            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
        }

        if ($this->is_enabled() && $this->get_recipient()) {
            // Mark as sent before actually sending to guard against re-entrancy.
            if ($order_id) {
                self::$sent_order_ids[] = (int) $order_id;
            }
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    /**
     * Get content html.
     *
     * @return string
     */
    public function get_content_html() {
        return wc_get_template_html(
            $this->template_html,
            [
                'order' => $this->object ? $this->object : $this->get_preview_order(),
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => false,
                'email' => $this,
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Get content plain.
     *
     * @return string
     */
    public function get_content_plain() {
        return wc_get_template_html(
            $this->template_plain,
            [
                'order' => $this->object ? $this->object : $this->get_preview_order(),
                'email_heading' => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin' => false,
                'plain_text' => true,
                'email' => $this,
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Get a sample order for preview purposes.
     *
     * @return WC_Order|false
     */
    protected function get_preview_order() {
        // Try to get the most recent proforma order
        $orders = wc_get_orders([
            'limit' => 1,
            'status' => 'proforma',
            'orderby' => 'date',
            'order' => 'DESC',
        ]);

        // If no proforma orders exist, get any recent order
        if (empty($orders)) {
            $orders = wc_get_orders([
                'limit' => 1,
                'orderby' => 'date',
                'order' => 'DESC',
            ]);
        }

        return !empty($orders) ? $orders[0] : false;
    }

    /**
     * Default content to show below main email content.
     *
     * @return string
     */
    public function get_default_additional_content() {
        return __('Esta es una proforma de su pedido. Cuando realice el pago, su pedido será procesado y recibirá la factura electrónica correspondiente.', 'fe-woo');
    }

    /**
     * Initialize settings form fields.
     */
    public function init_form_fields() {
        /* translators: %s: list of placeholders */
        $placeholder_text = sprintf(__('Available placeholders: %s', 'woocommerce'), '<code>' . implode('</code>, <code>', array_keys($this->placeholders)) . '</code>');
        $this->form_fields = [
            'enabled' => [
                'title' => __('Enable/Disable', 'woocommerce'),
                'type' => 'checkbox',
                'label' => __('Enable this email notification', 'woocommerce'),
                'default' => 'yes',
            ],
            'subject' => [
                'title' => __('Subject', 'woocommerce'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_subject(),
                'default' => '',
            ],
            'heading' => [
                'title' => __('Email heading', 'woocommerce'),
                'type' => 'text',
                'desc_tip' => true,
                'description' => $placeholder_text,
                'placeholder' => $this->get_default_heading(),
                'default' => '',
            ],
            'additional_content' => [
                'title' => __('Additional content', 'woocommerce'),
                'description' => __('Text to appear below the main email content.', 'woocommerce') . ' ' . $placeholder_text,
                'css' => 'width:400px; height: 75px;',
                'placeholder' => __('N/A', 'woocommerce'),
                'type' => 'textarea',
                'default' => $this->get_default_additional_content(),
                'desc_tip' => true,
            ],
            'email_type' => [
                'title' => __('Email type', 'woocommerce'),
                'type' => 'select',
                'description' => __('Choose which format of email to send.', 'woocommerce'),
                'default' => 'html',
                'class' => 'email_type wc-enhanced-select',
                'options' => $this->get_email_type_options(),
                'desc_tip' => true,
            ],
        ];
    }
}
