<?php

use PHPUnit\Framework\TestCase;

/**
 * Smoke test del FE_Woo_Factura_Generator.
 *
 * Por ahora es un placeholder que verifica únicamente que la clase carga
 * y expone las constantes / métodos públicos esperados. Una validación E2E
 * real (build_factura → XML → xmllint vs XSD v4.4) requiere WordPress
 * runtime + WC + emisor configurado, y debe correrse en DDEV / Pantheon
 * multidev (no en este pure-unit suite).
 *
 * @covers FE_Woo_Factura_Generator
 */
class FacturaGeneratorTest extends TestCase {

    public function test_class_loads_with_expected_constants() {
        $this->assertTrue(class_exists('FE_Woo_Factura_Generator'));

        // Document type constants (Hacienda v4.4).
        $this->assertSame('01', FE_Woo_Factura_Generator::DOC_TYPE_FACTURA_ELECTRONICA);
        $this->assertSame('04', FE_Woo_Factura_Generator::DOC_TYPE_TIQUETE_ELECTRONICO);
        $this->assertSame('03', FE_Woo_Factura_Generator::DOC_TYPE_NOTA_CREDITO);
        $this->assertSame('02', FE_Woo_Factura_Generator::DOC_TYPE_NOTA_DEBITO);

        // Currency constants.
        $this->assertSame('CRC', FE_Woo_Factura_Generator::CURRENCY_CRC);
        $this->assertSame('USD', FE_Woo_Factura_Generator::CURRENCY_USD);

        // Sales conditions.
        $this->assertSame('01', FE_Woo_Factura_Generator::SALES_CONDITION_CASH);
        $this->assertSame('02', FE_Woo_Factura_Generator::SALES_CONDITION_CREDIT);

        // Payment methods.
        $this->assertSame('01', FE_Woo_Factura_Generator::PAYMENT_CASH);
        $this->assertSame('02', FE_Woo_Factura_Generator::PAYMENT_CARD);
        $this->assertSame('03', FE_Woo_Factura_Generator::PAYMENT_CHECK);
        $this->assertSame('04', FE_Woo_Factura_Generator::PAYMENT_TRANSFER);
        $this->assertSame('99', FE_Woo_Factura_Generator::PAYMENT_OTHER);
    }

    public function test_public_helpers_are_callable() {
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'get_payment_method'));
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'get_payment_method_label'));
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'get_sales_condition'));
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'get_sales_condition_label'));
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'map_tarifa_to_codigo_iva'));
        $this->assertTrue(method_exists('FE_Woo_Factura_Generator', 'prepare_emisor_data'));
    }
}
