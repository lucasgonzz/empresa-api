<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Services\ImageAssignment\CandidateImageProcessor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ImagenesInteligentes\Dobles\TramoDePrueba;

/**
 * Los ajustes del motor y del job que salieron de la revisión independiente (plan §13):
 *
 *   - A1: el criterio de Lucas a la letra ("primero que tenga un tamaño decente, que no se pixele; y
 *     segundo, que tenga un fondo blanco") y el grupo ampliado: hasta 8 descargas, a la IA en tandas
 *     de 4 ya ordenadas (decente → fondo blanco → tamaño), la segunda solo si la primera no dio una
 *     asignable, como mucho 2 por criterio.
 *   - A3: `borrosa` nunca se asigna sola.
 *   - B2: con la IA caída 5 artículos seguidos, la asignación frena (reanudable).
 *   - B5: un artículo que no buscó nada no resetea el contador del proveedor.
 *   - B6: el techo de llamadas a la IA es de la asignación entera.
 *   - B12: un reintento acumula los contadores del artículo.
 *   - B13: el tramo dura entre 20 y 120 segundos.
 *   - S1 / B9: las descargas no se descomprimen y aceptan binarios genéricos; lo que viene de una
 *     cabecera se guarda en UTF-8 válido.
 */
class Ajustes_del_motor_y_el_tramo_Test extends ImagenesInteligentesTestCase
{
    /** Un EAN-13 de fábrica válido. */
    const CODIGO_REAL = '7791234567898';

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
    }

    /**
     * A1: 10 resultados decentes. Se bajan 8 (2 quedan sin descargar), la primera tanda de 4 no
     * tiene ninguna que sea el producto, la segunda sí: 2 llamadas a la IA y gana la de la segunda.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function se_bajan_ocho_y_la_segunda_tanda_se_paga_solo_si_la_primera_no_dio_una_asignable()
    {
        $articulo = $this->nuevo_articulo('Pinza pico de loro 10 pulgadas', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $colores    = ['rojo', 'azul', 'verde', 'amarillo', 'violeta', 'naranja', 'negro', 'cian'];
        $resultados = [];
        $imagenes   = [];

        for ($posicion = 1; $posicion <= 10; $posicion++) {
            $url = $this->url_imagen('pinza-'.$posicion);

            $resultados[] = $this->resultado($url, 900, 900, $posicion);
            $imagenes[$url] = $this->png(900, 900, $colores[($posicion - 1) % 8]);
        }

        // La primera tanda (posiciones 1 a 4) no es el producto; la violeta (posición 5) sí.
        $this->falsear([self::CODIGO_REAL => $resultados], $imagenes, ['violeta' => $this->veredicto('si', 'high')]);

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('violeta', $this->color_de_la_imagen_guardada($item->imagen_archivo));
        $this->assertSame(2, $this->llamadas_ia, 'Dos tandas: la segunda porque la primera no dio una asignable.');
        $this->assertSame(2, (int) $item->validaciones_ia);

        $descargadas = 0;

        for ($posicion = 1; $posicion <= 10; $posicion++) {
            $descargadas += $this->requests_a($this->url_imagen('pinza-'.$posicion));
        }

        $this->assertSame(8, $descargadas, 'Se bajan 8 de las 10.');

        $candidatas = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'];
        $sin_bajar  = array_filter($candidatas, function ($candidata) {
            return $candidata['resultado'] === 'no_evaluada' && strpos((string) $candidata['motivo'], 'No se llegó a descargar') === 0;
        });

        $this->assertCount(2, $sin_bajar);
    }

    /**
     * A1: si la primera tanda ya da una asignable, la segunda no se paga (1 llamada).
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function si_la_primera_tanda_da_una_asignable_no_se_paga_la_segunda()
    {
        $articulo = $this->nuevo_articulo('Pinza de punta 6 pulgadas', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $colores    = ['rojo', 'azul', 'verde', 'amarillo', 'violeta', 'naranja', 'negro', 'cian'];
        $resultados = [];
        $imagenes   = [];

        for ($posicion = 1; $posicion <= 8; $posicion++) {
            $url = $this->url_imagen('punta-'.$posicion);

            $resultados[] = $this->resultado($url, 900, 900, $posicion);
            $imagenes[$url] = $this->png(900, 900, $colores[$posicion - 1]);
        }

        $this->falsear([self::CODIGO_REAL => $resultados], $imagenes, ['azul' => $this->veredicto('si', 'high')]);

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame(1, $this->llamadas_ia);
    }

    /**
     * A1: el ORDEN en que las ve la IA (y en que se cortan las tandas) es decente → fondo blanco →
     * tamaño; y entre dos decentes con fondo blanco gana la más grande.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_ia_las_ve_en_el_orden_de_lucas_decente_fondo_blanco_tamano()
    {
        $articulo = $this->nuevo_articulo('Llave inglesa 12 pulgadas', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        // La IA falsa anota en qué orden le llegan las imágenes (todas son el producto).
        $orden = [];
        $test  = $this;

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        $imagenes = [
            $this->url_imagen('grande-celeste') => $this->png(1200, 1200, 'rojo', self::FONDO_CELESTE),
            $this->url_imagen('media-blanca')   => $this->png(700, 700, 'azul'),
            $this->url_imagen('chica-blanca')   => $this->png(500, 500, 'verde'),
            $this->url_imagen('grande-blanca')  => $this->png(900, 900, 'amarillo'),
        ];
        $serper = [
            $this->resultado($this->url_imagen('grande-celeste'), 1200, 1200, 1),
            $this->resultado($this->url_imagen('media-blanca'), 700, 700, 2),
            $this->resultado($this->url_imagen('chica-blanca'), 500, 500, 3),
            $this->resultado($this->url_imagen('grande-blanca'), 900, 900, 4),
        ];

        Http::fake(function ($request) use ($test, $imagenes, $serper, &$orden) {
            if (strpos($request->url(), 'google.serper.dev') !== false) {
                return Http::response(['images' => $serper], 200);
            }

            if (strpos($request->url(), 'api.anthropic.com') !== false) {
                return $test->respuesta_de_ia($request, function ($color) use ($test, &$orden) {
                    $orden[] = $color;

                    return $test->veredicto_publico('si', 'high');
                });
            }

            if (isset($imagenes[$request->url()])) {
                return Http::response($imagenes[$request->url()], 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('no', 404);
        });

        $item = $this->procesar($run, $articulo);

        // Decentes con fondo blanco (la más grande primero), la decente sin fondo blanco, la aceptable.
        $this->assertSame(['amarillo', 'azul', 'rojo', 'verde'], $orden);

        // Gana la decente con fondo blanco MÁS GRANDE (el tamaño desempata después del fondo).
        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status);
        $this->assertSame('amarillo', $this->color_de_la_imagen_guardada($item->imagen_archivo));
        $this->assertSame(900, (int) $item->imagen_meta['ancho']);
    }

    /**
     * A3: la IA la marca borrosa → nunca se asigna sola: a revisar con motivo `borrosa` y el aviso
     * "Se ve borrosa".
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function una_borrosa_va_a_revisar_con_su_motivo_y_su_aviso()
    {
        $articulo = $this->nuevo_articulo('Cinta aisladora negra', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('cinta'), 900, 900, 1)]],
            [$this->url_imagen('cinta') => $this->png(900, 900, 'negro')],
            ['negro' => $this->veredicto('si', 'high', ['borrosa'])]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('borrosa', $item->motivo);
        $this->assertContains('Se ve borrosa', $item->imagen_meta['avisos']);
    }

    /**
     * B2: la IA no responde en 5 artículos seguidos → la asignación queda fallida con un motivo
     * legible y los que faltaban siguen pendientes (reanudable). Un artículo que no necesitó la IA
     * (sin datos) no cuenta ni resetea.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_la_ia_caida_cinco_articulos_seguidos_la_asignacion_frena()
    {
        $articulos = [];
        $serper    = [];
        $imagenes  = [];

        for ($i = 0; $i < 7; $i++) {
            $codigo = $this->con_verificador('77900080000'.$i);
            $url    = $this->url_imagen('sin-ia-'.$i);

            $articulos[] = $this->nuevo_articulo('Producto sin IA '.$i, $codigo);
            $serper[$codigo] = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]  = $this->png(900, 900, 'rojo');

            // Después del tercero, uno sin nombre ni código real: no le pregunta nada a la IA.
            if ($i === 2) {
                $articulos[] = $this->nuevo_articulo(null, null);
            }
        }

        $run = $this->asignacion($articulos);

        $this->falsear($serper, $imagenes, 'caida');

        Queue::fake();

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertSame('La validación con IA no responde en 5 artículos seguidos; se frenó para no gastar búsquedas. Revisá la clave y reanudá.', $run->motivo_estado);
        $this->assertSame(6, (int) $run->procesados, '5 con la IA caída y el sin datos del medio.');
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count(), 'Los que faltaban siguen pendientes.');
        $this->assertSame(5, (int) $run->errores_ia_seguidos);

        // Reanudable, con la cuenta en cero.
        $this->assertSame(200, (int) ImageAssignmentRunHelper::reanudar($run)['status']);
        $this->assertSame(0, (int) $run->fresh()->errores_ia_seguidos);
    }

    /**
     * B5: un artículo que no intentó ninguna búsqueda no dice nada del proveedor: no resetea la
     * racha de errores. Uno que buscó bien, sí.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_articulo_que_no_busco_nada_no_resetea_la_racha_del_proveedor()
    {
        $sin_datos = $this->nuevo_articulo(null, null);
        $con_datos = $this->nuevo_articulo('Taladro percutor', self::CODIGO_REAL);
        $run       = $this->asignacion([$sin_datos, $con_datos]);

        DB::table('image_assignment_runs')->where('id', $run->id)->update(['errores_proveedor_seguidos' => 3]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('taladro'), 900, 900, 1)]],
            [$this->url_imagen('taladro') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        Queue::fake();

        $tramo              = new TramoDePrueba($run->id);
        $tramo->presupuesto = 0;
        $tramo->handle();

        $this->assertSame(ImageAssignmentItem::STATUS_NO_ASIGNADA, ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $sin_datos->id)->value('status'));
        $this->assertSame(3, (int) $run->fresh()->errores_proveedor_seguidos, 'El sin datos no buscó: la racha sigue.');

        $tramo              = new TramoDePrueba($run->id);
        $tramo->presupuesto = 0;
        $tramo->handle();

        $this->assertSame(0, (int) $run->fresh()->errores_proveedor_seguidos, 'Uno que buscó bien la corta.');
    }

    /**
     * B6: el techo de llamadas a la IA es de la asignación entera (el mayor entre
     * max_calls_batch y 4 por artículo), contra las validaciones que ya hizo en todos sus tramos.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_el_techo_de_la_asignacion_alcanzado_no_se_llama_a_la_ia()
    {
        config(['services.article_image_validation.max_calls_batch' => 0]);

        $articulo = $this->nuevo_articulo('Serrucho de poda', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);

        // 1 artículo → techo = max(0, 4 × 1) = 4, y ya se hicieron 4.
        DB::table('image_assignment_runs')->where('id', $run->id)->update(['validaciones_ia' => 4]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('serrucho'), 900, 900, 1)]],
            [$this->url_imagen('serrucho') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);

        $this->assertSame(0, $this->llamadas_ia, 'No se llamó a la IA.');
        $this->assertNotSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, 'Sin IA nada se asigna solo.');

        $candidatas = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'];
        $this->assertStringContainsString('techo de consultas a la IA', (string) $candidatas[0]['motivo']);
    }

    /**
     * B12: si el artículo se reintenta, sus contadores ACUMULAN lo del intento anterior (que ya se
     * pagó), así la suma de los artículos cuadra con la de la asignación.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function un_reintento_acumula_los_contadores_del_articulo()
    {
        $articulo = $this->nuevo_articulo('Llave de caño 14 pulgadas', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);
        $item     = ImageAssignmentItem::where('run_id', $run->id)->first();

        // Un primer intento que gastó 1 búsqueda y 1 validación y se murió antes de cerrar.
        DB::table('image_assignment_items')->where('id', $item->id)->update(['busquedas' => 1, 'validaciones_ia' => 1]);
        DB::table('image_assignment_runs')->where('id', $run->id)->update(['busquedas' => 1, 'busquedas_codigo' => 1, 'validaciones_ia' => 1]);

        $this->falsear(
            [self::CODIGO_REAL => [$this->resultado($this->url_imagen('llave'), 900, 900, 1)]],
            [$this->url_imagen('llave') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        $item = $this->procesar($run, $articulo);
        $run->refresh();

        $this->assertSame(2, (int) $item->busquedas);
        $this->assertSame(2, (int) $item->validaciones_ia);
        $this->assertSame((int) $run->busquedas, (int) ImageAssignmentItem::where('run_id', $run->id)->sum('busquedas'));
        $this->assertSame((int) $run->validaciones_ia, (int) ImageAssignmentItem::where('run_id', $run->id)->sum('validaciones_ia'));
    }

    /**
     * B13: el presupuesto de un tramo se acota a [20, 120] segundos.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_tramo_dura_entre_20_y_120_segundos()
    {
        $job      = new ProcessImageAssignmentRunJob(1);
        $segundos = new \ReflectionMethod($job, 'segundos_por_tramo');
        $segundos->setAccessible(true);

        config(['services.imagenes_inteligentes.segundos_por_tramo' => 0]);
        $this->assertSame(20, $segundos->invoke($job));

        config(['services.imagenes_inteligentes.segundos_por_tramo' => 5000]);
        $this->assertSame(120, $segundos->invoke($job));

        config(['services.imagenes_inteligentes.segundos_por_tramo' => 50]);
        $this->assertSame(50, $segundos->invoke($job));
    }

    /**
     * S1 y B9: la descarga pide el cuerpo sin comprimir (los topes miden los bytes del cable), acepta
     * una imagen servida como binario genérico y guarda en UTF-8 válido lo que venga de una cabecera.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function las_descargas_no_se_descomprimen_aceptan_binarios_y_limpian_las_cabeceras()
    {
        $articulo = $this->nuevo_articulo('Balde de albañil 12 L', self::CODIGO_REAL);
        $run      = $this->asignacion([$articulo]);
        $test     = $this;
        $imagen   = $this->png(900, 900, 'rojo');

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) use ($test, $imagen) {
            $url = $request->url();

            if (strpos($url, 'google.serper.dev') !== false) {
                return Http::response(['images' => [
                    $test->resultado_publico($test->url_imagen_publica('pagina'), 900, 900, 1),
                    $test->resultado_publico($test->url_imagen_publica('binaria'), 900, 900, 2),
                ]], 200);
            }

            if (strpos($url, 'api.anthropic.com') !== false) {
                return $test->respuesta_de_ia($request, ['rojo' => $test->veredicto_publico('si', 'high')]);
            }

            if ($url === $test->url_imagen_publica('binaria')) {
                return Http::response($imagen, 200, ['Content-Type' => 'application/octet-stream']);
            }

            // Una página con un Content-Type con un byte suelto (no es UTF-8 válido).
            return Http::response('<html></html>', 200, ['Content-Type' => "text/html; charset=\xC3("]);
        });

        $item = $this->procesar($run, $articulo);

        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, $item->status, 'La servida como octet-stream era una imagen: se usó.');

        foreach (Http::recorded() as $par) {
            if (strpos($par[0]->url(), 'imagenes.test') !== false) {
                $this->assertSame(['identity'], $par[0]->header('Accept-Encoding'), 'Sin compresión: los topes miden bytes del cable.');
            }
        }

        $candidatas = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'];
        $this->assertSame('no_es_imagen', $candidatas[0]['resultado']);
        $this->assertTrue(mb_check_encoding((string) $candidatas[0]['motivo'], 'UTF-8'), 'El motivo con la cabecera quedó en UTF-8 válido.');
    }

    /**
     * La comparación del procesador, sola: decente → fondo blanco → tamaño → posición.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_orden_del_procesador_es_decente_fondo_blanco_tamano_posicion()
    {
        $listas = [
            ['clave' => 'a', 'ancho' => 1200, 'alto' => 1200, 'fondo_blanco' => false, 'posicion' => 1],
            ['clave' => 'b', 'ancho' => 700, 'alto' => 700, 'fondo_blanco' => true, 'posicion' => 2],
            ['clave' => 'c', 'ancho' => 500, 'alto' => 500, 'fondo_blanco' => true, 'posicion' => 3],
            ['clave' => 'd', 'ancho' => 900, 'alto' => 900, 'fondo_blanco' => true, 'posicion' => 4],
            ['clave' => 'e', 'ancho' => 700, 'alto' => 700, 'fondo_blanco' => true, 'posicion' => 5],
        ];

        usort($listas, function ($a, $b) {
            return CandidateImageProcessor::comparar_para_la_ia($a, $b);
        });

        $this->assertSame(['d', 'b', 'e', 'a', 'c'], array_column($listas, 'clave'));
    }

    /**
     * El color del centro de un archivo guardado.
     *
     * @param  string $archivo
     * @return string
     */
    protected function color_de_la_imagen_guardada($archivo)
    {
        $imagen = imagecreatefromwebp(\Illuminate\Support\Facades\Storage::disk('public')->path($archivo));

        ob_start();
        imagepng($imagen);
        $png = ob_get_clean();

        imagedestroy($imagen);

        return $this->color_de_la_miniatura(base64_encode($png));
    }

    /**
     * Para los closures del Http::fake (veredicto(), resultado() y url_imagen() son protegidos).
     *
     * @param  string $es_el_producto
     * @param  string $confianza
     * @param  array  $problemas
     * @return array
     */
    public function veredicto_publico($es_el_producto, $confianza = 'high', array $problemas = [])
    {
        return $this->veredicto($es_el_producto, $confianza, $problemas);
    }

    /**
     * @return array
     */
    public function resultado_publico($url, $ancho, $alto, $posicion)
    {
        return $this->resultado($url, $ancho, $alto, $posicion);
    }

    /**
     * @param  string $nombre
     * @return string
     */
    public function url_imagen_publica($nombre)
    {
        return $this->url_imagen($nombre);
    }
}
