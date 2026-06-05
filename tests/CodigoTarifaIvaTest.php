<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre el mapeo de tarifa IVA → CodigoTarifaIVA Hacienda v4.4.
 *
 * Las tarifas válidas del enum (resolución MH-DGT-RES-0027-2024) son:
 *   01 — Tarifa 0% Exonerado (no se mapea numéricamente; lo emite exoneración).
 *   02 — Reducida 1%
 *   03 — Reducida 2%
 *   04 — Reducida 4%
 *   05 — Transitoria 0.5%  (no soportada hoy en el plugin)
 *   06 — Transitoria 1%    (no soportada hoy)
 *   07 — Transitoria 8%    (sí soportada)
 *   08 — General 13%
 *   10 — Exento (servicios exentos por ley)
 *   11 — 0% (sin derecho a crédito) (no soportada hoy)
 *
 * @covers FE_Woo_Factura_Generator::map_tarifa_to_codigo_iva
 */
class CodigoTarifaIvaTest extends TestCase {

    /**
     * @dataProvider validTarifaProvider
     */
    public function test_known_tarifa_maps_to_codigo($tarifa, $expected_codigo) {
        $this->assertSame(
            $expected_codigo,
            FE_Woo_Factura_Generator::map_tarifa_to_codigo_iva($tarifa)
        );
    }

    public function validTarifaProvider() {
        return [
            'tarifa 0% (exento)'        => [0, '10'],
            'tarifa 0.0 float'          => [0.0, '10'],
            'tarifa reducida 1%'        => [1, '02'],
            'tarifa reducida 2%'        => [2, '03'],
            'tarifa reducida 4%'        => [4, '04'],
            'tarifa transitoria 8%'     => [8, '07'],
            'tarifa general 13%'        => [13, '08'],
            'tarifa 13.0 float'         => [13.0, '08'],
        ];
    }

    public function test_unsupported_tarifa_throws() {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/no tiene CodigoTarifaIVA/');

        // 5% no es una tarifa válida en Hacienda v4.4.
        FE_Woo_Factura_Generator::map_tarifa_to_codigo_iva(5);
    }

    public function test_unsupported_tarifa_15_throws() {
        $this->expectException(UnexpectedValueException::class);

        FE_Woo_Factura_Generator::map_tarifa_to_codigo_iva(15);
    }
}
