<?php
/**
 * FE WooCommerce Email Manager
 *
 * Centralizes registration of all FE_Woo email classes with WooCommerce
 * and handles template resolution for plugin email templates.
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Email_Manager Class
 *
 * Registers all custom WC_Email subclasses and resolves their templates.
 */
class FE_Woo_Email_Manager {

    /**
     * Template prefixes handled by this plugin.
     *
     * @var string[]
     */
    private static $template_prefixes = [
        'customer-proforma',
        'customer-factura',
        'customer-multi-factura',
        'customer-nota',
    ];

    /**
     * Initialize hooks.
     */
    public static function init() {
        add_filter('woocommerce_email_classes', [__CLASS__, 'register_email_classes']);
        add_filter('woocommerce_locate_template', [__CLASS__, 'locate_email_template'], 10, 3);
    }

    /**
     * Register all FE_Woo email classes with WooCommerce.
     *
     * @param array $email_classes Existing email classes.
     * @return array Modified email classes.
     */
    public static function register_email_classes($email_classes) {
        require_once FE_WOO_PLUGIN_DIR . 'includes/emails/class-wc-proforma-email.php';
        require_once FE_WOO_PLUGIN_DIR . 'includes/emails/class-wc-factura-email.php';
        require_once FE_WOO_PLUGIN_DIR . 'includes/emails/class-wc-multi-factura-email.php';
        require_once FE_WOO_PLUGIN_DIR . 'includes/emails/class-wc-nota-email.php';

        $email_classes['WC_Proforma_Email']       = new WC_Proforma_Email();
        $email_classes['WC_Factura_Email']         = new WC_Factura_Email();
        $email_classes['WC_Multi_Factura_Email']   = new WC_Multi_Factura_Email();
        $email_classes['WC_Nota_Email']            = new WC_Nota_Email();

        return $email_classes;
    }

    /**
     * Locate plugin email templates.
     *
     * Checks if the requested template belongs to this plugin and returns
     * the plugin template path if it exists.
     *
     * @param string $template      Template path.
     * @param string $template_name Template name.
     * @param string $template_path Template path in theme.
     * @return string Modified template path.
     */
    public static function locate_email_template($template, $template_name, $template_path) {
        $is_ours = false;
        foreach (self::$template_prefixes as $prefix) {
            if (strpos($template_name, $prefix) === 0) {
                $is_ours = true;
                break;
            }
        }

        if (!$is_ours) {
            return $template;
        }

        $plugin_template = FE_WOO_PLUGIN_DIR . 'templates/emails/' . $template_name;

        if (file_exists($plugin_template)) {
            return $plugin_template;
        }

        return $template;
    }
}
