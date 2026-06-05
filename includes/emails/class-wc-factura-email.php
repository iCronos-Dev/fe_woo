<?php
/**
 * Factura / Tiquete Electrónico Email
 *
 * Email sent to customers after their Factura Electrónica or Tiquete Electrónico
 * has been successfully generated and submitted to Hacienda.
 *
 * @package FE_Woo
 * @extends WC_Email
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WC_Factura_Email Class
 */
class WC_Factura_Email extends WC_Email {

    /**
     * Document clave (Hacienda key)
     *
     * @var string
     */
    public $clave = '';

    /**
     * Document type: 'factura' or 'tiquete'
     *
     * @var string
     */
    public $document_type = 'tiquete';

    /**
     * File attachments to include
     *
     * @var array
     */
    public $custom_attachments = [];

    /**
     * Constructor
     */
    public function __construct() {
        $this->id = 'customer_factura';
        $this->customer_email = true;
        $this->title = __('Factura / Tiquete Electrónico', 'fe-woo');
        $this->description = __('Se envía al cliente cuando su Factura o Tiquete Electrónico ha sido generado y enviado a Hacienda.', 'fe-woo');
        $this->template_html = 'emails/customer-factura.php';
        $this->template_plain = 'emails/plain/customer-factura.php';
        $this->template_base = FE_WOO_PLUGIN_DIR . 'templates/';
        $this->placeholders = [
            '{order_date}' => '',
            '{order_number}' => '',
        ];

        // Call parent constructor
        parent::__construct();

        // Customer email — recipient is set by trigger(), never fallback to admin
        $this->recipient = '';
    }

    /**
     * Get email subject.
     *
     * @return string
     */
    public function get_default_subject() {
        return __('Documento electrónico de su pedido #{order_number} en {site_title}', 'fe-woo');
    }

    /**
     * Get email heading.
     *
     * @return string
     */
    public function get_default_heading() {
        return __('Documento Electrónico', 'fe-woo');
    }

    /**
     * Get the dynamic document label based on document_type.
     *
     * @return string
     */
    public function get_document_label() {
        return ($this->document_type === 'factura')
            ? __('Factura Electrónica', 'fe-woo')
            : __('Tiquete Electrónico', 'fe-woo');
    }

    /**
     * Trigger the sending of this email.
     *
     * @param int      $order_id      The order ID.
     * @param WC_Order $order         Order object.
     * @param string   $clave         Document clave.
     * @param string   $document_type 'factura' or 'tiquete'.
     * @param array    $attachments   File paths to attach.
     */
    public function trigger($order_id, $order = false, $clave = '', $document_type = 'tiquete', $attachments = []) {
        $this->setup_locale();

        // Reset state from any previous cached invocation
        $this->clave = '';
        $this->document_type = 'tiquete';
        $this->custom_attachments = [];
        $this->object = null;
        $this->recipient = '';

        if ($order_id && !is_a($order, 'WC_Order')) {
            $order = wc_get_order($order_id);
        }

        if (is_a($order, 'WC_Order')) {
            $this->object = $order;
            $this->recipient = $this->get_factura_recipient($order, $document_type);
            $this->placeholders['{order_date}'] = wc_format_datetime($this->object->get_date_created());
            $this->placeholders['{order_number}'] = $this->object->get_order_number();
        }

        $this->clave = $clave;
        $this->document_type = $document_type;
        $this->custom_attachments = $attachments;

        if ($this->is_enabled() && $this->get_recipient()) {
            $this->send($this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments());
        }

        $this->restore_locale();
    }

    /**
     * Determine the correct recipient for this document type.
     *
     * @param WC_Order $order         Order object.
     * @param string   $document_type 'factura' or 'tiquete'.
     * @return string
     */
    private function get_factura_recipient($order, $document_type) {
        if ($document_type === 'factura') {
            $email = $order->get_meta('_fe_woo_invoice_email');
            if (!empty($email)) {
                return $email;
            }
        }
        return $order->get_billing_email();
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
                'order'              => $this->object ? $this->object : $this->get_preview_order(),
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => false,
                'email'              => $this,
                'clave'              => $this->clave,
                'document_type'      => $this->document_type,
                'document_label'     => $this->get_document_label(),
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
                'order'              => $this->object ? $this->object : $this->get_preview_order(),
                'email_heading'      => $this->get_heading(),
                'additional_content' => $this->get_additional_content(),
                'sent_to_admin'      => false,
                'plain_text'         => true,
                'email'              => $this,
                'clave'              => $this->clave,
                'document_type'      => $this->document_type,
                'document_label'     => $this->get_document_label(),
            ],
            '',
            $this->template_base
        );
    }

    /**
     * Get custom attachments.
     *
     * @return array
     */
    public function get_attachments() {
        $attachments = parent::get_attachments();
        return array_merge($attachments, $this->custom_attachments);
    }

    /**
     * Get a placeholder for preview purposes.
     *
     * Returns false to avoid exposing real customer PII in admin previews.
     * WooCommerce handles false gracefully in email templates.
     *
     * @return false
     */
    protected function get_preview_order() {
        return false;
    }

    /**
     * Default content to show below main email content.
     *
     * @return string
     */
    public function get_default_additional_content() {
        return __('Los documentos electrónicos (PDF y XML) se encuentran adjuntos a este correo. Consérvelos para sus registros.', 'fe-woo');
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
