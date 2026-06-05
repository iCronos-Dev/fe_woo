<?php
/**
 * FE WooCommerce Proforma Management
 *
 * Handles proforma order status and email notifications
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Proforma Class
 *
 * Manages proforma order status and prevents proforma orders from entering the invoice queue
 */
class FE_Woo_Proforma {

    /**
     * Proforma status slug (without 'wc-' prefix)
     */
    const STATUS_SLUG = 'proforma';

    /**
     * Initialize the proforma management
     */
    public static function init() {
        // Register custom order status
        add_action('init', [__CLASS__, 'register_proforma_status']);

        // Add proforma status to order statuses list
        add_filter('wc_order_statuses', [__CLASS__, 'add_proforma_to_order_statuses']);

        // Add proforma status to bulk actions
        add_filter('bulk_actions-edit-shop_order', [__CLASS__, 'add_proforma_bulk_action']);
        add_filter('bulk_actions-woocommerce_page_wc-orders', [__CLASS__, 'add_proforma_bulk_action']);

        // Modify queue hooks to exclude proforma orders
        add_filter('fe_woo_should_add_order_to_queue', [__CLASS__, 'exclude_proforma_from_queue'], 10, 2);

        // Hook to add order to queue when status changes FROM proforma TO completed/processing
        add_action('woocommerce_order_status_changed', [__CLASS__, 'handle_proforma_to_paid_transition'], 10, 4);

        // Auto-send proforma email whenever an order reaches proforma status (covers all contexts)
        add_action('woocommerce_order_status_changed', [__CLASS__, 'auto_send_proforma_email_on_status_change'], 20, 4);

        // Add custom order action to resend proforma email
        add_filter('woocommerce_order_actions', [__CLASS__, 'add_resend_proforma_email_action']);
        add_action('woocommerce_order_action_send_proforma_email', [__CLASS__, 'send_proforma_email_action']);
    }

    /**
     * Register the proforma order status
     */
    public static function register_proforma_status() {
        register_post_status('wc-' . self::STATUS_SLUG, [
            'label' => _x('Proforma', 'Order status', 'fe-woo'),
            'public' => true,
            'exclude_from_search' => false,
            'show_in_admin_all_list' => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count' => _n_noop(
                'Proforma <span class="count">(%s)</span>',
                'Proforma <span class="count">(%s)</span>',
                'fe-woo'
            ),
        ]);
    }

    /**
     * Add proforma status to the list of order statuses
     *
     * @param array $order_statuses Existing order statuses
     * @return array Modified order statuses
     */
    public static function add_proforma_to_order_statuses($order_statuses) {
        $new_order_statuses = [];

        // Add proforma status after pending
        foreach ($order_statuses as $key => $status) {
            $new_order_statuses[$key] = $status;

            if ('wc-pending' === $key) {
                $new_order_statuses['wc-' . self::STATUS_SLUG] = _x('Proforma', 'Order status', 'fe-woo');
            }
        }

        return $new_order_statuses;
    }

    /**
     * Add proforma to bulk actions dropdown
     *
     * @param array $bulk_actions Existing bulk actions
     * @return array Modified bulk actions
     */
    public static function add_proforma_bulk_action($bulk_actions) {
        $bulk_actions['mark_' . self::STATUS_SLUG] = __('Cambiar estado a Proforma', 'fe-woo');
        return $bulk_actions;
    }

    /**
     * Exclude proforma orders from invoice queue
     *
     * @param bool     $should_add Whether to add to queue
     * @param WC_Order $order Order object
     * @return bool Modified decision
     */
    public static function exclude_proforma_from_queue($should_add, $order) {
        // Don't add if order is in proforma status
        if ($order->get_status() === self::STATUS_SLUG) {
            return false;
        }

        return $should_add;
    }

    /**
     * Handle transition from proforma to paid status
     *
     * When an order transitions from proforma to completed/processing,
     * add it to the invoice queue
     *
     * @param int      $order_id Order ID
     * @param string   $old_status Old order status
     * @param string   $new_status New order status
     * @param WC_Order $order Order object
     */
    public static function handle_proforma_to_paid_transition($order_id, $old_status, $new_status, $order) {
        // Only process if transitioning FROM proforma
        if ($old_status !== self::STATUS_SLUG) {
            return;
        }

        // Only process if transitioning TO a paid status
        $paid_statuses = ['processing', 'completed'];
        if (!in_array($new_status, $paid_statuses, true)) {
            return;
        }

        // Add to queue if not already there
        if (class_exists('FE_Woo_Queue') && !FE_Woo_Queue::order_exists_in_queue($order_id)) {
            FE_Woo_Queue::add_order_to_queue($order_id);

            // Log
            if (FE_Woo_Hacienda_Config::is_debug_enabled() && function_exists('wc_get_logger')) {
                $logger = wc_get_logger();
                $logger->info(
                    sprintf('Order #%d transitioned from proforma to %s - added to invoice queue', $order_id, $new_status),
                    ['source' => 'fe-woo-proforma']
                );
            }
        }
    }

    /**
     * Check if an order is in proforma status
     *
     * @param int|WC_Order $order Order ID or object
     * @return bool True if proforma
     */
    public static function is_proforma($order) {
        if (is_numeric($order)) {
            $order = wc_get_order($order);
        }

        if (!$order) {
            return false;
        }

        return $order->get_status() === self::STATUS_SLUG;
    }

    /**
     * Add "Send proforma email" action to order actions dropdown
     *
     * @param array $actions Existing order actions
     * @return array Modified order actions
     */
    public static function add_resend_proforma_email_action($actions) {
        global $theorder;

        // Check if we have an order object
        if (!$theorder || !is_a($theorder, 'WC_Order')) {
            return $actions;
        }

        // Only add action if order status is proforma
        if ($theorder->get_status() === self::STATUS_SLUG) {
            $actions['send_proforma_email'] = __('Enviar email proforma', 'fe-woo');
        }

        return $actions;
    }

    /**
     * Process "Send proforma email" action
     *
     * @param WC_Order $order The order object
     */
    public static function send_proforma_email_action($order) {
        // Get WooCommerce mailer instance
        $mailer = WC()->mailer();

        // Get all email classes
        $emails = $mailer->get_emails();

        // Find and trigger proforma email
        if (isset($emails['WC_Proforma_Email'])) {
            $emails['WC_Proforma_Email']->trigger($order->get_id(), $order);

            // Add order note
            $order->add_order_note(__('Email de proforma enviado manualmente.', 'fe-woo'));
        }
    }

    /**
     * Auto-send proforma email whenever an order transitions TO proforma status.
     *
     * Runs at priority 20 (after WooCommerce's own notification hooks at priority 10),
     * and covers ALL transition sources: frontend checkout, admin panel, REST API,
     * and programmatic/WP-CLI order creation.
     *
     * Double-sending is prevented by a static list inside WC_Proforma_Email::trigger()
     * — if WooCommerce's own notification already fired for this order in this request,
     * trigger() will silently return without sending a second copy.
     *
     * @param int      $order_id   Order ID
     * @param string   $old_status Previous status (without 'wc-' prefix)
     * @param string   $new_status New status (without 'wc-' prefix)
     * @param WC_Order $order      Order object
     */
    public static function auto_send_proforma_email_on_status_change($order_id, $old_status, $new_status, $order) {
        if ($new_status !== self::STATUS_SLUG) {
            return;
        }

        // Calling WC()->mailer() ensures the mailer (and all email classes) are
        // initialised even in WP-CLI / programmatic contexts where WooCommerce
        // would not have done so automatically.
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (!isset($emails['WC_Proforma_Email'])) {
            return;
        }

        // WC_Proforma_Email::trigger() handles deduplication internally via a
        // static list, so calling it here is safe even if WC's own notification
        // hook already fired for this order earlier in the same request.
        $emails['WC_Proforma_Email']->trigger($order_id, $order);

        // Add order note only when we are the ones triggering (i.e. the email
        // was not already sent by WC's hook). Check the order notes count as a
        // proxy isn't reliable, so we always add a note — WC_Proforma_Email's
        // dedup means the actual send only happens once.
        $order->add_order_note(__('Email de proforma enviado automáticamente.', 'fe-woo'));
    }

    /**
     * Trigger the proforma email for an order.
     *
     * @param WC_Order $order Order object
     */
    private static function trigger_proforma_email($order) {
        $mailer = WC()->mailer();
        $emails = $mailer->get_emails();

        if (!isset($emails['WC_Proforma_Email'])) {
            return;
        }

        $emails['WC_Proforma_Email']->trigger($order->get_id(), $order);
    }
}
