<?php

namespace Tests\Feature\Pdf;

use App\Http\Controllers\Helpers\CatalogHeaderLayoutHelper;
use App\Http\Controllers\Helpers\UserHelper;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Las reglas con las que ArticleTablePdf dibuja el encabezado del catálogo, probadas fuera de
 * FPDF (la clase del PDF hace require de fpdf.php y termina en Output(); exit;, no se puede
 * instanciar acá): en qué hoja se ve cada elemento, qué imprime cada renglón (un renglón con
 * source imprime el dato ACTUAL del negocio y se saltea si hoy está vacío; uno libre sale tal
 * cual) y cómo se encaja el logo en su cuadrado sin estirarlo.
 *
 * Misión catalogo-pdf-encabezado (18/9/2026).
 *
 * @group pdf-catalogo-encabezado
 */
class Catalogo_encabezado_reglas_de_render_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Archivos temporales creados por el test (se borran al terminar).
     *
     * @var array
     */
    protected $archivos_temporales = [];

    protected function tearDown(): void
    {
        foreach ($this->archivos_temporales as $archivo) {
            if (is_file($archivo)) {
                @unlink($archivo);
            }
        }

        parent::tearDown();
    }

    /**
     * Dueño con el que se resuelven los datos del negocio (mismo modelo que usa el PDF).
     *
     * @return \App\Models\User
     */
    protected function dueno()
    {
        $owner = User::find(500);
        if (is_null($owner)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($owner, 'web');

        return UserHelper::getFullModel();
    }

    /**
     * 'first' se ve solo en la hoja 1; 'all' y cualquier basura, en todas.
     *
     * @test
     */
    public function element_visible_on_page_solo_oculta_first_fuera_de_la_primera_hoja()
    {
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page('first', 1));
        $this->assertFalse(CatalogHeaderLayoutHelper::element_visible_on_page('first', 2));
        $this->assertFalse(CatalogHeaderLayoutHelper::element_visible_on_page('first', 7));
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page('all', 1));
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page('all', 2));
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page('basura', 2));
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page(null, 2));
        $this->assertTrue(CatalogHeaderLayoutHelper::element_visible_on_page('first', '1'), 'El número de página puede llegar como string.');
    }

    /**
     * Un renglón con source imprime el valor actual del negocio aunque el guardado sea otro: el
     * teléfono que sale en el PDF es el de hoy, no el del día que se diseñó el encabezado.
     *
     * @test
     */
    public function un_renglon_con_source_usa_el_valor_actual_del_negocio()
    {
        $owner = $this->dueno();
        $telefono_actual = trim((string) $owner->phone);
        if ($telefono_actual === '') {
            $this->markTestSkipped('El usuario 500 no tiene teléfono cargado; el caso "con valor actual" no se puede probar con él.');
        }

        $resueltos = CatalogHeaderLayoutHelper::resolve_rows([
            ['title' => 'Tel.', 'value' => 'un número viejo guardado', 'source' => 'telefono'],
        ], $owner);

        $this->assertCount(1, $resueltos);
        $this->assertEquals('Tel.', $resueltos[0]['title'], 'El título guardado se respeta.');
        $this->assertEquals($telefono_actual, $resueltos[0]['value'], 'El valor es el actual del negocio, no el guardado.');
        $this->assertEquals('telefono', $resueltos[0]['source']);
    }

    /**
     * Un renglón con source cuyo dato hoy está vacío se saltea: no se imprime "Teléfono:" en blanco.
     * Se pasan las fuentes a mano (tercer parámetro) para no depender de qué tiene cargado el 500.
     *
     * @test
     */
    public function un_renglon_con_source_sin_valor_actual_se_saltea()
    {
        $fuentes = [
            ['key' => 'telefono', 'label' => 'Teléfono', 'value' => ''],
            ['key' => 'email', 'label' => 'Email', 'value' => 'ventas@negocio.com'],
        ];

        $resueltos = CatalogHeaderLayoutHelper::resolve_rows([
            ['title' => 'Teléfono', 'value' => 'lo que había', 'source' => 'telefono'],
            ['title' => 'Email', 'value' => '', 'source' => 'email'],
            ['title' => 'Otra', 'value' => 'x', 'source' => 'fuente_inexistente'],
        ], null, $fuentes);

        $this->assertCount(1, $resueltos);
        $this->assertEquals(['title' => 'Email', 'value' => 'ventas@negocio.com', 'source' => 'email'], $resueltos[0]);
    }

    /**
     * Un renglón libre pasa tal cual; sin título queda solo el valor; vacío del todo se saltea.
     *
     * @test
     */
    public function un_renglon_libre_pasa_tal_cual()
    {
        $resueltos = CatalogHeaderLayoutHelper::resolve_rows([
            ['title' => 'Horario', 'value' => 'Lun a Vie 9 a 18', 'source' => null],
            ['title' => '', 'value' => 'Solo un valor', 'source' => null],
            ['title' => 'Solo un título', 'value' => '', 'source' => null],
            ['title' => '', 'value' => '', 'source' => null],
        ], null, []);

        $this->assertEquals([
            ['title' => 'Horario', 'value' => 'Lun a Vie 9 a 18', 'source' => null],
            ['title' => '', 'value' => 'Solo un valor', 'source' => null],
            ['title' => 'Solo un título', 'value' => '', 'source' => null],
        ], $resueltos);
    }

    /**
     * Las fuentes del 500 salen de users (teléfono, email), de su primera dirección y de
     * afip_information, con el mismo criterio que el PDF de venta.
     *
     * @test
     */
    public function sources_for_user_lee_users_addresses_y_afip_information()
    {
        $owner = $this->dueno();

        $fuentes = CatalogHeaderLayoutHelper::sources_for_user($owner);
        $por_clave = array_column($fuentes, 'value', 'key');

        $this->assertSame(array_keys(CatalogHeaderLayoutHelper::SOURCE_LABELS), array_keys($por_clave));
        $this->assertEquals(trim((string) $owner->phone), $por_clave['telefono']);
        $this->assertEquals(trim((string) $owner->email), $por_clave['email']);

        $afip = $owner->afip_information;
        if (! is_null($afip)) {
            $this->assertEquals(trim((string) $afip->cuit), $por_clave['cuit']);
            $this->assertEquals(trim((string) $afip->razon_social), $por_clave['razon_social']);
            $this->assertEquals(trim((string) $afip->domicilio_comercial), $por_clave['domicilio_comercial']);
            $this->assertEquals(trim((string) $afip->ingresos_brutos), $por_clave['ingresos_brutos']);
            $this->assertEquals(
                ! is_null($afip->iva_condition) ? trim((string) $afip->iva_condition->name) : '',
                $por_clave['condicion_iva']
            );
        }

        $direccion = $owner->addresses->first();
        if (! is_null($direccion)) {
            $this->assertStringContainsString(trim((string) $direccion->street), $por_clave['direccion']);
        } else {
            $this->assertSame('', $por_clave['direccion']);
        }

        // Sin usuario, todas las fuentes existen y están vacías (el PDF no revienta, solo no imprime datos).
        $sin_usuario = array_column(CatalogHeaderLayoutHelper::sources_for_user(null), 'value', 'key');
        $this->assertSame(array_keys(CatalogHeaderLayoutHelper::SOURCE_LABELS), array_keys($sin_usuario));
        $this->assertSame([''], array_values(array_unique($sin_usuario)));
    }

    /**
     * El logo se encaja en el cuadrado de size_mm respetando la proporción: un JPG de 400×200
     * a 20 mm mide 20 × 10, no 20 × 20 estirado.
     *
     * @test
     */
    public function logo_box_dimensions_mm_respeta_la_proporcion_del_jpg()
    {
        $apaisado = $this->crear_jpg_temporal(400, 200);
        $this->assertEquals(['width' => 20.0, 'height' => 10.0], CatalogHeaderLayoutHelper::logo_box_dimensions_mm($apaisado, 20));

        $vertical = $this->crear_jpg_temporal(100, 300);
        $this->assertEquals(['width' => 10.0, 'height' => 30.0], CatalogHeaderLayoutHelper::logo_box_dimensions_mm($vertical, 30));

        $cuadrado = $this->crear_jpg_temporal(50, 50);
        $this->assertEquals(['width' => 25.0, 'height' => 25.0], CatalogHeaderLayoutHelper::logo_box_dimensions_mm($cuadrado, '25'));
    }

    /** @test */
    public function logo_box_dimensions_mm_devuelve_null_si_no_puede_leer_la_imagen()
    {
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm(null, 25));
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm('', 25));
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm(sys_get_temp_dir().'/no-existe-zz-catalogo.jpg', 25));
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm(sys_get_temp_dir(), 25), 'Un directorio no es una imagen.');

        $no_es_imagen = tempnam(sys_get_temp_dir(), 'zzcat');
        $this->archivos_temporales[] = $no_es_imagen;
        file_put_contents($no_es_imagen, 'esto no es un JPG');
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm($no_es_imagen, 25));

        $jpg = $this->crear_jpg_temporal(400, 200);
        $this->assertNull(CatalogHeaderLayoutHelper::logo_box_dimensions_mm($jpg, 0), 'Un tamaño 0 no se puede dibujar.');
    }

    /**
     * JPG generado con GD del tamaño pedido, en un archivo temporal que se borra en tearDown().
     *
     * @param  int  $ancho
     * @param  int  $alto
     * @return string  Ruta del archivo.
     */
    protected function crear_jpg_temporal($ancho, $alto)
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD no está disponible para generar el JPG de prueba.');
        }

        $ruta = tempnam(sys_get_temp_dir(), 'zzcat').'.jpg';
        $this->archivos_temporales[] = $ruta;

        $imagen = imagecreatetruecolor($ancho, $alto);
        $blanco = imagecolorallocate($imagen, 255, 255, 255);
        imagefilledrectangle($imagen, 0, 0, $ancho - 1, $alto - 1, $blanco);
        imagejpeg($imagen, $ruta, 90);
        imagedestroy($imagen);

        return $ruta;
    }
}
