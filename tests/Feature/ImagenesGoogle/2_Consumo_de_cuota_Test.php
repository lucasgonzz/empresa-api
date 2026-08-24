<?php

namespace Tests\Feature\ImagenesGoogle;

use App\Jobs\ProcessArticleBatchImagesJob;
use App\Models\GeocoderCounter;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Feature tests del consumo de la cuota diaria de busquedas (grupo 356, prompt 02).
 *
 * Protege otra falla silenciosa: si el contador vuelve a incrementarse ante un error de la API,
 * al cliente le desaparecen busquedas de su cuota diaria sin haber consultado nada, y nadie lo
 * relaciona con el error de Google. El 4/8/2026 un solo batch le quemo 4 de 10.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing esta sembrada de antes y un
 * refresh la vaciaria, rompiendo el resto de las suites.
 */
class Consumo_de_cuota_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Usuario autenticado de los tests de esta rama (mismo patron que Sales/Stock/Preferencias).
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Contador del dia recien creado para el usuario del fixture, en cero.
     *
     * @param int $user_id
     * @return \App\Models\GeocoderCounter
     */
    protected function contador_en_cero($user_id)
    {
        return GeocoderCounter::create([
            'user_id' => $user_id,
            'counter' => 0,
        ]);
    }

    /**
     * Corre una busqueda contra el fake de Google y devuelve cuanto se movio el contador.
     *
     * Se invoca `fetch_google_image_results` por reflexion (es private) en vez de correr el job
     * entero: lo que se esta probando es exactamente esa decision, y el handle() completo
     * arrastraria descarga de imagenes, validacion por IA y escritura de diagnostico.
     *
     * @param \Illuminate\Http\Client\Response|callable $respuesta_de_google
     * @param \App\Models\GeocoderCounter $counter
     * @return int Delta del contador.
     */
    protected function delta_de_cuota($respuesta_de_google, GeocoderCounter $counter)
    {
        Http::fake(['*' => $respuesta_de_google]);

        $job = new ProcessArticleBatchImagesJob([1], (int) $counter->user_id, 'KEY-DE-PRUEBA', 'CX-DE-PRUEBA', 10);

        $metodo = new ReflectionMethod($job, 'fetch_google_image_results');
        $metodo->setAccessible(true);

        $antes = (int) $counter->fresh()->counter;

        $metodo->invoke($job, 'martillo', $counter);

        return ((int) $counter->fresh()->counter) - $antes;
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function un_error_de_referrer_no_consume_cuota()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $counter = $this->contador_en_cero($user->id);

        $delta = $this->delta_de_cuota(
            Http::response(
                ['error' => ['message' => 'Requests from referer <empty> are blocked.']],
                403
            ),
            $counter
        );

        $this->assertEquals(0, $delta);
    }

    /**
     * @group imagenes-google
     * @test
     */
    public function una_busqueda_exitosa_consume_exactamente_una()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $counter = $this->contador_en_cero($user->id);

        $delta = $this->delta_de_cuota(
            Http::response([
                'items'             => [['link' => 'https://sitio-de-terceros.test/a.jpg']],
                'searchInformation' => ['totalResults' => '1'],
            ], 200),
            $counter
        );

        $this->assertEquals(1, $delta);
    }

    /**
     * Contraintuitivo a proposito: Google cobro la busqueda igual, porque la query se ejecuto y
     * devolvio cero resultados. El criterio es "¿llego a haber busqueda?", no "¿sirvio de algo?".
     *
     * @group imagenes-google
     * @test
     */
    public function una_busqueda_sin_resultados_tambien_consume()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $counter = $this->contador_en_cero($user->id);

        $delta = $this->delta_de_cuota(
            Http::response(['searchInformation' => ['totalResults' => '0']], 200),
            $counter
        );

        $this->assertEquals(1, $delta);
    }
}
