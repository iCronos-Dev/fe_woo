<?php
/**
 * Bootstrap minimalista para tests unitarios puros del fe_woo plugin.
 *
 * NO carga WordPress runtime — solo declara las constantes y stubs mínimos
 * para que las clases del plugin se puedan cargar y testear de forma aislada.
 * Tests que requieran WC_Order/wc_get_order/etc. usan stubs locales (ver
 * tests/PaymentMethodTest.php).
 *
 * Para correr: `composer test` desde web/app/plugins/fe_woo/
 */

// Stub de constantes WordPress que las clases del plugin usan al cargarse.
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/');
}
if (!defined('FE_WOO_PLUGIN_DIR')) {
    define('FE_WOO_PLUGIN_DIR', dirname(__DIR__) . '/');
}
if (!defined('FE_WOO_VERSION')) {
    define('FE_WOO_VERSION', 'test');
}

// Solo cargamos la clase Factura_Generator: es la única necesaria para los
// tests pure-unit (PaymentMethod, CodigoTarifaIva). Los smoke tests con XML
// completo deben correr en un entorno WP real (DDEV / Pantheon multidev).
require_once dirname(__DIR__) . '/includes/class-fe-woo-factura-generator.php';
