<?php

namespace Tests\Feature\ImagenesGoogle;

use App\Events\ArticleBatchImagesProcessed;
use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\BackgroundProcess;
use App\Models\GeocoderCounter;
use App\Models\User;
use App\Services\Traits\BusquedaDeImagenesEnGoogle;
use App\Services\Traits\GoogleSearchHelpers;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * El trait BusquedaDeImagenesEnGoogle (misión asistente-masivas-imagenes-y-remito, 19/9/2026):
 * la búsqueda, la cuota y la descarga salieron de ProcessArticleBatchImagesJob a un trait para
 * que el job de categorías las comparta.
 *
 * Protege tres cosas que fallan en silencio:
 * - que SIN extras el request a Custom Search sea el de siempre (sin `imgDominantColor`): si
 *   alguien lo "mejorara" pidiendo fondo blanco para los artículos, cambiaría los resultados de
 *   todos los clientes sin que ningún test lo diga;
 * - que CON extras el parámetro viaje de verdad (el job de categorías depende de eso);
 * - que el job de artículos RETOME el registro visible que nace `pendiente` al encolar, en vez
 *   de abrir otro (si no, la píldora mostraría dos procesos por cada lote).
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes.
 */
class Trait_de_busqueda_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Objeto mínimo que usa los dos traits, con las tres properties de las que depende
     * BusquedaDeImagenesEnGoogle. Mismo molde que 1_Referer_en_llamadas_a_google_Test.
     *
     * @param int $user_id
     * @return object
     */
    protected function buscador($user_id)
    {
        $buscador = new class {
            use GoogleSearchHelpers, BusquedaDeImagenesEnGoogle;

            public $user_id;
            public $google_api_key = 'KEY-DE-PRUEBA';
            public $cx = 'CX-DE-PRUEBA';

            public function buscar($query, GeocoderCounter $counter, array $extras = [])
            {
                return $this->fetch_google_image_results($query, $counter, $extras);
            }
        };

        $buscador->user_id = $user_id;

        return $buscador;
    }

    /**
     * Los parámetros de la query string de la última request a googleapis.
     *
     * @return array
     */
    protected function parametros_enviados_a_google()
    {
        $parametros = null;

        Http::assertSent(function ($request) use (&$parametros) {
            if (strpos($request->url(), 'googleapis.com/customsearch') !== false) {
                $query = (string) parse_url($request->url(), PHP_URL_QUERY);
                parse_str($query, $parametros);
            }

            return true;
        });

        return is_array($parametros) ? $parametros : [];
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function sin_extras_la_query_es_la_de_siempre_y_no_lleva_img_dominant_color()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Http::fake(['*' => Http::response(['items' => [], 'searchInformation' => ['totalResults' => '0']], 200)]);

        $counter = GeocoderCounter::create(['user_id' => $user->id, 'counter' => 0]);

        $resultado = $this->buscador($user->id)->buscar('martillo', $counter);

        $this->assertNull($resultado['api_error']);
        $this->assertSame([], $resultado['items']);

        $parametros = $this->parametros_enviados_a_google();

        $this->assertSame([
            'key'        => 'KEY-DE-PRUEBA',
            'cx'         => 'CX-DE-PRUEBA',
            'searchType' => 'image',
            'q'          => 'martillo',
        ], $parametros, 'Sin extras el request a Custom Search tiene que ser exactamente el de siempre.');

        $this->assertArrayNotHasKey('imgDominantColor', $parametros);

        // Y la búsqueda (aunque vacía) consumió una de la cuota, como siempre.
        $this->assertSame(1, (int) $counter->fresh()->counter);
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function con_extras_la_query_lleva_img_dominant_color()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Http::fake(['*' => Http::response(['items' => [], 'searchInformation' => ['totalResults' => '0']], 200)]);

        $counter = GeocoderCounter::create(['user_id' => $user->id, 'counter' => 0]);

        $this->buscador($user->id)->buscar('bazar producto fondo blanco', $counter, ['imgDominantColor' => 'white']);

        $parametros = $this->parametros_enviados_a_google();

        $this->assertSame('white', $parametros['imgDominantColor'] ?? null);
        $this->assertSame('bazar producto fondo blanco', $parametros['q'] ?? null);
        $this->assertSame('image', $parametros['searchType'] ?? null);
    }

    /**
     * El job de artículos usa el trait y ya no tiene copias propias: si alguien volviera a
     * declarar `fetch_google_image_results` en el job, el de categorías y el de artículos
     * dejarían de compartir la regla de cuota sin que nada lo diga.
     *
     * @group imagenes-google
     * @test
     */
    public function el_job_de_articulos_toma_la_busqueda_del_trait()
    {
        $this->assertContains(BusquedaDeImagenesEnGoogle::class, class_uses(ProcessArticleBatchImagesJob::class));

        $archivo_del_trait = (new \ReflectionClass(BusquedaDeImagenesEnGoogle::class))->getFileName();

        foreach (['get_or_create_counter', 'fetch_google_image_results', 'consumir_cuota', 'download_crop_and_save'] as $metodo) {
            $reflexion = new ReflectionMethod(ProcessArticleBatchImagesJob::class, $metodo);

            $this->assertSame($archivo_del_trait, $reflexion->getFileName(), $metodo . ' tiene que vivir en el trait, no en el job.');
        }
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function el_job_retoma_el_registro_visible_pendiente_en_vez_de_abrir_otro()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
        Http::fake(['*' => Http::response(['items' => []], 200)]);

        /* Lo que deja el encolado: la fila en `pendiente`, antes de que el worker levante el job. */
        $pendiente = BackgroundProcessHelper::iniciar($user->id, 'imagenes_automaticas', 'Imágenes automáticas', [
            'total'   => 0,
            'unidad'  => 'artículos',
            'detalle' => '0 artículos',
            'status'  => BackgroundProcess::STATUS_PENDIENTE,
            'etapa'   => 'En espera del procesador',
        ]);
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $pendiente->status);

        $antes = (int) BackgroundProcess::where('user_id', $user->id)->where('tipo', 'imagenes_automaticas')->count();

        /* Sin artículos: el job abre/retoma el registro, no busca nada y lo cierra. */
        (new ProcessArticleBatchImagesJob([], (int) $user->id, 'KEY-DE-PRUEBA', 'CX-DE-PRUEBA', 10))->handle();

        $this->assertSame($antes, (int) BackgroundProcess::where('user_id', $user->id)->where('tipo', 'imagenes_automaticas')->count(), 'El job abrió una segunda fila en vez de retomar la pendiente.');

        $retomado = BackgroundProcess::find($pendiente->id);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $retomado->status);
        $this->assertSame('Terminado', $retomado->etapa);
        $this->assertArrayHasKey('procesados', $retomado->resultado());
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function sin_registro_pendiente_el_job_abre_uno_como_siempre()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        Event::fake([ArticleBatchImagesProcessed::class, BackgroundProcessUpdated::class]);
        Http::fake(['*' => Http::response(['items' => []], 200)]);

        /* Un proceso `en_proceso` de OTRO lote no se retoma: es de otro worker. */
        $ajeno = BackgroundProcessHelper::iniciar($user->id, 'imagenes_automaticas', 'Imágenes automáticas', ['total' => 5]);

        $antes = (int) BackgroundProcess::where('user_id', $user->id)->where('tipo', 'imagenes_automaticas')->count();

        (new ProcessArticleBatchImagesJob([], (int) $user->id, 'KEY-DE-PRUEBA', 'CX-DE-PRUEBA', 10))->handle();

        $this->assertSame($antes + 1, (int) BackgroundProcess::where('user_id', $user->id)->where('tipo', 'imagenes_automaticas')->count());
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, BackgroundProcess::find($ajeno->id)->status, 'El job le cerró la corrida a otro lote.');
    }
}
