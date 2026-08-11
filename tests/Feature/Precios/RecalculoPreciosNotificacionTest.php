<?php

namespace Tests\Feature\Precios;

use App\Http\Controllers\Helpers\SetFinalPricesNotificationHelper;
use App\Jobs\FinalizeSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\Article;
use App\Models\Category;
use App\Models\PriceUpdateRun;
use App\Models\Provider;
use App\Models\User;
use App\Notifications\GlobalNotification;
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
 *  - que "artículos afectados" sean los que efectivamente cambiaron de precio y no los
 *    procesados (sin eso el desglose no dice nada: un cambio de dólar listaría todos los
 *    proveedores con su catálogo entero);
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
     * Corrida ya cerrada con el desglose indicado, sin pasar por los jobs.
     *
     * @param  int   $user_id
     * @param  array $proveedores  [['nombre' => ..., 'cantidad' => ...], ...]
     * @param  array $categorias
     * @return \App\Models\PriceUpdateRun
     */
    protected function crear_corrida_cerrada($user_id, $proveedores, $categorias)
    {
        $total = 0;

        foreach ($proveedores as $grupo) {
            $total += $grupo['cantidad'];
        }

        return PriceUpdateRun::create([
            'user_id'          => $user_id,
            'origen'           => 'dolar',
            'status'           => 'terminado',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => $total,
            'stats_json'       => json_encode([
                'proveedores' => $proveedores,
                'categorias'  => $categorias,
            ]),
        ]);
    }

    /**
     * Criterio: el aviso llega al FINAL, no al encolar. Es el test que protege el bug
     * corregido: con la cola frenada, despachar ProcessSetFinalPrices no puede haber
     * notificado nada.
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
        Article::create([
            'name'    => 'zz Articulo para el test de no notificar al encolar',
            'user_id' => $user->id,
        ]);

        Notification::fake();
        Queue::fake();

        $job = new ProcessSetFinalPrices($user->id, null, null, false, 'dolar');
        $job->handle();

        Notification::assertNothingSent();
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

        $run = PriceUpdateRun::create([
            'user_id'          => $user->id,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 2,
            'processed_chunks' => 2,
            /* El conteo cierra pero el bucle todavía no termino de despachar. */
            'chunks_encolados' => 0,
            'articles_updated' => 0,
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
     * Criterio: sólo cuentan los artículos cuyo final_price cambió de verdad, con su
     * desglose por proveedor.
     *
     * @group precios
     * @test
     */
    public function el_desglose_cuenta_solo_los_articulos_que_cambiaron_de_precio()
    {
        $user = $this->autenticar();

        $proveedor_a = Provider::create(['name' => 'zz Proveedor A precios', 'user_id' => $user->id]);
        $proveedor_b = Provider::create(['name' => 'zz Proveedor B precios', 'user_id' => $user->id]);

        $run = PriceUpdateRun::create([
            'user_id'          => $user->id,
            'origen'           => 'dolar',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
        ]);

        /*
         * Se registran 3 artículos del proveedor A y 1 del B. El quinto artículo (el otro
         * del B) NO se registra a propósito: representa al que no cambió de precio, y es la
         * aserción que prueba que se cuentan los que cambiaron y no los procesados.
         */
        $ids = [];

        for ($i = 1; $i <= 3; $i++) {
            $articulo = Article::create([
                'name'        => 'zz Articulo A ' . $i,
                'user_id'     => $user->id,
                'provider_id' => $proveedor_a->id,
            ]);
            $ids[] = $articulo->id;
        }

        $articulo_b = Article::create([
            'name'        => 'zz Articulo B 1',
            'user_id'     => $user->id,
            'provider_id' => $proveedor_b->id,
        ]);
        $ids[] = $articulo_b->id;

        /* Este es el que no cambio: se crea pero NO se registra en la corrida. */
        Article::create([
            'name'        => 'zz Articulo B 2 sin cambio',
            'user_id'     => $user->id,
            'provider_id' => $proveedor_b->id,
        ]);

        $filas = [];
        foreach ($ids as $article_id) {
            $filas[] = ['price_update_run_id' => $run->id, 'article_id' => $article_id];
        }
        DB::table('price_update_run_articles')->insertOrIgnore($filas);

        Notification::fake();

        $job = new FinalizeSetFinalPrices($user->id, $run->id);
        $job->handle();

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
                    && $notification->price_stats['articulos_actualizados'] === 4;
            }
        );
    }

    /**
     * Criterio: con más grupos que el tope, el payload lleva los 8 más grandes y resume el
     * resto en dos números distintos (artículos y grupos).
     *
     * @group precios
     * @test
     */
    public function el_payload_lleva_el_top_y_resume_el_resto()
    {
        $user = $this->autenticar();

        $proveedores = [];
        $cantidad = 12;

        for ($i = 1; $i <= 12; $i++) {
            $proveedores[] = ['nombre' => 'zz Proveedor ' . $i, 'cantidad' => $cantidad];
            $cantidad--;
        }

        $run = $this->crear_corrida_cerrada($user->id, $proveedores, []);

        $stats = SetFinalPricesNotificationHelper::build_price_stats($run);

        $this->assertCount(8, $stats['proveedores']['items']);
        $this->assertEquals(4, $stats['proveedores']['otros_grupos'], 'Los grupos que quedaron fuera del top no coinciden.');
        /* Los que quedan fuera son los de cantidad 4, 3, 2 y 1. */
        $this->assertEquals(10, $stats['proveedores']['otros_cantidad']);
        $this->assertEquals(12, $stats['proveedores']['total_grupos']);
    }

    /**
     * Criterio: el payload entra en el límite de Pusher.
     *
     * 🔴 No es un test formal. Pusher rechaza los mensajes de más de 10 KB, y cuando eso
     * pasa la notificación NO LLEGA y no falla nada visible: el usuario no ve el modal y
     * nadie se entera. Por eso se mide con margen (6 KB).
     *
     * @group precios
     * @test
     */
    public function el_payload_entra_en_el_limite_de_pusher()
    {
        $user = $this->autenticar();

        $proveedores = [];
        $categorias  = [];

        for ($i = 1; $i <= 12; $i++) {
            /* Nombres largos a propósito: es el peor caso realista. */
            $nombre = 'zz Proveedor con nombre bastante largo para el peor caso ' . $i;
            $proveedores[] = ['nombre' => $nombre, 'cantidad' => 100 + $i];
            $categorias[]  = ['nombre' => 'zz Categoria con nombre igualmente largo ' . $i, 'cantidad' => 50 + $i];
        }

        $run = $this->crear_corrida_cerrada($user->id, $proveedores, $categorias);

        $payload = SetFinalPricesNotificationHelper::build_price_stats($run);

        $this->assertLessThan(
            6144,
            strlen(json_encode($payload)),
            'El payload del broadcast se paso del margen: con Pusher, la notificacion no llegaria y no fallaria nada.'
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
        $user = $this->autenticar();

        /* user_id de otro: no hace falta que exista, lo que se prueba es el filtro. */
        $run_ajena = PriceUpdateRun::create([
            'user_id'          => 999999,
            'origen'           => 'dolar',
            'status'           => 'terminado',
            'total_chunks'     => 1,
            'processed_chunks' => 1,
            'chunks_encolados' => 1,
            'articles_updated' => 5,
            'stats_json'       => json_encode(['proveedores' => [], 'categorias' => []]),
        ]);

        $response = $this->getJson('api/price-update-run/' . $run_ajena->id . '/desglose?tipo=provider');

        $response->assertStatus(404);
    }

    /**
     * Criterio: dos disparos seguidos con una corrida abierta reusan la misma, para que el
     * usuario no reciba dos modales con números casi iguales.
     *
     * @group precios
     * @test
     */
    public function dos_disparos_seguidos_reusan_la_misma_corrida()
    {
        $user = $this->autenticar();

        Notification::fake();
        Queue::fake();

        $antes = PriceUpdateRun::where('user_id', $user->id)->count();

        $primero = new ProcessSetFinalPrices($user->id, null, null, false, 'dolar');
        $primero->handle();

        $segundo = new ProcessSetFinalPrices($user->id, null, null, false, 'tipo_de_precio');
        $segundo->handle();

        $despues = PriceUpdateRun::where('user_id', $user->id)->count();

        $this->assertEquals(
            $antes + 1,
            $despues,
            'El segundo disparo abrio una corrida nueva en vez de reusar la que ya estaba abierta.'
        );
    }
}
