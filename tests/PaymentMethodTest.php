<?php

use PHPUnit\Framework\TestCase;

/**
 * Stub mínimo de WC_Order para tests pure-unit. Solo expone get_payment_method,
 * que es lo único que FE_Woo_Factura_Generator::get_payment_method() consulta.
 */
class FakeOrderForPaymentTest {
    private $payment_method;
    public function __construct($payment_method) {
        $this->payment_method = $payment_method;
    }
    public function get_payment_method() {
        return $this->payment_method;
    }
}

/**
 * Cubre el mapeo de gateway slug → [TipoMedioPago, MedioPagoOtros] de
 * FE_Woo_Factura_Generator::get_payment_method() (introducido en v1.16.0).
 *
 * @covers FE_Woo_Factura_Generator::get_payment_method
 * @covers FE_Woo_Factura_Generator::get_payment_method_label
 */
class PaymentMethodTest extends TestCase {

    /**
     * @dataProvider gatewayProvider
     */
    public function test_gateway_maps_to_expected_code_and_label($slug, $expected_code, $expected_otros) {
        $order = new FakeOrderForPaymentTest($slug);
        list($code, $otros) = FE_Woo_Factura_Generator::get_payment_method($order);

        $this->assertSame($expected_code, $code, "Gateway $slug should map to code $expected_code");
        $this->assertSame($expected_otros, $otros, "Gateway $slug should map to label " . var_export($expected_otros, true));
    }

    public function gatewayProvider() {
        return [
            // Default WC gateways.
            'WC cod (cash on delivery)'            => ['cod', '01', null],
            'WC bacs (bank transfer)'              => ['bacs', '04', null],
            'WC cheque'                            => ['cheque', '03', null],
            // Site-specific.
            'PowerTranz card'                      => ['elevento-powertranz', '02', null],
            // FooEvents POS — slug pattern fooeventspos-{key}.
            'FooEvents POS cash'                   => ['fooeventspos-cash', '01', null],
            'FooEvents POS cash on delivery'       => ['fooeventspos-cash_on_delivery', '01', null],
            'FooEvents POS direct bank transfer'   => ['fooeventspos-direct_bank_transfer', '04', null],
            'FooEvents POS check payment'          => ['fooeventspos-check_payment', '03', null],
            'FooEvents POS split (Pago combinado)' => ['fooeventspos-split', '99', 'Pago combinado'],
            // Heurística por substring.
            'Stripe variant'                       => ['stripe', '02', null],
            'Square variant'                       => ['fooeventspos-square_terminal', '02', null],
            'Unknown gateway → 99 Otros'           => ['random_unknown', '99', 'Otros'],
        ];
    }

    public function test_label_helper_produces_human_readable_string() {
        $this->assertSame('Efectivo', FE_Woo_Factura_Generator::get_payment_method_label('01'));
        $this->assertSame('Tarjeta', FE_Woo_Factura_Generator::get_payment_method_label('02'));
        $this->assertSame('Cheque', FE_Woo_Factura_Generator::get_payment_method_label('03'));
        $this->assertSame('Transferencia', FE_Woo_Factura_Generator::get_payment_method_label('04'));
        $this->assertSame('Otros', FE_Woo_Factura_Generator::get_payment_method_label('99'));

        // Otros con etiqueta descriptiva (split payment).
        $this->assertSame(
            'Otros (Pago combinado)',
            FE_Woo_Factura_Generator::get_payment_method_label('99', 'Pago combinado')
        );

        // Otros con la etiqueta default 'Otros' no se duplica.
        $this->assertSame(
            'Otros',
            FE_Woo_Factura_Generator::get_payment_method_label('99', 'Otros')
        );
    }

    public function test_sales_condition_label_covers_all_codes() {
        $this->assertSame('Contado', FE_Woo_Factura_Generator::get_sales_condition_label('01'));
        $this->assertSame('Crédito', FE_Woo_Factura_Generator::get_sales_condition_label('02'));
        $this->assertSame('Consignación', FE_Woo_Factura_Generator::get_sales_condition_label('03'));
        $this->assertSame('Apartado', FE_Woo_Factura_Generator::get_sales_condition_label('04'));
        $this->assertSame('Arrendamiento', FE_Woo_Factura_Generator::get_sales_condition_label('05'));
        $this->assertSame('Otros', FE_Woo_Factura_Generator::get_sales_condition_label('99'));
        // Código no reconocido → fallback Contado.
        $this->assertSame('Contado', FE_Woo_Factura_Generator::get_sales_condition_label('zz'));
    }
}
