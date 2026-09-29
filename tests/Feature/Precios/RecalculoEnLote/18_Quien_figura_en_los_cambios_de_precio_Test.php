<?php

namespace Tests\Feature\Precios\RecalculoEnLote;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\PriceTypeHelper;
use App\Jobs\ProcessChunkSetFinalPrices;
use App\Jobs\ProcessSetFinalPrices;
use App\Models\PriceUpdateRun;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

/**
 * Quién figura en los price_changes de un recálculo en segundo plano (misión
 * recalculo-precios-motor-rapido, seguimiento del 29/9/2026).
 *
 * En el worker no hay sesión, así que todo recálculo encolado dejaba employee_id =
 * config('app.USER_ID'). Con esta misión, el recálculo por el guardado de una categoría y el de los
 * recargos de una lista pasaron del request a la cola y perdían a la persona que los disparó. Ahora
 * quien encola (ProcessSetFinalPrices al construirse, PriceTypeHelper::
 * dispatch_recalculate_for_articles() en el request) resuelve a la persona y la pasa a cada lote.
 *
 * Cada test separa los dos momentos como en producción: se ENCOLA con la sesión del empleado y se
 * CORRE sin sesión (se olvidan los guards), con el job ida y vuelta por serialize(), que es lo que
 * hace la cola. Si el dato no viajara en el job, el motor caería en config('app.USER_ID').
 *
 * Y la compatibilidad: un lote o un productor encolados antes de este cambio llegan sin la
 * propiedad nueva y tienen que andar igual, como hoy.
 *
 * IMPORTANTE (PHP 7.4): no usar match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group recalculo-en-lote
 */
class Quien_figura_en_los_cambios_de_precio_Test extends RecalculoEnLoteTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame(
            'sync',
            config('queue.connections.' . config('queue.default') . '.driver'),
            'Este test necesita la cola inline para correr el recálculo de punta a punta.'
        );

        $this->assertNotNull(config('app.USER_ID'), 'Precondición: sin sesión, el motor registra config(\'app.USER_ID\'); tiene que estar definido.');

        Notification::fake();
        Event::fake([BackgroundProcessUpdated::class]);
    }

    /**
     * Encolado por un empleado logueado, corrido sin sesión: los price_changes a nombre del
     * empleado (la persona, no el dueño).
     *
     * @return void
     */
    public function test_un_recalculo_disparado_por_un_empleado_deja_sus_cambios_a_nombre_del_empleado()
    {
        $dueno     = $this->crear_dueno();
        $empleado  = $this->crear_empleado($dueno);
        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);
        $ids       = $this->articulos_con_precio_viejo($dueno, ['provider_id' => $proveedor->id], 3);

        $ultimo = $this->ultimo_cambio_de_precio();

        /* En el request: la persona logueada es el empleado. */
        $this->actingAs(User::find($empleado->id), 'web');

        $productor = new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name);

        $this->assertSame((int) $empleado->id, $productor->auth_user_id, 'El productor no guardó a la persona que lo encoló.');

        /* En el worker: sin sesión. La cola sync serializa el job, lo deserializa y lo corre. */
        $this->olvidar_la_sesion();

        dispatch($productor);

        $this->assertCambiosDePrecioANombreDe($ids, $ultimo, (int) $empleado->id);
    }

    /**
     * Sin sesión al encolar (la cotización automática del dólar, un comando): como hoy.
     *
     * @return void
     */
    public function test_un_recalculo_sin_sesion_deja_los_cambios_como_hoy()
    {
        $dueno     = $this->crear_dueno();
        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);
        $ids       = $this->articulos_con_precio_viejo($dueno, ['provider_id' => $proveedor->id], 3);

        $ultimo = $this->ultimo_cambio_de_precio();

        /* crear_dueno() deja la sesión del dueño: un comando no tiene ninguna, desde el principio. */
        $this->olvidar_la_sesion();

        $productor = new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name);

        $this->assertNull($productor->auth_user_id);

        dispatch($productor);

        $this->assertCambiosDePrecioANombreDe($ids, $ultimo, (int) config('app.USER_ID'));
    }

    /**
     * Los lotes que despacha una lista de precios directo en el request
     * (PriceTypeHelper::dispatch_recalculate_for_articles()) también llevan a la persona.
     *
     * @return void
     */
    public function test_los_lotes_que_despacha_una_lista_en_el_request_llevan_a_la_persona_logueada()
    {
        $dueno    = $this->crear_dueno();
        $empleado = $this->crear_empleado($dueno);
        $ids      = $this->articulos_con_precio_viejo($dueno, [], 3);

        $ultimo = $this->ultimo_cambio_de_precio();

        $this->actingAs(User::find($empleado->id), 'web');

        Queue::fake();

        PriceTypeHelper::dispatch_recalculate_for_articles($ids, $dueno->id);

        $lotes = Queue::pushed(ProcessChunkSetFinalPrices::class);

        $this->assertCount(1, $lotes, 'Precondición: tres artículos son un solo lote.');

        /* En el worker: sin sesión, y cada lote ida y vuelta por serialize(). */
        $this->olvidar_la_sesion();

        foreach ($lotes as $lote) {
            unserialize(serialize($lote))->handle();
        }

        $this->assertCambiosDePrecioANombreDe($ids, $ultimo, (int) $empleado->id);
    }

    /**
     * Un lote encolado antes de este cambio (payload sin auth_user_id) anda igual que hoy.
     *
     * @return void
     */
    public function test_un_lote_encolado_sin_la_propiedad_nueva_sigue_andando()
    {
        $dueno = $this->crear_dueno();
        $ids   = $this->articulos_con_precio_viejo($dueno, [], 2);

        $run = PriceUpdateRun::create([
            'user_id'          => $dueno->id,
            'origen'           => 'otro',
            'status'           => 'en_proceso',
            'total_chunks'     => 1,
            'processed_chunks' => 0,
            'chunks_encolados' => 1,
            'articles_updated' => 0,
            'started_at'       => Carbon::now(),
        ]);

        $ultimo = $this->ultimo_cambio_de_precio();

        $valores = (new ProcessChunkSetFinalPrices($ids, $dueno->id, $run->id, 999999))->__serialize();

        $this->assertArrayHasKey("\0*\0auth_user_id", $valores, 'Precondición: la propiedad nueva viaja en el payload con ese nombre; si no, este test no saca nada.');

        unset($valores["\0*\0auth_user_id"]);

        $lote = $this->deserializar(ProcessChunkSetFinalPrices::class, $valores);

        /* Lo corre un worker: sin sesión (crear_dueno() deja la del dueño). */
        $this->olvidar_la_sesion();

        $lote->handle();

        $this->assertSame(1, (int) PriceUpdateRun::find($run->id)->processed_chunks, 'El lote viejo no se contó en su corrida.');
        $this->assertSame(2, (int) DB::table('price_update_run_articles')->where('price_update_run_id', $run->id)->count());

        $this->assertCambiosDePrecioANombreDe($ids, $ultimo, (int) config('app.USER_ID'));
    }

    /**
     * Un productor encolado antes de este cambio (payload sin auth_user_id) anda igual que hoy.
     *
     * @return void
     */
    public function test_un_productor_encolado_sin_la_propiedad_nueva_sigue_andando()
    {
        $dueno     = $this->crear_dueno();
        $proveedor = $this->crear_proveedor($dueno, ['percentage_gain' => 40]);
        $ids       = $this->articulos_con_precio_viejo($dueno, ['provider_id' => $proveedor->id], 2);

        $ultimo = $this->ultimo_cambio_de_precio();

        $valores = (new ProcessSetFinalPrices($dueno->id, 'provider_id', $proveedor->id, false, 'proveedor', $proveedor->name))->__serialize();

        $this->assertArrayHasKey('auth_user_id', $valores, 'Precondición: la propiedad nueva viaja en el payload con ese nombre; si no, este test no saca nada.');

        unset($valores['auth_user_id']);

        $productor = $this->deserializar(ProcessSetFinalPrices::class, $valores);

        $this->assertNull($productor->auth_user_id);

        /* Lo corre un worker: sin sesión (crear_dueno() deja la del dueño). */
        $this->olvidar_la_sesion();

        $productor->handle();

        $run = PriceUpdateRun::where('user_id', $dueno->id)->orderBy('id', 'DESC')->first();

        $this->assertNotNull($run);
        $this->assertSame('terminado', $run->status);

        $this->assertCambiosDePrecioANombreDe($ids, $ultimo, (int) config('app.USER_ID'));
    }

    /* ------------------------------------------------------------------------------------------
     * Ayudantes
     * ---------------------------------------------------------------------------------------- */

    /**
     * @param  \App\Models\User $dueno
     * @return \App\Models\User
     */
    protected function crear_empleado($dueno)
    {
        return User::create([
            'name'     => 'zz Empleado recalculo',
            'email'    => 'recalculo-empleado-' . uniqid('', true) . '@test.local',
            'password' => Hash::make('secret'),
            'owner_id' => $dueno->id,
        ]);
    }

    /**
     * Artículos con costo, recalculados una vez (sin sesión) y con el precio final pisado: el
     * próximo recálculo les cambia el precio a todos y deja un price_change por artículo.
     *
     * @param  \App\Models\User $dueno
     * @param  array            $atributos
     * @param  int              $cantidad
     * @return int[]
     */
    protected function articulos_con_precio_viejo($dueno, array $atributos, $cantidad)
    {
        $ids = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $ids[] = $this->crear_articulo($dueno, array_merge(['cost' => 100 + $i * 9, 'percentage_gain' => 30], $atributos))->id;
        }

        $this->recalcular_con_el_motor($ids, $dueno->id);

        $this->pisar_precio_final($ids, 1);

        return $ids;
    }

    /**
     * @return int
     */
    protected function ultimo_cambio_de_precio()
    {
        return (int) DB::table('price_changes')->max('id');
    }

    /**
     * Lo que ve un worker: sin sesión.
     *
     * @return void
     */
    protected function olvidar_la_sesion()
    {
        $this->app['auth']->forgetGuards();

        $this->assertFalse(Auth::check(), 'Precondición: el job tiene que correr sin sesión, como en el worker.');
    }

    /**
     * Arma un job a partir de sus propiedades serializadas, como lo reconstruye la cola desde un
     * payload (el mismo formato que serialize() con __serialize()).
     *
     * @param  string $clase
     * @param  array  $valores
     * @return object
     */
    protected function deserializar($clase, array $valores)
    {
        return unserialize('O:' . strlen($clase) . ':"' . $clase . '"' . substr(serialize($valores), 1));
    }

    /**
     * Un price_change nuevo por artículo, todos a nombre de esa persona.
     *
     * @param  int[] $ids
     * @param  int   $desde_id     Último id de price_changes antes del recálculo.
     * @param  int   $employee_id
     * @return void
     */
    protected function assertCambiosDePrecioANombreDe(array $ids, $desde_id, $employee_id)
    {
        $cambios = DB::table('price_changes')
                        ->where('id', '>', $desde_id)
                        ->whereIn('article_id', $ids)
                        ->get(['article_id', 'employee_id']);

        $this->assertCount(count($ids), $cambios, 'Tenía que quedar un cambio de precio por artículo.');

        foreach ($cambios as $cambio) {
            $this->assertSame($employee_id, (int) $cambio->employee_id, 'El cambio de precio del artículo ' . $cambio->article_id . ' quedó a nombre de ' . $cambio->employee_id . ' y no de ' . $employee_id . '.');
        }
    }
}
