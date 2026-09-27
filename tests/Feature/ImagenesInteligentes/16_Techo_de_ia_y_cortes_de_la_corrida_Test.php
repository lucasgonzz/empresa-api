<?php

namespace Tests\Feature\ImagenesInteligentes;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\ImageAssignmentRunHelper;
use App\Jobs\ProcessImageAssignmentRunJob;
use App\Models\ImageAssignmentItem;
use App\Models\ImageAssignmentRun;
use App\Services\ImageAssignment\ArticleImageAssignmentEngine;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ImagenesInteligentes\Dobles\TramoDePrueba;

/**
 * Los cortes de una corrida después del re-verificador independiente (sexta pasada, 27/9/2026):
 *
 *   - B6: con el techo de validaciones con IA de la asignación alcanzado, las candidatas del artículo
 *     en curso van al pozo SIN EVALUAR (el artículo termina a revisar con sin_validacion_ia, NUNCA
 *     "sin resultados", que lo dejaría 90 días sin volver a buscar) y el job corta la corrida
 *     (fallida, reanudable) antes de pagar otra búsqueda. Al reanudar, el techo arranca de nuevo.
 *   - B2: la IA apagada a propósito o sin clave es configuración, no una caída: las de selección y
 *     del asistente siguen (todo a revisar) sin cortarse; la de todo el catálogo se corta con la
 *     causa real. Y el corte por la IA caída dice el error real.
 *   - B8: el failed() del job corre una sola vez por ficha de tramo.
 */
class Techo_de_ia_y_cortes_de_la_corrida_Test extends ImagenesInteligentesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
        Queue::fake();
    }

    /**
     * Corre el motor sobre el item de un artículo y devuelve lo que devolvió procesar() (el helper
     * de la base devuelve el item: acá hace falta también el resultado).
     *
     * @param  \App\Models\ImageAssignmentRun $run
     * @param  \App\Models\Article            $articulo
     * @return array  [resultado de procesar(), item fresco]
     */
    protected function procesar_con_resultado(ImageAssignmentRun $run, $articulo)
    {
        $item = ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulo->id)->firstOrFail();

        $item->status   = ImageAssignmentItem::STATUS_PROCESANDO;
        $item->intentos = 1;
        $item->save();

        $resultado = (new ArticleImageAssignmentEngine($run->fresh()))->procesar($item);

        return [$resultado, $item->fresh()];
    }

    /**
     * B6 en el motor: con el techo alcanzado, la candidata por código va al pozo sin evaluar y el
     * artículo termina A REVISAR (sin_validacion_ia), sin pagar la búsqueda por nombre. Y si por
     * código no salió nada, se busca por nombre igual y sus candidatas van al pozo sin evaluar: nunca
     * "sin resultados" por no haber podido mirar las imágenes.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_el_techo_alcanzado_las_candidatas_van_a_revisar_sin_evaluar()
    {
        config(['services.article_image_validation.max_calls_batch' => 0]);

        $codigo   = $this->con_verificador('779000900001');
        $articulo = $this->nuevo_articulo('Serrucho de poda curvo', $codigo);
        $run      = $this->asignacion([$articulo]);

        // 1 artículo → techo = max(0, 4 × 1) = 4, y ya se hicieron 4.
        DB::table('image_assignment_runs')->where('id', $run->id)->update(['validaciones_ia' => 4]);

        $this->falsear(
            [$codigo => [$this->resultado($this->url_imagen('serrucho'), 900, 900, 1)]],
            [$this->url_imagen('serrucho') => $this->png(900, 900, 'rojo')],
            ['rojo' => $this->veredicto('si', 'high')]
        );

        list($resultado, $item) = $this->procesar_con_resultado($run, $articulo);

        $this->assertSame(0, $this->llamadas_ia, 'No se llamó a la IA.');
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $item->status);
        $this->assertSame('sin_validacion_ia', $item->motivo);
        $this->assertSame('sin_evaluar', $item->imagen_meta['ia']['veredicto']);
        $this->assertContains('No se pudo validar con IA', $item->imagen_meta['avisos']);
        $this->assertTrue($resultado['techo_de_ia']);
        $this->assertSame([$codigo], $this->consultas_serper, 'La búsqueda por nombre no se pagó: ya había una para revisar.');

        $por_nombre = $this->diagnostico_de($item, 'nombre');
        $this->assertFalse($por_nombre['usado']);
        $this->assertStringContainsString('techo de consultas a la IA', (string) $por_nombre['motivo_no_usado']);

        $elegida = $this->diagnostico_de($item, 'codigo_de_barras')['candidatas'][0];
        $this->assertSame('elegida', $elegida['resultado']);
        $this->assertStringContainsString('techo de consultas a la IA', (string) $elegida['motivo']);

        // Por código no sale nada: se busca por nombre igual, y lo que sale va a revisar sin evaluar.
        $otro_codigo = $this->con_verificador('779000900002');
        $otro        = $this->nuevo_articulo('Tijera de podar', $otro_codigo);
        $otro_run    = $this->asignacion([$otro]);

        DB::table('image_assignment_runs')->where('id', $otro_run->id)->update(['validaciones_ia' => 4]);

        $consulta_nombre = (new ArticleImageAssignmentEngine($otro_run->fresh()))->consulta_por_nombre($otro);

        $this->falsear(
            [
                $otro_codigo     => [],
                $consulta_nombre => [$this->resultado($this->url_imagen('tijera'), 800, 800, 1)],
            ],
            [$this->url_imagen('tijera') => $this->png(800, 800, 'azul')],
            ['azul' => $this->veredicto('si', 'high')]
        );

        list($otro_resultado, $otro_item) = $this->procesar_con_resultado($otro_run, $otro);

        $this->assertSame([$otro_codigo, $consulta_nombre], $this->consultas_serper, 'Sin ganadora por código, se buscó por nombre.');
        $this->assertSame(0, $this->llamadas_ia);
        $this->assertSame(ImageAssignmentItem::STATUS_A_REVISAR, $otro_item->status, 'Nunca sin_resultados por no haber evaluado.');
        $this->assertSame('sin_validacion_ia', $otro_item->motivo);
        $this->assertSame('nombre', $otro_item->criterio_usado);
        $this->assertTrue($otro_resultado['techo_de_ia']);
    }

    /**
     * B6 en el job: la corrida llega al techo con el primer artículo y se corta ANTES de pagar la
     * búsqueda del segundo, como fallida y con el motivo legible; los que faltaban quedan pendientes.
     * Reanudada, el techo arranca desde lo ya validado y la corrida termina.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_el_techo_alcanzado_la_corrida_se_corta_y_se_puede_reanudar()
    {
        config(['services.article_image_validation.max_calls_batch' => 0]);

        $articulos = [];
        $serper    = [];
        $imagenes  = [];

        for ($i = 1; $i <= 3; $i++) {
            $codigo = $this->con_verificador('77900091000'.$i);
            $url    = $this->url_imagen('pala-'.$i);

            $articulos[]     = $this->nuevo_articulo('Pala de punta '.$i, $codigo);
            $serper[$codigo] = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]  = $this->png(900, 900, 'verde');
        }

        $run = $this->asignacion($articulos);

        // 3 artículos → techo = max(0, 4 × 3) = 12; ya van 11: el primer artículo usa la última.
        DB::table('image_assignment_runs')->where('id', $run->id)->update(['validaciones_ia' => 11]);

        $this->falsear($serper, $imagenes, ['verde' => $this->veredicto('si', 'high')]);

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertSame('Se alcanzó el techo de validaciones con IA de esta búsqueda (12 consultas). Los artículos que faltaban quedaron pendientes: se puede reanudar.', $run->motivo_estado);
        $this->assertCount(1, $this->consultas_serper, 'El segundo artículo no pagó ninguna búsqueda.');
        $this->assertSame(1, $this->llamadas_ia);
        $this->assertSame(ImageAssignmentItem::STATUS_ASIGNADA, ImageAssignmentItem::where('run_id', $run->id)->where('article_id', $articulos[0]->id)->value('status'));
        $this->assertSame(2, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());

        // Reanudable: el techo vuelve a arrancar desde lo ya validado y la corrida termina.
        $this->assertSame(200, (int) ImageAssignmentRunHelper::reanudar($run)['status']);
        $this->assertSame(12, (int) $run->fresh()->validaciones_ia_base);

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status);
        $this->assertSame(14, (int) $run->validaciones_ia, 'Lo pagado se sigue contando entero.');
        $this->assertSame(3, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_ASIGNADA)->count());
    }

    /**
     * B2: con la IA apagada a propósito (ARTICLE_IMAGE_VALIDATION_ENABLED=false) o sin clave, una
     * asignación por selección NO se corta: sigue, y todo queda a revisar (contrato). Seis artículos
     * seguidos, uno más que el corte por la IA caída.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function con_la_ia_apagada_o_sin_clave_la_seleccion_sigue_y_todo_queda_a_revisar()
    {
        foreach (['apagada', 'sin_clave'] as $caso) {
            if ($caso === 'apagada') {
                config(['services.article_image_validation.enabled' => false]);
            } else {
                config(['services.article_image_validation.enabled' => true, 'services.anthropic.api_key' => '']);
            }

            $articulos = [];
            $serper    = [];
            $imagenes  = [];

            for ($i = 1; $i <= 6; $i++) {
                $codigo = $this->con_verificador(($caso === 'apagada' ? '77900092000' : '77900093000').$i);
                $url    = $this->url_imagen($caso.'-'.$i);

                $articulos[]     = $this->nuevo_articulo('Balde '.$caso.' '.$i, $codigo);
                $serper[$codigo] = [$this->resultado($url, 900, 900, 1)];
                $imagenes[$url]  = $this->png(900, 900, 'rojo');
            }

            $run = $this->asignacion($articulos);

            $this->falsear($serper, $imagenes, ['rojo' => $this->veredicto('si', 'high')]);

            (new ProcessImageAssignmentRunJob($run->id))->handle();

            $run->refresh();
            $this->assertSame(ImageAssignmentRun::STATUS_TERMINADA, $run->status, $caso.': no se corta.');
            $this->assertNull($run->motivo_estado, $caso);
            $this->assertSame(0, (int) $run->errores_ia_seguidos, $caso.': no es una caída.');
            $this->assertSame(0, $this->llamadas_ia, $caso);
            $this->assertSame(6, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_A_REVISAR)->where('motivo', 'sin_validacion_ia')->count(), $caso.': todo a revisar.');
        }
    }

    /**
     * B2: una de todo el catálogo necesita la IA. Si se la apagan con la corrida en marcha, se corta
     * con la causa real (la de ia_disponible()) sin pagar ni una búsqueda, y se puede reanudar.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function la_de_catalogo_se_corta_con_la_causa_real_si_le_apagan_la_ia()
    {
        $articulos = [$this->nuevo_articulo('Tenaza armador', $this->con_verificador('779000940001'))];
        $run       = $this->asignacion($articulos, ['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'aplica_tope_diario' => false]);

        config(['services.article_image_validation.enabled' => false]);

        $this->falsear([], [], []);

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertStringStartsWith(ImageAssignmentRunHelper::ia_disponible()['motivo'], (string) $run->motivo_estado);
        $this->assertStringContainsString('se puede reanudar', (string) $run->motivo_estado);
        $this->assertCount(0, $this->consultas_serper, 'No se pagó ninguna búsqueda.');
        $this->assertSame(1, ImageAssignmentItem::where('run_id', $run->id)->where('status', ImageAssignmentItem::STATUS_PENDIENTE)->count());

        // Sin clave, la otra causa real.
        $otra = $this->asignacion([$this->nuevo_articulo('Tenaza rusa', $this->con_verificador('779000940002'))], ['origen' => ImageAssignmentRun::ORIGEN_CATALOGO, 'aplica_tope_diario' => false]);

        config(['services.article_image_validation.enabled' => true, 'services.anthropic.api_key' => '']);

        (new ProcessImageAssignmentRunJob($otra->id))->handle();

        $this->assertStringStartsWith('Falta la clave de la IA (ANTHROPIC_API_KEY)', (string) $otra->fresh()->motivo_estado);
    }

    /**
     * B2: el corte por la IA caída dice el error real también cuando no llega a responder (un
     * timeout): legible, sin el mensaje crudo de cURL.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_corte_por_la_ia_caida_dice_la_causa_real_de_un_timeout()
    {
        $articulos = [];
        $serper    = [];
        $imagenes  = [];

        for ($i = 1; $i <= 5; $i++) {
            $codigo = $this->con_verificador('77900095000'.$i);
            $url    = $this->url_imagen('timeout-'.$i);

            $articulos[]     = $this->nuevo_articulo('Cinta métrica '.$i, $codigo);
            $serper[$codigo] = [$this->resultado($url, 900, 900, 1)];
            $imagenes[$url]  = $this->png(900, 900, 'rojo');
        }

        $run  = $this->asignacion($articulos);
        $test = $this;

        Http::swap(new \Illuminate\Http\Client\Factory(app('events')));
        Http::fake(function ($request) use ($test, $serper, $imagenes) {
            $url = $request->url();

            if (strpos($url, 'google.serper.dev') !== false) {
                $consulta = (string) $request->data()['q'];

                return Http::response(['images' => isset($serper[$consulta]) ? $serper[$consulta] : []], 200);
            }

            if (strpos($url, 'api.anthropic.com') !== false) {
                throw new ConnectException('cURL error 28: Operation timed out after 25001 milliseconds with 0 bytes received', $request->toPsrRequest());
            }

            if (isset($imagenes[$url])) {
                return Http::response($imagenes[$url], 200, ['Content-Type' => 'image/png']);
            }

            return Http::response('no', 404);
        });

        (new ProcessImageAssignmentRunJob($run->id))->handle();

        $run->refresh();
        $this->assertSame(ImageAssignmentRun::STATUS_FALLIDA, $run->status);
        $this->assertSame('La validación con IA no responde en 5 artículos seguidos (último error: la IA no respondió a tiempo). Se frenó para no gastar búsquedas; cuando esté resuelto, se puede reanudar.', $run->motivo_estado);
        $this->assertStringNotContainsString('cURL', (string) $run->motivo_estado);
    }

    /**
     * B8: el failed() de un tramo corre UNA sola vez por ficha. Con la cola database, si el tramo
     * murió adentro de una transacción, el rollback deshace el borrado de la fila de jobs y el mismo
     * tramo se da por fallido otra vez al vencer retry_after: la segunda vez no suma un fallo ni
     * despacha otro tramo. El failed() de OTRO tramo sí cuenta.
     *
     * @group imagenes-inteligentes
     * @test
     */
    public function el_failed_corre_una_sola_vez_por_tramo()
    {
        $articulo = $this->nuevo_articulo('Nivel de burbuja 60 cm', $this->con_verificador('779000960001'));
        $run      = $this->asignacion([$articulo]);
        $item     = ImageAssignmentItem::where('run_id', $run->id)->first();

        // Solo lo que despachen los failed() (crear la asignación ya despachó su primer tramo).
        Queue::fake();

        $tramo = new TramoDePrueba($run->id);
        $ficha = $this->ficha_de($tramo);

        $item->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'intentos' => 1, 'tramo' => $ficha]);

        $tramo->failed(new \RuntimeException('Timeout del worker (prueba)'));

        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos);
        $this->assertSame(ImageAssignmentItem::STATUS_PENDIENTE, $item->fresh()->status);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);

        // El mismo tramo, dado por fallido otra vez (la fila de jobs volvió por el rollback).
        $item->refresh();
        $item->update(['status' => ImageAssignmentItem::STATUS_PROCESANDO, 'tramo' => $ficha]);

        $tramo->failed(new \RuntimeException('El mismo tramo, 4200 s después (prueba)'));

        $this->assertSame(1, (int) $run->fresh()->fallos_consecutivos, 'No suma un fallo espurio.');
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 1);
        $this->assertSame(ImageAssignmentItem::STATUS_PROCESANDO, $item->fresh()->status, 'No toca nada.');

        // Otro tramo que muere sí cuenta.
        $otro = new TramoDePrueba($run->id);

        $item->refresh();
        $item->update(['tramo' => $this->ficha_de($otro)]);

        $otro->failed(new \RuntimeException('Otro tramo (prueba)'));

        $this->assertSame(2, (int) $run->fresh()->fallos_consecutivos);
        Queue::assertPushed(ProcessImageAssignmentRunJob::class, 2);
    }

    /**
     * La ficha de un tramo.
     *
     * @param  \App\Jobs\ProcessImageAssignmentRunJob $job
     * @return string
     */
    protected function ficha_de(ProcessImageAssignmentRunJob $job)
    {
        $propiedad = new \ReflectionProperty($job, 'tramo');
        $propiedad->setAccessible(true);

        return (string) $propiedad->getValue($job);
    }
}
