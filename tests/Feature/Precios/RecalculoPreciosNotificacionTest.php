<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\PriceUpdateRun;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature tests del recálculo de precios que avisa al terminar (tarea 5).
 *
 * Lo que protegen, en orden de importancia:
 *  - que el aviso NO salga en el momento de encolar, que es el bug que esta misión corrige;
 *  - que cada disparo tenga su propia corrida y su propia notificación: cuando se compartían,
 *    el que terminaba primero cerraba por el otro y el usuario veía un número menor al real,
 *    sin que nada fallara;
 *  - que "artículos afectados" sean los que efectivamente cambiaron de precio y no los
 *    procesados, corriendo el job de verdad y dejando que la comparación decida;
 *  - que las tres formas de morirse del flujo terminen en un aviso al usuario: ahora que se
 *    notifica al final, si el final no llega no llega nada;
 *  - que el payload entre en el límite de Pusher, porque pasarse no falla: la notificación
 *    simplemente no llega y nadie se entera;
 *  - que el desglose de una corrida ajena no se pueda leer.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing del slot está sembrada de
 * antes y un refresh la vaciaría.
 */
class RecalculoPreciosNotificacionTest extends TestCase
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
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = $this->usuario_de_testing();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Corrida abierta, tal como la deja el productor.
     *
     * @param  int   $user_id
     * @param  array $atributos
     * @return \App\Models\PriceUpdateRun
     */
    protected function crear_corrida_abierta($user_id, $atributos = [])
    {
        return PriceUpdateRun::create(array_merge([
            'user_id'          => $user_id,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 0,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ], $atributos));
    }

    /**
     * Artículo con costo y ganancia, para que setFinalPrice tenga algo que calcular.
     *
     * @param  \App\Models\User $user
     * @param  string $nombre
     * @param  int|null $provider_id
     * @return \App\Models\Article
     */
    protected function crear_articulo($user, $nombre, $provider_id = null)
    {
        return Article::create([
            'name'            => $nombre,
            'user_id'         => $user->id,
            'provider_id'     => $provider_id,
            'cost'            => 100,
            'percentage_gain' => 50,
        ]);
    }

    /**
     * Criterio: el aviso llega al FINAL, no al encolar. Es el test que protege el bug
     * corregido: con la cola frenada, despachar ProcessSetFinalPrices no puede haber
     * notificado nada — pero sí tiene que haber encolado los chunks y el finalizador.
     *
     * 🔴 El assertPushed no es decorativo: handle() se traga todas las excepciones, así que
     * sin él este test seguiría verde aunque el job explotara antes de despachar nada, que
     * es exactamente el escenario en el que el usuario no recibe el aviso nunca.
     *
     * @group precios
     * @test
     */
    public function no_notifica_cuando_se_encolan_los_chunks()
    {
        $user = $this->autenticar();

        /*
         * Tiene que haber al menos un artículo: sin ninguno, el job entra en la rama de
         * "no hay nada que recalcular", que SI notifica a propósito (decisión de Lucas: un
         * recálculo que no encontró nada es información, no silencio). Sin este artículo el
         * test pasaría o fallaría según lo que tenga sembrado la base, que es peor que no
         * tenerlo.
         */
        $this->crear_articulo($user, 'zz Articulo para el test de no notificar al encolar');

        Notification::fake();
        Queue::fake();

        $job = new ProcessSetFinalPrices($user->id, null, null, false, 'dolar');
        $job->handle();

        Notification::assertNothingSent();
        Queue::assertPushed(ProcessChunkSetFinalPrices::class);
        Queue::assertPushed(FinalizeSetFinalPrices::class);
    }

    /**
     * Criterio: el finalizador no cierra mientras falten chunks, ni siquiera cuando el
     * conteo coincide, porque el bucle todavía puede estar despachando.
     *
     * @group precios
     * @test
     */
    public function el_finalizador_no_cierra_si_los_chunks_no_terminaron_de_encolarse()
    {
        $user = $this->autenticar();

        $run = $this->crear_corrida_abierta($user->id, [
            'total_chunks'     => 2,
            'processed_chunks' => 2,
            /* El conteo cierra pero el bucle todavía no termino de despachar. */
            'chunks_encolados' => 0,
        ]);

        Notification::fake();
        Queue::fake();

        $job = new FinalizeSetFinalPrices($user->id, $run->id);
        $job->handle();

        $run->refresh();

        $this->assertEquals(
            'en_proceso',
            $run->status,
            'El finalizador cerro la corrida con el bucle todavia despachando chunks.'
        );
        Notification::assertNothingSent();
    }

    /**
     * Criterio: sólo cuentan los artículos cuyo final_price cambió de verdad.
     *
     * 🔴 Este test CORRE ProcessChunkSetFinalPrices. La versión anterior insertaba a mano las
     * filas de price_update_run_articles y nunca instanciaba el job, así que probaba la
     * agregación SQL del finalizador y no la comparación de precios: el "artículo que no
     * cambió" era un artículo que el test decidía no insertar. Con la comparación rota —
     * registrando todos los procesados, o ninguno — aquel test seguía verde y el modal
     * mostraba un número inflado sin que nada fallara.
     *
     * El escenario se siembra así: se dejan los cinco artículos con su final_price ya
     * calculado y estable, y después se le pisa el valor a cuatro. Al correr el job de
     * verdad, esos cuatro vuelven a su precio calculado (o sea: cambian) y el quinto no se
     * mueve. Quién cambió lo decide la comparación del job, no el test.
     *
     * @group precios
     * @test
     */
    public function el_desglose_cuenta_solo_los_articulos_que_cambiaron_de_precio()
    {
        $user = $this->autenticar();

        $proveedor_a = Provider::create(['name' => 'zz Proveedor A precios', 'user_id' => $user->id]);
        $proveedor_b = Provider::create(['name' => 'zz Proveedor B precios', 'user_id' => $user->id]);

        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $ids[] = $this->crear_articulo($user, 'zz Articulo A ' . $i, $proveedor_a->id)->id;
        }

        $ids[] = $this->crear_articulo($user, 'zz Articulo B 1', $proveedor_b->id)->id;

        $id_sin_cambio = $this->crear_articulo($user, 'zz Articulo B 2 sin cambio', $proveedor_b->id)->id;

        $todos = array_merge($ids, [$id_sin_cambio]);

        /*
         * Pasada de calentamiento: sin run_id no registra nada ni toca contadores, sólo deja
         * los final_price calculados. Desde acá, correr el job otra vez no debería cambiar
         * ni uno solo.
         */
        $calentamiento = new ProcessChunkSetFinalPrices($todos, $user->id);
        $calentamiento->handle();

        /* A estos cuatro se les pisa el precio: el job los va a devolver a su valor real. */
        Article::whereIn('id', $ids)->update(['final_price' => 1]);

        $run = $this->crear_corrida_abierta($user->id);

        Notification::fake();

        $chunk = new ProcessChunkSetFinalPrices($todos, $user->id, $run->id);
        $chunk->handle();

        $registrados = DB::table('price_update_run_articles')
            ->where('price_update_run_id', $run->id)
            ->pluck('article_id')
            ->toArray();

        sort($registrados);
        sort($ids);

        $this->assertEquals(
            $ids,
            $registrados,
            'Los artículos registrados no son los que cambiaron de precio.'
        );

        $run->refresh();
        $this->assertEquals(1, (int) $run->processed_chunks, 'El chunk no se marco como procesado.');

        $finalizador = new FinalizeSetFinalPrices($user->id, $run->id);
        $finalizador->handle();

        $run->refresh();

        $this->assertEquals(4, $run->articles_updated, 'Se contaron artículos que no cambiaron de precio.');
        $this->assertEquals('terminado', $run->status);

        $stats = json_decode($run->stats_json, true);
        $por_proveedor = [];

        foreach ($stats['proveedores'] as $grupo) {
            $por_proveedor[$grupo['nombre']] = $grupo['cantidad'];
        }

        $this->assertEquals(3, $por_proveedor['zz Proveedor A precios']);
        $this->assertEquals(1, $por_proveedor['zz Proveedor B precios']);

        Notification::assertSentTo(
            $user,
            GlobalNotification::class,
            function ($notification) {
                return $notification->notification_modal === 'price_update_result'
                    && $notification->price_stats['articulos_actualizados'] === 4
                    && $notification->price_stats['proveedores_cantidad'] === 2;
            }
        );
    }

    /**
     * Criterio: dos disparos seguidos abren DOS corridas, cada una con su contador, y cada
     * una notifica por su cuenta.
     *
     * 🔴 Es el test 6 de la primera vuelta dado vuelta. Cuando la segunda reusaba la corrida
     * de la primera, las dos compartían total_chunks y chunks_encolados: la que terminaba de
     * despachar primero ponía el flag, el finalizador veía el conteo cerrado porque la otra
     * todavía no había sumado sus chunks, y cerraba con números parciales. Nada fallaba.
     *
     * @group precios
     * @test
     */
    public function dos_disparos_seguidos_abren_dos_corridas_y_notifican_cada_una()
    {
        $user = $this->autenticar();

        $this->crear_articulo($user, 'zz Articulo para el test de dos disparos');

        Notification::fake();
        Queue::fake();

        $ids_previos = PriceUpdateRun::where('user_id', $user->id)->pluck('id')->toArray();

        $primero = new ProcessSetFinalPrices($user->id, null, null, false, 'dolar');
        $primero->handle();

        $segundo = new ProcessSetFinalPrices($user->id, null, null, false, 'tipo_de_precio');
        $segundo->handle();

        $nuevas = PriceUpdateRun::where('user_id', $user->id)
            ->whereNotIn('id', $ids_previos)
            ->orderBy('id')
            ->get();

        $this->assertCount(
            2,
            $nuevas,
            'El segundo disparo no abrio su propia corrida: dos productores en la misma fila se pisan los contadores.'
        );

        $this->assertEquals('dolar', $nuevas[0]->origen);
        $this->assertEquals('tipo_de_precio', $nuevas[1]->origen);

        foreach ($nuevas as $corrida) {
            $this->assertGreaterThan(0, (int) $corrida->total_chunks, 'Una de las corridas quedo sin chunks propios.');
            $this->assertEquals(1, (int) $corrida->chunks_encolados);

            /* Cada corrida tiene su propio finalizador encolado. */
            Queue::assertPushed(FinalizeSetFinalPrices::class, function ($job) use ($corrida) {
                return $this->run_id_del_finalizador($job) === (int) $corrida->id;
            });
        }

        /* Los chunks terminan (es lo que hace ProcessChunkSetFinalPrices) y cierra cada una. */
        foreach ($nuevas as $corrida) {
            DB::table('price_update_runs')
                ->where('id', $corrida->id)
                ->update(['processed_chunks' => $corrida->total_chunks]);

            $finalizador = new FinalizeSetFinalPrices($user->id, $corrida->id);
            $finalizador->handle();
        }

        foreach ($nuevas as $corrida) {
            Notification::assertSentTo(
                $user,
                GlobalNotification::class,
                function ($notification) use ($corrida) {
                    return $notification->notification_modal === 'price_update_result'
                        && (int) $notification->price_stats['run_id'] === (int) $corrida->id;
                }
            );
        }
    }

    /**
     * Criterio: un chunk que muere de forma definitiva no deja la corrida colgada en
     * silencio — la cierra en error, guarda por qué, y avisa.
     *
     * Sin esto processed_chunks nunca alcanza a total_chunks, el finalizador se re-despacha
     * cada 10 segundos para siempre y el usuario se queda esperando un modal que no llega.
     *
     * @group precios
     * @test
     */
    public function un_chunk_que_falla_termina_en_notificacion_de_error()
    {
        $user = $this->autenticar();

        $run = $this->crear_corrida_abierta($user->id);

        Notification::fake();

        $chunk = new ProcessChunkSetFinalPrices([1, 2, 3], $user->id, $run->id);
        $chunk->failed(new \Exception('se quedo sin memoria'));

        $run->refresh();

        $this->assertEquals('error', $run->status, 'La corrida quedo abierta despues de que su chunk muriera.');
        $this->assertNotNull($run->error_detalle, 'La corrida en error no dice por que fallo.');
        $this->assertStringContainsString('se quedo sin memoria', $run->error_detalle);
        $this->assertNotNull($run->finished_at);

        Notification::assertSentTo(
            $user,
            GlobalNotification::class,
            function ($notification) {
                return $notification->message_text === 'Error al actualizar Precios'
                    && count($notification->info_to_show) > 0;
            }
        );
    }

    /**
     * Criterio: una corrida que no puede cerrar en 2 horas se cierra en error y avisa, en vez
     * de re-despachar el finalizador cada 10 segundos hasta el fin de los tiempos.
     *
     * @group precios
     * @test
     */
    public function el_finalizador_que_no_puede_cerrar_en_dos_horas_cierra_en_error()
    {
        $user = $this->autenticar();

        $run = $this->crear_corrida_abierta($user->id, [
            'total_chunks'     => 4,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            /* Arrancó hace más de las TOPE_HORAS y le sigue faltando un chunk. */
            'started_at'       => Carbon::now()->subHours(FinalizeSetFinalPrices::TOPE_HORAS + 1),
        ]);

        Notification::fake();
        Queue::fake();

        $job = new FinalizeSetFinalPrices($user->id, $run->id);
        $job->handle();

        $run->refresh();

        $this->assertEquals('error', $run->status, 'La corrida vencida siguio en_proceso.');
        $this->assertNotNull($run->error_detalle, 'La corrida vencida no dice por que se cerro.');
        $this->assertStringContainsString('1 de 4 lotes', $run->error_detalle);

        Queue::assertNotPushed(FinalizeSetFinalPrices::class);

        Notification::assertSentTo(
            $user,
            GlobalNotification::class,
            function ($notification) {
                return $notification->message_text === 'Error al actualizar Precios';
            }
        );
    }

    /**
     * Criterio: el endpoint devuelve 200 y TODOS los nombres de los proveedores de una
     * corrida propia, en orden alfabético.
     *
     * 🔴 Sin este test el único que había era el del 404, y un 404 también es lo que
     * devolvería una ruta mal escrita: la suite podía estar verde con el endpoint muerto.
     *
     * @group precios
     * @test
     */
    public function el_endpoint_devuelve_todos_los_proveedores_de_una_corrida_propia()
    {
        $user = $this->autenticar();

        $proveedor_z = Provider::create(['name' => 'zz Zeta proveedor', 'user_id' => $user->id]);
        $proveedor_a = Provider::create(['name' => 'zz Alfa proveedor', 'user_id' => $user->id]);

        $run = $this->crear_corrida_abierta($user->id, [
            'status'           => 'terminado',
            'processed_chunks' => 1,
            'articles_updated' => 3,
        ]);

        /* Acá sí se siembran las filas a mano: lo que se prueba es el endpoint, no la
           comparación de precios (eso lo prueba el test que corre el chunk). */
        $filas = [];

        foreach ([$proveedor_z->id, $proveedor_a->id] as $provider_id) {
            $articulo = $this->crear_articulo($user, 'zz Articulo endpoint ' . $provider_id, $provider_id);
            $filas[] = ['price_update_run_id' => $run->id, 'article_id' => $articulo->id];
        }

        /* Uno sin proveedor: es un grupo más y tiene que aparecer, no desaparecer. */
        $sin_proveedor = $this->crear_articulo($user, 'zz Articulo endpoint sin proveedor');
        $filas[] = ['price_update_run_id' => $run->id, 'article_id' => $sin_proveedor->id];

        DB::table('price_update_run_articles')->insertOrIgnore($filas);

        $response = $this->getJson('api/price-update-run/' . $run->id . '/desglose');

        $response->assertStatus(200);

        $this->assertEquals(
            ['Sin proveedor', 'zz Alfa proveedor', 'zz Zeta proveedor'],
            $response->json('proveedores')
        );
    }

    /**
     * Criterio: el desglose de una corrida de otro usuario no devuelve datos. Es
     * multi-tenant: una corrida ajena filtra la composición del catálogo de otro comercio.
     *
     * @group precios
     * @test
     */
    public function el_desglose_de_una_corrida_ajena_no_devuelve_datos()
    {
        $this->autenticar();

        /* user_id de otro: no hace falta que exista, lo que se prueba es el filtro. */
        $run_ajena = $this->crear_corrida_abierta(999999, [
            'status'           => 'terminado',
            'processed_chunks' => 1,
            'articles_updated' => 5,
        ]);

        $response = $this->getJson('api/price-update-run/' . $run_ajena->id . '/desglose');

        $response->assertStatus(404);
    }

    /**
     * Criterio: el payload entra en el límite de Pusher.
     *
     * 🔴 No es un test formal. Pusher rechaza los mensajes de más de 10 KB, y cuando eso
     * pasa la notificación NO LLEGA y no falla nada visible: el usuario no ve el modal y
     * nadie se entera.
     *
     * Se mide sobre lo que sale de toBroadcast() y no sobre build_price_stats(), que es lo
     * que medía antes: entre los dos había ~325 bytes de diferencia, o sea que el test no
     * medía lo que decía medir. Y se mide en BYTES, que es como cuenta Pusher.
     *
     * @group precios
     * @test
     */
    public function el_payload_entra_en_el_limite_de_pusher()
    {
        $user = $this->autenticar();

        /* Peor caso realista: el detalle del origen al tope de la columna, todo acentuado. */
        $run = $this->crear_corrida_abierta($user->id, [
            'status'           => 'terminado',
            'processed_chunks' => 1,
            'articles_updated' => 12345,
            'origen_detalle'   => str_repeat('á', 255),
            'stats_json'       => json_encode(['proveedores' => []]),
        ]);

        Notification::fake();

        SetFinalPricesNotificationHelper::notify_prices_updated($user->id, $run);

        Notification::assertSentTo(
            $user,
            GlobalNotification::class,
            function ($notification) use ($user) {
                $bytes = strlen(json_encode($notification->toBroadcast($user)->data));

                $this->assertLessThan(
                    2048,
                    $bytes,
                    'El payload del broadcast se paso del margen: con Pusher, la notificacion no llegaria y no fallaria nada.'
                );

                return true;
            }
        );
    }

    /**
     * El id de corrida con el que se encoló un finalizador, que es una propiedad protegida.
     *
     * @param  \App\Jobs\FinalizeSetFinalPrices $job
     * @return int
     */
    protected function run_id_del_finalizador($job)
    {
        $propiedad = new \ReflectionProperty(FinalizeSetFinalPrices::class, 'price_update_run_id');
        $propiedad->setAccessible(true);

        return (int) $propiedad->getValue($job);
    }
}
