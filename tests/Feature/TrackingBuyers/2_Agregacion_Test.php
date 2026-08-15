<?php

namespace Tests\Feature\TrackingBuyers;

use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión tracking-buyers-tienda — el rollup diario `tracking:agregar-buyers`.
 *
 * El modo de falla que protege es la DUPLICACIÓN SILENCIOSA. Este comando lo dispara el cron
 * todas las noches y, cuando algo sale mal, lo primero que hace cualquiera es correrlo de nuevo
 * a mano — o recorrer varios días hacia atrás de una. Si no fuera idempotente, cada reintento
 * sumaría una copia del día al agregado, y como el agregado es la única memoria que sobrevive a
 * la purga de 90 días, esos totales inflados serían indistinguibles de los reales para siempre.
 * Nada tiraría un error: las cifras del ERP simplemente estarían mal.
 *
 * La idempotencia NO la puede dar el esquema (un unique compuesto sobre columnas nullable no
 * sirve en MySQL, ver la migración del agregado), así que la da el comando borrando el día
 * completo antes de reinsertar — y por eso "correrlo dos veces deja el mismo resultado" es LA
 * aserción de este archivo, no una de más.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Agregacion_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión que enciende el tracking.
     *
     * @var string
     */
    const SLUG = 'tracking_buyers';

    /**
     * Usuario dueño de la instancia de testing. Es el mismo que resuelve el comando por
     * config('app.USER_ID'), que en .env.testing vale 500.
     *
     * Null si la base de testing no lo tiene sembrado.
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Deja el terreno limpio: sin agregados ni eventos previos del usuario de testing.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('buyer_tracking_daily')->where('user_id', 500)->delete();
        DB::table('buyer_tracking_events')->where('user_id', 500)->delete();
    }

    /**
     * Le activa la extensión al comercio, que es el gate de los dos comandos.
     *
     * @param User $user
     * @return void
     */
    protected function activar_extencion($user)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            // forceCreate: el modelo no declara $fillable y acá no rige el Model::unguarded()
            // que aplica `artisan db:seed`, así que un create() común tiraría MassAssignmentException.
            $extencion = ExtencionEmpresa::forceCreate([
                'name' => 'Seguimiento de compradores en la tienda',
                'slug' => self::SLUG,
            ]);
        }

        $ya_la_tiene = DB::table('extencion_empresa_user')
            ->where('user_id', $user->id)
            ->where('extencion_empresa_id', $extencion->id)
            ->exists();

        if (!$ya_la_tiene) {
            DB::table('extencion_empresa_user')->insert([
                'user_id'              => $user->id,
                'extencion_empresa_id' => $extencion->id,
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }

    /**
     * Inserta un evento crudo. Los campos que no se pasan quedan en su default.
     *
     * @param array $datos
     * @return void
     */
    protected function sembrar_evento(array $datos)
    {
        DB::table('buyer_tracking_events')->insert(array_merge([
            'user_id'     => 500,
            'buyer_id'    => null,
            'visitor_id'  => '11111111-1111-4111-8111-111111111111',
            'session_id'  => '22222222-2222-4222-8222-222222222222',
            'event_type'  => 'product_view',
            'article_id'  => null,
            'dwell_ms'    => null,
            'amount'      => null,
            'created_at'  => now(),
            'updated_at'  => now(),
        ], $datos));
    }

    /**
     * Filas del agregado del usuario de testing, ordenadas para poder compararlas.
     *
     * @return array<int, array>
     */
    protected function agregado()
    {
        return DB::table('buyer_tracking_daily')
            ->where('user_id', 500)
            ->orderBy('fecha')
            ->orderByRaw('COALESCE(buyer_id, 0)')
            ->orderByRaw('COALESCE(article_id, 0)')
            ->orderBy('event_type')
            ->get(['fecha', 'buyer_id', 'article_id', 'event_type', 'total', 'dwell_ms_total', 'amount_total'])
            ->map(function ($fila) {
                return (array) $fila;
            })
            ->toArray();
    }

    /**
     * N eventos del día colapsan en una fila por (buyer, artículo, tipo), con los totales
     * sumados. Es lo que hace que el ERP pueda leer años de historia sin los eventos crudos.
     *
     * @group tracking_buyers
     * @test
     */
    public function el_rollup_colapsa_los_eventos_del_dia_con_los_totales_correctos()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $fecha = '2026-08-10';

        // Tres vistas del mismo artículo por el mismo comprador: una sola fila, total 3.
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'event_type' => 'product_view', 'dwell_ms' => 1000, 'occurred_at' => $fecha . ' 09:00:00']);
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'event_type' => 'product_view', 'dwell_ms' => 2000, 'occurred_at' => $fecha . ' 12:30:00']);
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'event_type' => 'product_view', 'dwell_ms' => 3500, 'occurred_at' => $fecha . ' 23:59:58']);

        // Otro tipo de evento del mismo comprador y artículo: fila aparte.
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'event_type' => 'cart_add', 'occurred_at' => $fecha . ' 12:31:00']);

        // Un anónimo (buyer_id null) sobre otro artículo, con monto.
        $this->sembrar_evento(['buyer_id' => null, 'article_id' => 20, 'event_type' => 'checkout_complete', 'amount' => 1500.50, 'occurred_at' => $fecha . ' 18:00:00']);
        $this->sembrar_evento(['buyer_id' => null, 'article_id' => 20, 'event_type' => 'checkout_complete', 'amount' => 499.50, 'occurred_at' => $fecha . ' 18:05:00']);

        // Un evento de OTRO día, que no tiene que entrar en este rollup.
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'event_type' => 'product_view', 'dwell_ms' => 9999, 'occurred_at' => '2026-08-09 10:00:00']);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => $fecha])->assertExitCode(0);

        $filas = $this->agregado();

        $this->assertCount(3, $filas, 'El rollup no colapsó en las 3 filas esperadas.');

        // Anónimo primero por el orden de COALESCE(buyer_id, 0).
        $this->assertEquals(null, $filas[0]['buyer_id']);
        $this->assertEquals(20, $filas[0]['article_id']);
        $this->assertEquals('checkout_complete', $filas[0]['event_type']);
        $this->assertEquals(2, $filas[0]['total']);
        $this->assertEquals(0, $filas[0]['dwell_ms_total']);
        $this->assertEquals(2000.00, (float) $filas[0]['amount_total']);

        $this->assertEquals(77, $filas[1]['buyer_id']);
        $this->assertEquals('cart_add', $filas[1]['event_type']);
        $this->assertEquals(1, $filas[1]['total']);

        $this->assertEquals(77, $filas[2]['buyer_id']);
        $this->assertEquals(10, $filas[2]['article_id']);
        $this->assertEquals('product_view', $filas[2]['event_type']);
        $this->assertEquals(3, $filas[2]['total']);
        $this->assertEquals(6500, $filas[2]['dwell_ms_total'], 'Las tres vistas del día tenían que sumar 6500 ms.');
        $this->assertEquals($fecha, substr((string) $filas[2]['fecha'], 0, 10));

        // El evento del día anterior no se coló: ninguna fila del agregado es del 09.
        foreach ($filas as $fila) {
            $this->assertEquals($fecha, substr((string) $fila['fecha'], 0, 10));
        }
    }

    /**
     * LA aserción del archivo: correr el rollup dos veces sobre el mismo día deja exactamente
     * el mismo agregado. Es lo que hace seguro reintentar y recorrer el histórico hacia atrás.
     *
     * @group tracking_buyers
     * @test
     */
    public function correr_el_rollup_dos_veces_deja_exactamente_el_mismo_resultado()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $fecha = '2026-08-10';

        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'dwell_ms' => 1000, 'occurred_at' => $fecha . ' 09:00:00']);
        $this->sembrar_evento(['buyer_id' => 77, 'article_id' => 10, 'dwell_ms' => 2000, 'occurred_at' => $fecha . ' 10:00:00']);
        $this->sembrar_evento(['buyer_id' => null, 'article_id' => 20, 'dwell_ms' => 500, 'occurred_at' => $fecha . ' 11:00:00']);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => $fecha])->assertExitCode(0);

        $primera = $this->agregado();
        $this->assertCount(2, $primera);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => $fecha])->assertExitCode(0);

        $segunda = $this->agregado();

        $this->assertEquals(
            $primera,
            $segunda,
            'La segunda corrida cambió el agregado: el comando no es idempotente y cada reintento del cron duplicaría los totales.'
        );
        $this->assertCount(
            2,
            $segunda,
            'La segunda corrida dejó filas de más: el borrado previo del día no está limpiando.'
        );
    }

    /**
     * La opción --fecha manda: agrega el día que se le pide y no toca los otros.
     *
     * @group tracking_buyers
     * @test
     */
    public function el_rollup_respeta_la_opcion_fecha_y_no_toca_los_otros_dias()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => '2026-08-01 10:00:00']);
        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => '2026-08-02 10:00:00']);
        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => '2026-08-02 11:00:00']);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => '2026-08-01'])->assertExitCode(0);
        $this->artisan('tracking:agregar-buyers', ['--fecha' => '2026-08-02'])->assertExitCode(0);

        $filas = $this->agregado();

        $this->assertCount(2, $filas, 'Cada día tenía que dejar su propia fila.');
        $this->assertEquals('2026-08-01', substr((string) $filas[0]['fecha'], 0, 10));
        $this->assertEquals(1, $filas[0]['total']);
        $this->assertEquals('2026-08-02', substr((string) $filas[1]['fecha'], 0, 10));
        $this->assertEquals(2, $filas[1]['total']);

        /*
         * Y volver a correr el día 2 no se lleva puesto el día 1: el borrado previo está
         * acotado a (user_id, fecha), no barre la tabla.
         */
        $this->artisan('tracking:agregar-buyers', ['--fecha' => '2026-08-02'])->assertExitCode(0);

        $this->assertEquals($filas, $this->agregado(), 'Reagregar un día alteró el agregado de otro día.');
    }

    /**
     * Sin --fecha procesa AYER, que es lo que corresponde cuando lo dispara el cron a las 03:30.
     *
     * @group tracking_buyers
     * @test
     */
    public function sin_la_opcion_fecha_el_rollup_procesa_ayer()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $ayer = now()->subDay()->format('Y-m-d');
        $hoy  = now()->format('Y-m-d');

        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => $ayer . ' 12:00:00']);
        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => $hoy . ' 12:00:00']);

        $this->artisan('tracking:agregar-buyers')->assertExitCode(0);

        $filas = $this->agregado();

        $this->assertCount(1, $filas, 'Sin --fecha tenía que agregar sólo el día de ayer.');
        $this->assertEquals($ayer, substr((string) $filas[0]['fecha'], 0, 10));
    }

    /**
     * Sin la extensión el comando no escribe nada. La extensión es el interruptor de las dos
     * puntas, y el gate va también adentro del comando porque el comando se puede correr a mano.
     *
     * @group tracking_buyers
     * @test
     */
    public function sin_la_extencion_el_rollup_no_agrega_nada()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        // A propósito NO se activa la extensión; y si la base la trae de antes, se la sacamos.
        DB::table('extencion_empresa_user')
            ->where('user_id', $user->id)
            ->whereIn('extencion_empresa_id', ExtencionEmpresa::where('slug', self::SLUG)->pluck('id'))
            ->delete();

        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => '2026-08-10 10:00:00']);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => '2026-08-10'])->assertExitCode(0);

        $this->assertCount(0, $this->agregado(), 'El rollup escribió sin la extensión activa.');
    }

    /**
     * Una --fecha con formato inválido corta con código 1 y sin escribir. Es la diferencia
     * entre un error visible y un rollup que "anduvo" sobre un día que nadie pidió.
     *
     * @group tracking_buyers
     * @test
     */
    public function una_fecha_invalida_corta_sin_escribir()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $this->sembrar_evento(['article_id' => 10, 'occurred_at' => '2026-08-10 10:00:00']);

        $this->artisan('tracking:agregar-buyers', ['--fecha' => '2026-13-45'])->assertExitCode(1);

        $this->assertCount(0, $this->agregado(), 'Escribió con una fecha inválida.');
    }
}
