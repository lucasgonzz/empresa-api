<?php

namespace Tests\Feature\Precios\DescuentosYMasivasEnLote;

use App\Http\Controllers\Helpers\article\ArticleProviderDiscountHelper;
use App\Jobs\ProcessPropagarDescuentosProveedorJob;
use App\Models\BackgroundProcess;
use App\Models\Provider;
use App\Models\ProviderDiscount;
use App\Models\User;
use App\Notifications\GlobalNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Mision `recalculo-precios-motor-rapido`, seguimiento del 29/9/2026 — la propagacion de descuentos
 * del proveedor (`PUT provider/{id}/propagar-descuentos`) pasa a SEGUNDO PLANO cuando son muchos
 * articulos (mas que una tanda del motor).
 *
 * 🔴 LO QUE FIJA ESTE ARCHIVO:
 *
 *   1. Con pocos articulos, TODO igual que antes: sincronico y la misma respuesta, sin ninguna clave
 *      nueva (la SPA vieja y la nueva la leen igual).
 *   2. El umbral es exactamente una tanda del motor: con N = tanda va en el request, con N > tanda
 *      va a la cola.
 *   3. Con muchos, el request NO toca descuentos ni precios: encola UN job con los datos correctos,
 *      abre el registro visible en `pendiente` y responde `en_segundo_plano: true`.
 *   4. Correr el job deja la base EXACTAMENTE igual que la propagacion en el request de develop
 *      (propagar_como_hoy(), copiada textual en la base de estos tests), price_changes a nombre de
 *      la persona que confirmo incluidos, aunque en el worker no haya sesion. Y el registro visible
 *      cierra con el total REAL y los procesados.
 *   5. Un proveedor ajeno o inexistente no toca nada; failed() cierra el registro y avisa una vez.
 *   6. La clasificacion ya no hidrata modelos Eloquent (toBase()).
 *
 * Los numeros son la especificacion. 🔴 Esta prohibido ajustar un valor esperado para que coincida
 * con lo que devuelve el sistema: si un test queda en rojo, se corrige el codigo.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union types,
 * promocion de constructor, readonly, enum ni #[...].
 *
 * @group costeo-precios
 */
class Propagacion_en_segundo_plano_Test extends DescuentosYMasivasEnLoteTestCase
{
    /**
     * Estado del registro visible capturado ADENTRO del savepoint del camino nuevo (despues el
     * rollback lo borra).
     *
     * @var array|null
     */
    private $registro_capturado = null;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        /* Los avisos del job salen por Pusher; aca se cuentan, no se mandan. */
        Notification::fake();
    }

    /* ------------------------------------------------------------------------------------------
     * Armado
     * ---------------------------------------------------------------------------------------- */

    /**
     * Empleado del comercio: es quien confirma la ventana en los tests (para separar el dueño de la
     * persona que queda en los price_changes y en el aviso).
     *
     * @param  \App\Models\User $dueno
     * @return \App\Models\User
     */
    private function empleado($dueno)
    {
        return User::create([
            'name'         => 'zz Empleado propagacion',
            'company_name' => 'zz Comercio recalculo en lote',
            'email'        => 'propagacion-empleado-' . uniqid('', true) . '@test.local',
            'password'     => Hash::make('secret'),
            'owner_id'     => $dueno->id,
            'admin_access' => 1,
        ]);
    }

    /**
     * Comercio con la preferencia prendida, un proveedor con la ficha en 15% y 5%, `$desactualizados`
     * articulos con la copia vieja (10%), uno editado a mano (se respeta) y uno al dia.
     *
     * Casos raros adentro de los desactualizados, para que la equivalencia los recorra: uno con el
     * "mostrar en la tienda" prendido y uno con un descuento de compra que se conserva.
     *
     * @param  int $desactualizados
     * @return array
     */
    private function escenario($desactualizados)
    {
        $dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $provider = $this->crear_proveedor($dueno, ['percentage_gain' => 30]);

        $this->descuento_de_la_ficha($provider, 15, 'Bonif general');
        $this->descuento_de_la_ficha($provider, 5, 'Pronto pago');

        $ids = [];

        for ($i = 1; $i <= $desactualizados; $i++) {

            $article = $this->crear_articulo($dueno, ['cost' => 1000 + $i * 37.5, 'provider_id' => $provider->id]);

            if ($i === 2) {
                $this->descuento_de_compra($article, $provider, null, 80);
            }

            $this->copia_de_la_ficha($article, $provider, 10, ['show_in_online' => $i === 1 ? 1 : 0]);

            $ids[] = $article->id;
        }

        $editado = $this->crear_articulo($dueno, ['cost' => 900, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($editado, $provider, 12, ['editado_a_mano' => 1]);

        $al_dia = $this->crear_articulo($dueno, ['cost' => 800, 'provider_id' => $provider->id]);
        $this->copia_de_la_ficha($al_dia, $provider, 15);
        $this->copia_de_la_ficha($al_dia, $provider, 5);

        $todos = array_merge($ids, [$editado->id, $al_dia->id]);

        $this->calentar($todos, $dueno->id);

        return [
            'dueno'           => $dueno,
            'provider'        => $provider,
            'desactualizados' => $ids,
            'editado'         => $editado->id,
            'ids'             => $todos,
        ];
    }

    /**
     * @param  \App\Models\Provider $provider
     * @param  array $payload
     * @return \Illuminate\Testing\TestResponse
     */
    private function propagar($provider, array $payload = [])
    {
        return $this->putJson('api/provider/' . $provider->id . '/propagar-descuentos', $payload);
    }

    /**
     * Una propiedad protegida del job encolado (el job no expone getters, y el test no necesita que
     * los tenga).
     *
     * @param  object $job
     * @param  string $propiedad
     * @return mixed
     */
    private function leer($job, $propiedad)
    {
        $lector = \Closure::bind(function () use ($propiedad) {
            return $this->{$propiedad};
        }, $job, get_class($job));

        return $lector();
    }

    /**
     * El ultimo registro visible de propagacion del dueño.
     *
     * @param  \App\Models\User $dueno
     * @return \App\Models\BackgroundProcess|null
     */
    private function ultimo_registro($dueno)
    {
        return BackgroundProcess::where('user_id', $dueno->id)
                                    ->where('tipo', 'sincronizar_descuentos')
                                    ->orderBy('id', 'DESC')
                                    ->first();
    }

    /**
     * Sin sesion, como en el worker. Despues de un request el guard queda en sanctum y hay que
     * volver a `web` para poder cerrarla (clase de error ya fijada en la suite de procesos).
     *
     * @return void
     */
    private function sin_sesion()
    {
        Auth::shouldUse('web');
        Auth::logout();
    }

    /* ------------------------------------------------------------------------------------------
     * 1 y 2. El camino chico y el umbral
     * ---------------------------------------------------------------------------------------- */

    /**
     * Con pocos articulos, todo como antes: se propaga en el request, la respuesta es exactamente
     * `{actualizados, respetados}` y no se encola nada.
     *
     * @test
     */
    public function con_pocos_articulos_propaga_en_el_request_y_responde_como_siempre()
    {
        Queue::fake();

        $e = $this->escenario(3);

        $response = $this->propagar($e['provider']);

        $response->assertStatus(200);
        $response->assertExactJson(['actualizados' => 3, 'respetados' => 1]);

        Queue::assertNotPushed(ProcessPropagarDescuentosProveedorJob::class);

        foreach ($e['desactualizados'] as $id) {
            $de_la_ficha = DB::table('article_discounts')->where('article_id', $id)->where('origen', 'ficha_proveedor')->orderBy('id')->pluck('percentage')->all();
            $this->assertEquals(['15.00', '5.00'], $de_la_ficha, 'Se rehizo en el request, como siempre.');
        }

        $this->assertNull($this->ultimo_registro($e['dueno']), 'En el request no se abre ningun registro visible.');
    }

    /**
     * El umbral es EXACTAMENTE una tanda del motor (3 en este test): con 3 articulos a actualizar va
     * en el request; con 4, a la cola.
     *
     * @test
     */
    public function el_umbral_es_una_tanda_del_motor()
    {
        Queue::fake();

        config(['app.RECALCULO_PRECIOS_LOTE' => 3]);

        $e = $this->escenario(3);

        $this->propagar($e['provider'])->assertExactJson(['actualizados' => 3, 'respetados' => 1]);

        Queue::assertNotPushed(ProcessPropagarDescuentosProveedorJob::class);

        /*
         * El proveedor renegocia otra vez (15 -> 17): los tres que se acaban de actualizar Y el que
         * estaba al dia quedan desactualizados. Son 4, uno mas que la tanda.
         */
        ProviderDiscount::where('provider_id', $e['provider']->id)->where('percentage', 15)->update(['percentage' => 17]);

        $this->propagar($e['provider'])->assertExactJson([
            'actualizados'     => 4,
            'respetados'       => 1,
            'en_segundo_plano' => true,
        ]);

        Queue::assertPushed(ProcessPropagarDescuentosProveedorJob::class, 1);
    }

    /* ------------------------------------------------------------------------------------------
     * 3. El camino grande: el request solo encola
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 Con mas articulos que una tanda, el request no toca NI un descuento NI un precio: encola un
     * job con el dueño, la persona y el tilde, abre el registro visible en `pendiente` con el total,
     * y responde de inmediato con `en_segundo_plano`.
     *
     * Lo confirma un EMPLEADO: el dueño va para el job y el registro, la persona para el aviso.
     *
     * @test
     */
    public function con_muchos_articulos_el_request_solo_encola_y_responde_en_segundo_plano()
    {
        Queue::fake();

        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $e = $this->escenario(5);

        $empleado = $this->empleado($e['dueno']);

        $this->actingAs($empleado, 'web');

        $marca = (int) DB::table('price_changes')->max('id');

        $antes = $this->foto_completa($e['ids'], $marca);

        $response = $this->propagar($e['provider'], ['pisar_editados_a_mano' => false]);

        $response->assertStatus(200);
        $response->assertExactJson([
            'actualizados'     => 5,
            'respetados'       => 1,
            'en_segundo_plano' => true,
        ]);

        $this->assertEquals(
            $antes,
            $this->foto_completa($e['ids'], $marca),
            'El request no puede tocar ni descuentos ni precios: eso lo hace el job.'
        );

        $registro = $this->ultimo_registro($e['dueno']);

        $this->assertNotNull($registro, 'El registro visible nace al encolar.');
        $this->assertSame(BackgroundProcess::STATUS_PENDIENTE, $registro->status);
        $this->assertSame('Actualización de descuentos de ' . $e['provider']->name, $registro->titulo);
        $this->assertSame('artículos', $registro->unidad);
        $this->assertSame(5, (int) $registro->total);
        $this->assertSame(0, (int) $registro->procesados);
        $this->assertSame((int) $empleado->id, (int) $registro->auth_user_id);
        $this->assertNull($registro->referencia_type, 'Sin referencia al proveedor: viaja por id (ver el docblock del job).');

        Queue::assertPushed(ProcessPropagarDescuentosProveedorJob::class, 1);

        Queue::assertPushed(ProcessPropagarDescuentosProveedorJob::class, function ($job) use ($e, $empleado, $registro) {
            return $this->leer($job, 'provider_id') === (int) $e['provider']->id
                && $this->leer($job, 'owner_user_id') === (int) $e['dueno']->id
                && $this->leer($job, 'auth_user_id') === (int) $empleado->id
                && $this->leer($job, 'pisar_editados_a_mano') === false
                && $job->background_process_id === (int) $registro->id;
        });
    }

    /* ------------------------------------------------------------------------------------------
     * 4. El job: equivalencia con la propagacion en el request, y el registro
     * ---------------------------------------------------------------------------------------- */

    /**
     * 🔴 Correr el job (sin sesion, como en el worker) deja la base EXACTAMENTE igual que la
     * propagacion en el request de develop hecha por la misma persona: descuentos (orden y "mostrar
     * en la tienda" incluidos), precios, pivots y price_changes con el employee_id de quien confirmo.
     *
     * Y el registro visible: se anuncia con un total VIEJO a proposito (7, como si el catalogo
     * hubiera cambiado entre el click y el worker) y tiene que cerrar con el total REAL (5 de 5):
     * es lo que prueba que el avance del job viene de la clasificacion que hace el propio job.
     *
     * @test
     */
    public function el_job_deja_la_base_igual_que_la_propagacion_en_el_request()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $e = $this->escenario(5);

        $empleado = $this->empleado($e['dueno']);

        $this->actingAs($empleado, 'web');

        $provider_id = $e['provider']->id;
        $dueno_id    = $e['dueno']->id;

        $this->registro_capturado = null;

        $r = $this->comparar_caminos_de(
            $e['ids'],
            function () use ($provider_id) {
                /* La referencia: la propagacion de develop, en el request, con la persona logueada. */
                return $this->propagar_como_hoy(Provider::find($provider_id), false);
            },
            function () use ($provider_id, $dueno_id, $empleado) {

                $id = ProcessPropagarDescuentosProveedorJob::anunciar(Provider::find($provider_id), $dueno_id, $empleado->id, false, 7);

                $this->sin_sesion();

                (new ProcessPropagarDescuentosProveedorJob($provider_id, $dueno_id, $empleado->id, false, 'op-' . uniqid('', true), $id))->handle();

                $this->registro_capturado = BackgroundProcess::find($id)->toArray();

                $resultado = BackgroundProcess::find($id)->resultado();

                return [
                    'actualizados' => (int) $resultado['actualizados'],
                    'respetados'   => (int) $resultado['respetados'],
                ];
            },
            'Job contra la propagacion en el request'
        );

        $this->assertSame(['actualizados' => 5, 'respetados' => 1], $r['nuevo']);

        foreach ($r['foto']['cambios'] as $cambios) {
            foreach ($cambios as $cambio) {
                $this->assertSame((int) $empleado->id, (int) $cambio['employee_id'], 'Los price_changes quedan a nombre de quien confirmo, aunque en el worker no haya sesion.');
            }
        }

        $this->assertNotNull($this->registro_capturado);
        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $this->registro_capturado['status']);
        $this->assertSame(5, (int) $this->registro_capturado['total'], 'El total es el de la clasificacion del job, no el anunciado.');
        $this->assertSame(5, (int) $this->registro_capturado['procesados']);
        $this->assertSame(100, (int) $this->registro_capturado['porcentaje']);

        Notification::assertSentTo(User::find($dueno_id), GlobalNotification::class);
    }

    /**
     * El avance que da la propagacion: el total al arrancar y uno por tanda escrita, acumulando.
     *
     * @test
     */
    public function la_propagacion_informa_el_avance_por_tanda()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $e = $this->escenario(5);

        $avisos = [];

        ArticleProviderDiscountHelper::propagar_a_articulos(
            Provider::find($e['provider']->id),
            false,
            null,
            function ($procesados, $total) use (&$avisos) {
                $avisos[] = [$procesados, $total];
            }
        );

        $this->assertSame([[0, 5], [2, 5], [4, 5], [5, 5]], $avisos);
    }

    /* ------------------------------------------------------------------------------------------
     * 5. Ajenos, inexistentes y failed()
     * ---------------------------------------------------------------------------------------- */

    /**
     * Un job con el proveedor de OTRO comercio, o con un proveedor que no existe, no toca nada y
     * cierra su registro en fallo. Y el endpoint no deja encolar sobre un proveedor ajeno.
     *
     * @test
     */
    public function un_proveedor_ajeno_o_inexistente_no_hace_nada()
    {
        config(['app.RECALCULO_PRECIOS_LOTE' => 2]);

        $e = $this->escenario(5);

        $otro_dueno = $this->crear_dueno(['aplicar_descuentos_proveedor_al_asignar' => 1]);

        $marca = (int) DB::table('price_changes')->max('id');

        $antes = $this->foto_completa($e['ids'], $marca);

        /* Por el endpoint, logueado como el otro comercio: 404 y nada encolado. */
        Queue::fake();

        $this->propagar($e['provider'])->assertStatus(404);

        Queue::assertNotPushed(ProcessPropagarDescuentosProveedorJob::class);

        /* El job, con el dueño equivocado. */
        $id_ajeno = ProcessPropagarDescuentosProveedorJob::anunciar($e['provider'], $otro_dueno->id, $otro_dueno->id, true, 5);

        $this->sin_sesion();

        (new ProcessPropagarDescuentosProveedorJob($e['provider']->id, $otro_dueno->id, $otro_dueno->id, true, 'op-' . uniqid('', true), $id_ajeno))->handle();

        /* Y con un proveedor que no existe. */
        $inexistente = (int) DB::table('providers')->max('id') + 1000;

        $id_inexistente = ProcessPropagarDescuentosProveedorJob::anunciar($e['provider'], $e['dueno']->id, $e['dueno']->id, true, 5);

        (new ProcessPropagarDescuentosProveedorJob($inexistente, $e['dueno']->id, $e['dueno']->id, true, 'op-' . uniqid('', true), $id_inexistente))->handle();

        $this->assertEquals($antes, $this->foto_completa($e['ids'], $marca), 'Ni el proveedor ajeno ni el inexistente pueden tocar nada.');

        foreach ([$id_ajeno, $id_inexistente] as $id) {
            $registro = BackgroundProcess::find($id);
            $this->assertSame(BackgroundProcess::STATUS_FALLO, $registro->status, 'El registro que nacio al encolar no queda en pendiente.');
        }
    }

    /**
     * failed() (proceso muerto sin pasar por el catch) cierra el registro en fallo con el motivo, y
     * aunque corra dos veces la persona recibe UN solo aviso.
     *
     * @test
     */
    public function failed_cierra_el_registro_y_avisa_una_sola_vez()
    {
        $e = $this->escenario(1);

        $empleado = $this->empleado($e['dueno']);

        $id = ProcessPropagarDescuentosProveedorJob::anunciar($e['provider'], $e['dueno']->id, $empleado->id, false, 5);

        $operacion = 'op-' . uniqid('', true);

        (new ProcessPropagarDescuentosProveedorJob($e['provider']->id, $e['dueno']->id, $empleado->id, false, $operacion, $id))
            ->failed(new \Exception('se quedó sin memoria'));

        (new ProcessPropagarDescuentosProveedorJob($e['provider']->id, $e['dueno']->id, $empleado->id, false, $operacion, $id))
            ->failed(new \Exception('otro motivo'));

        $registro = BackgroundProcess::find($id);

        $this->assertSame(BackgroundProcess::STATUS_FALLO, $registro->status);
        $this->assertSame('se quedó sin memoria', $registro->error_message, 'La segunda llamada no pisa el cierre.');

        Notification::assertSentToTimes(User::find($e['dueno']->id), GlobalNotification::class, 1);
    }

    /**
     * Cola 'excel' en el shared y 'default' en el VPS, un intento y el tope de una hora.
     *
     * @test
     */
    public function la_cola_y_los_topes_son_los_de_los_jobs_pesados()
    {
        config(['app.VPS' => false]);

        $en_el_shared = new ProcessPropagarDescuentosProveedorJob(1, 1, 1, false, 'op-test');

        $this->assertSame('excel', $en_el_shared->queue);
        $this->assertSame(3600, $en_el_shared->timeout);
        $this->assertSame(1, $en_el_shared->tries);

        config(['app.VPS' => true]);

        $this->assertNull((new ProcessPropagarDescuentosProveedorJob(1, 1, 1, false, 'op-test'))->queue);
    }

    /* ------------------------------------------------------------------------------------------
     * 6. Sin hidratar modelos
     * ---------------------------------------------------------------------------------------- */

    /**
     * La clasificacion del preview y de la propagacion lee las filas crudas (toBase()): ni un solo
     * modelo ArticleDiscount hidratado, con los mismos resultados.
     *
     * @test
     */
    public function la_clasificacion_no_hidrata_modelos_de_descuentos()
    {
        $e = $this->escenario(3);

        $hidratados = 0;

        Event::listen('eloquent.retrieved: ' . \App\Models\ArticleDiscount::class, function () use (&$hidratados) {
            $hidratados++;
        });

        $provider = Provider::find($e['provider']->id);

        $preview = ArticleProviderDiscountHelper::preview_propagacion($provider);
        $plan    = ArticleProviderDiscountHelper::planificar_propagacion($provider);

        $this->assertSame(0, $hidratados, 'La clasificacion no puede hidratar modelos: en un proveedor grande son decenas de miles.');

        $this->assertSame(3, $preview['desactualizados']);
        $this->assertSame(1, $preview['editados_a_mano']);
        $this->assertCount(3, $plan['items']);
        $this->assertSame(1, $plan['respetados']);
    }
}
