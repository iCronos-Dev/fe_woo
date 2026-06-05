<?php

use PHPUnit\Framework\TestCase;

/**
 * Cubre el layout de almacenamiento de documentos introducido en v1.26.0:
 * los archivos pasaron de un layout plano `factura-electronica/order-{id}/` a
 * uno fechado `factura-electronica/Y/m/d/order-{id}/` derivado de la fecha de
 * creación de la orden.
 *
 * Las clases públicas siguen siendo idempotentes: `get_xml_path()` /
 * `get_acuse_path()` / `get_pdf_path()` resuelven primero el layout fechado y
 * caen al layout legacy cuando el archivo todavía vive ahí (sin migración),
 * y `delete_order_documents()` limpia ambos directorios.
 *
 * @covers FE_Woo_Document_Storage
 */
class DocumentStorageTest extends TestCase {

    /** @var string */
    private $tmp_uploads;

    public static function setUpBeforeClass(): void {
        // Stubs WordPress mínimos que la clase de storage usa al cargarse y
        // operar. No tocamos los stubs que ya defina otro test — las guardas
        // con function_exists() permiten correr el suite completo sin choques.
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/');
        }
        if (!function_exists('trailingslashit')) {
            function trailingslashit($string) {
                return rtrim($string, '/\\') . '/';
            }
        }
        if (!function_exists('wp_upload_dir')) {
            function wp_upload_dir() {
                return [
                    'basedir' => $GLOBALS['__fe_uploads_basedir'],
                    'baseurl' => 'http://example.test/uploads',
                ];
            }
        }
        if (!function_exists('wp_mkdir_p')) {
            function wp_mkdir_p($dir) {
                if (file_exists($dir)) {
                    return is_dir($dir);
                }
                return mkdir($dir, 0777, true);
            }
        }
        if (!function_exists('sanitize_file_name')) {
            function sanitize_file_name($name) {
                return preg_replace('/[^A-Za-z0-9._-]/', '-', $name);
            }
        }
        if (!function_exists('wp_json_encode')) {
            function wp_json_encode($value, $flags = 0) {
                return json_encode($value, $flags);
            }
        }
        if (!function_exists('__')) {
            function __($s, $domain = null) {
                return $s;
            }
        }
        if (!function_exists('wc_get_order')) {
            function wc_get_order($order_id) {
                $map = $GLOBALS['__fe_wc_order_map'] ?? [];
                return $map[$order_id] ?? false;
            }
        }

        require_once dirname(__DIR__) . '/includes/class-fe-woo-document-storage.php';
    }

    protected function setUp(): void {
        $this->tmp_uploads = sys_get_temp_dir() . '/fe-woo-storage-' . uniqid('', true);
        mkdir($this->tmp_uploads . '/factura-electronica', 0777, true);

        $GLOBALS['__fe_uploads_basedir'] = $this->tmp_uploads;
        $GLOBALS['__fe_wc_order_map'] = [];

        $this->reset_storage_statics();
    }

    protected function tearDown(): void {
        $this->reset_storage_statics();
        $this->rrmdir($this->tmp_uploads);
        unset($GLOBALS['__fe_uploads_basedir'], $GLOBALS['__fe_wc_order_map']);
    }

    public function test_get_order_dir_uses_order_creation_date_for_path(): void {
        $this->register_order(1001, '2026-04-15 10:00:00');

        $dir = FE_Woo_Document_Storage::get_order_dir(1001);

        $this->assertSame(
            trailingslashit($this->tmp_uploads) . 'factura-electronica/2026/04/15/order-1001',
            $dir
        );
    }

    public function test_get_order_dir_falls_back_to_today_when_order_missing(): void {
        // No registramos la orden — wc_get_order() devolverá false.
        $today = gmdate('Y/m/d');

        $dir = FE_Woo_Document_Storage::get_order_dir(9999);

        $this->assertSame(
            trailingslashit($this->tmp_uploads) . 'factura-electronica/' . $today . '/order-9999',
            $dir
        );
    }

    public function test_save_xml_writes_into_dated_dir_and_get_xml_path_round_trips(): void {
        $this->register_order(2002, '2026-03-20 08:30:00');
        $clave = '50601012600310167282500100001010000000017111111111';

        $result = FE_Woo_Document_Storage::save_xml(2002, '<xml/>', $clave);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString(
            '/factura-electronica/2026/03/20/order-2002/',
            $result['file_path']
        );
        $this->assertSame($result['file_path'], FE_Woo_Document_Storage::get_xml_path(2002, $clave));
        $this->assertTrue(FE_Woo_Document_Storage::documents_exist(2002, $clave));
    }

    public function test_get_xml_path_falls_back_to_legacy_flat_layout(): void {
        $this->register_order(3003, '2026-02-10 12:00:00');
        $clave = 'CLAVE-LEGACY-XML';

        // Simulamos un archivo del layout viejo (pre-v1.26.0).
        $legacy_dir = $this->tmp_uploads . '/factura-electronica/order-3003';
        mkdir($legacy_dir, 0777, true);
        $legacy_path = $legacy_dir . '/' . sanitize_file_name($clave) . '.xml';
        file_put_contents($legacy_path, '<legacy/>');

        $resolved = FE_Woo_Document_Storage::get_xml_path(3003, $clave);

        $this->assertSame($legacy_path, $resolved);
    }

    public function test_get_acuse_path_falls_back_to_legacy(): void {
        $this->register_order(3004, '2026-02-10 12:00:00');
        $clave = 'CLAVE-LEGACY-ACUSE';

        $legacy_dir = $this->tmp_uploads . '/factura-electronica/order-3004';
        mkdir($legacy_dir, 0777, true);
        $legacy_path = $legacy_dir . '/' . sanitize_file_name($clave) . '_acuse.json';
        file_put_contents($legacy_path, '{}');

        $this->assertSame($legacy_path, FE_Woo_Document_Storage::get_acuse_path(3004, $clave));
    }

    public function test_get_acuse_xml_path_falls_back_to_legacy(): void {
        $this->register_order(3005, '2026-02-10 12:00:00');
        $clave = 'CLAVE-LEGACY-AHC';

        $legacy_dir = $this->tmp_uploads . '/factura-electronica/order-3005';
        mkdir($legacy_dir, 0777, true);
        $legacy_path = $legacy_dir . '/AHC-' . sanitize_file_name($clave) . '.xml';
        file_put_contents($legacy_path, '<MensajeHacienda/>');

        $this->assertSame($legacy_path, FE_Woo_Document_Storage::get_acuse_xml_path(3005, $clave));
    }

    public function test_get_pdf_path_prefers_pdf_over_html_in_dated_layout(): void {
        $this->register_order(4004, '2026-01-05 09:15:00');
        $clave = 'CLAVE-PDF';

        $dir = FE_Woo_Document_Storage::get_order_dir(4004);
        mkdir($dir, 0777, true);

        $pdf  = $dir . '/' . sanitize_file_name($clave) . '.pdf';
        $html = $dir . '/' . sanitize_file_name($clave) . '.html';
        file_put_contents($pdf, 'PDF-CONTENT');
        file_put_contents($html, '<html></html>');

        $this->assertSame($pdf, FE_Woo_Document_Storage::get_pdf_path(4004, $clave));
    }

    public function test_get_pdf_path_returns_legacy_html_when_only_legacy_exists(): void {
        $this->register_order(4005, '2026-01-05 09:15:00');
        $clave = 'CLAVE-PDF-LEGACY';

        $legacy_dir = $this->tmp_uploads . '/factura-electronica/order-4005';
        mkdir($legacy_dir, 0777, true);
        $legacy_html = $legacy_dir . '/' . sanitize_file_name($clave) . '.html';
        file_put_contents($legacy_html, '<html></html>');

        $this->assertSame($legacy_html, FE_Woo_Document_Storage::get_pdf_path(4005, $clave));
    }

    public function test_delete_order_documents_removes_both_dated_and_legacy_dirs(): void {
        $this->register_order(5005, '2026-04-01 11:00:00');
        $clave = 'CLAVE-DELETE';

        // Archivo en layout fechado.
        $dated_dir = FE_Woo_Document_Storage::get_order_dir(5005);
        mkdir($dated_dir, 0777, true);
        file_put_contents($dated_dir . '/' . sanitize_file_name($clave) . '.xml', '<xml/>');

        // Archivo en layout legacy.
        $legacy_dir = $this->tmp_uploads . '/factura-electronica/order-5005';
        mkdir($legacy_dir, 0777, true);
        file_put_contents($legacy_dir . '/' . sanitize_file_name($clave) . '_acuse.json', '{}');

        $this->assertTrue(FE_Woo_Document_Storage::delete_order_documents(5005));
        $this->assertDirectoryDoesNotExist($dated_dir);
        $this->assertDirectoryDoesNotExist($legacy_dir);
    }

    public function test_delete_order_documents_is_idempotent_when_nothing_exists(): void {
        $this->register_order(6006, '2026-04-01 11:00:00');

        $this->assertTrue(FE_Woo_Document_Storage::delete_order_documents(6006));
    }

    public function test_date_path_is_cached_per_order_id(): void {
        // El cache evita llamadas repetidas a wc_get_order para el mismo
        // order_id durante una request — prevenimos que un cambio del estado
        // global filtre dos directorios distintos para la misma orden.
        $this->register_order(7007, '2026-05-12 06:00:00');

        $first = FE_Woo_Document_Storage::get_order_dir(7007);

        // Cambia la fecha del stub: si el cache funciona, el path resuelto
        // sigue siendo el mismo.
        $this->register_order(7007, '2030-01-01 00:00:00');
        $second = FE_Woo_Document_Storage::get_order_dir(7007);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('/2026/05/12/order-7007', $first);
    }

    /**
     * Registra un order_id en el stub de wc_get_order con una fecha de
     * creación específica.
     */
    private function register_order(int $order_id, string $created_at): void {
        $GLOBALS['__fe_wc_order_map'][$order_id] = new FakeWcOrderForStorageTest($created_at);
    }

    /**
     * Resetea los static state del Storage para que cada test parta limpio.
     */
    private function reset_storage_statics(): void {
        $ref = new ReflectionClass(FE_Woo_Document_Storage::class);
        foreach (['base_dir', 'base_url', 'order_date_cache'] as $name) {
            $prop = $ref->getProperty($name);
            $prop->setAccessible(true);
            $prop->setValue(null, $name === 'order_date_cache' ? [] : null);
        }
    }

    private function rrmdir(string $dir): void {
        if (!file_exists($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->rrmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}

/**
 * Stub mínimo de WC_Order: sólo expone get_date_created() devolviendo un
 * objeto con date('Y/m/d') — que es todo lo que get_order_date_path() consume.
 */
class FakeWcOrderForStorageTest {
    private $created_at;
    public function __construct(string $created_at) {
        $this->created_at = $created_at;
    }
    public function get_date_created() {
        return new FakeWcDateTimeForStorageTest($this->created_at);
    }
}

class FakeWcDateTimeForStorageTest {
    private $dt;
    public function __construct(string $iso) {
        $this->dt = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
    }
    public function date(string $format): string {
        return $this->dt->format($format);
    }
}
