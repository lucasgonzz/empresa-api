<?php

namespace Tests\Feature\TrackingBuyers;

use App\Console\Commands\PurgarBuyerTracking;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Misión tracking-buyers-tienda — la retención `tracking:purgar-buyers`.
 *
 * Protege dos modos de falla opuestos, los dos silenciosos.
 *
 * El primero es BORRAR DE MÁS. Esto es un DELETE agendado que corre todas las noches sin que
 * nadie lo mire; un off-by-one en el límite de días o un where mal acotado se lleva historial
 * que no se puede recuperar, y nadie se entera hasta que alguien pregunta por un dato que ya
 * no está. Por eso el test mide el BORDE (89 días se queda, 91 se va) y no un caso cómodo.
 *
 * El segundo es que el borrado por lotes NO TERMINE de barrer. El bucle corta cuando un lote
 * devuelve menos de FILAS_POR_LOTE; si esa condición estuviera mal escrita, la purga borraría
 * un solo lote por noche y la tabla crecería igual — sin ningún error, sólo con la base
 * inflándose. Por eso el test siembra MÁS de un lote completo: es la única forma de que el
 * bucle dé más de una vuelta de verdad.
 *
 * El borrado va por lotes, y no de un saque, porque un DELETE de cientos de miles de filas
 * mantiene el lock mientras dura y es el mecanismo del 1205 Lock wait timeout que ya tumbó
 * ventas en producción en este sistema.
 *
 * DatabaseTransactions (no RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites.
 */
class Purga_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión que enciende el tracking.
     *
     * @var string
     */
    const SLUG = 'tracking_buyers';

    /**
     * Un user_id que NO es el de la instancia, para el caso de las filas que la purga vieja
     * no podía alcanzar. No hace falta que exista en `users`: la tabla no tiene FK física, y
     * el punto del test es justamente que esas filas se escriban igual.
     *
     * @var int
     */
    const USER_ID_AJENO = 90500;

    /**
     * Usuario dueño de la instancia de testing (config('app.USER_ID') = 500 en .env.testing).
     *
     * @return \App\Models\User|null
     */
    protected function usuario_de_testing()
    {
        return User::find(500);
    }

    /**
     * Deja el terreno limpio.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('buyer_tracking_events')->whereIn('user_id', [500, self::USER_ID_AJENO])->delete();
    }

    /**
     * Le activa la extensión al comercio, que es el gate del comando.
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
     * Siembra $cantidad eventos con el occurred_at dado, insertando de a 1000.
     *
     * @param string $occurred_at
     * @param int $cantidad
     * @param int $user_id Comercio dueño de las filas; por defecto el de la instancia
     * @return void
     */
    protected function sembrar_eventos($occurred_at, $cantidad, $user_id = 500)
    {
        $ahora = now();
        $filas = [];

        for ($i = 0; $i < $cantidad; $i++) {
            $filas[] = [
                'user_id'     => $user_id,
                'buyer_id'    => null,
                'visitor_id'  => '11111111-1111-4111-8111-111111111111',
                'session_id'  => '22222222-2222-4222-8222-222222222222',
                'event_type'  => 'product_view',
                'article_id'  => 10,
                'occurred_at' => $occurred_at,
                'created_at'  => $ahora,
                'updated_at'  => $ahora,
            ];
        }

        foreach (array_chunk($filas, 1000) as $lote) {
            DB::table('buyer_tracking_events')->insert($lote);
        }
    }

    /**
     * Cantidad de eventos que quedan del usuario de testing.
     *
     * @return int
     */
    protected function eventos_restantes()
    {
        return $this->eventos_restantes_de(500);
    }

    /**
     * Cantidad de eventos que quedan de un comercio puntual.
     *
     * @param int $user_id
     * @return int
     */
    protected function eventos_restantes_de($user_id)
    {
        return DB::table('buyer_tracking_events')->where('user_id', $user_id)->count();
    }

    /**
     * El borde de la retención: lo de 91 días se va, lo de 89 se queda. Un off-by-one acá es
     * historial perdido sin aviso.
     *
     * @group tracking_buyers
     * @test
     */
    public function borra_lo_de_mas_de_noventa_dias_y_no_toca_lo_de_ochenta_y_nueve()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $this->sembrar_eventos(now()->subDays(91)->format('Y-m-d H:i:s'), 3);
        $this->sembrar_eventos(now()->subDays(89)->format('Y-m-d H:i:s'), 4);
        $this->sembrar_eventos(now()->format('Y-m-d H:i:s'), 2);

        $this->assertEquals(9, $this->eventos_restantes());

        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);

        $this->assertEquals(
            6,
            $this->eventos_restantes(),
            'La purga no dejó exactamente los eventos de menos de 90 días.'
        );

        $viejos = DB::table('buyer_tracking_events')
            ->where('user_id', 500)
            ->where('occurred_at', '<', now()->subDays(90))
            ->count();

        $this->assertEquals(0, $viejos, 'Quedaron eventos anteriores al corte de retención.');
    }

    /**
     * El bucle de lotes barre TODO, no un lote y chau. Se siembra más de un lote completo
     * (FILAS_POR_LOTE + 7) para que el bucle tenga que dar más de una vuelta de verdad: con
     * una sola vuelta quedarían 7 filas viejas y el test lo denuncia.
     *
     * @group tracking_buyers
     * @test
     */
    public function el_borrado_por_lotes_barre_mas_de_un_lote_y_termina()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $de_mas = PurgarBuyerTracking::FILAS_POR_LOTE + 7;

        $this->sembrar_eventos(now()->subDays(120)->format('Y-m-d H:i:s'), $de_mas);
        $this->sembrar_eventos(now()->subDays(1)->format('Y-m-d H:i:s'), 5);

        $this->assertEquals($de_mas + 5, $this->eventos_restantes());

        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);

        $this->assertEquals(
            5,
            $this->eventos_restantes(),
            'El bucle de lotes cortó antes de barrer todo: la tabla seguiría creciendo sin que nada avise.'
        );

        // Y una segunda corrida no tiene nada que hacer: es el punto fijo.
        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);
        $this->assertEquals(5, $this->eventos_restantes());
    }

    /**
     * La opción --dias manda sobre la constante.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_opcion_dias_manda_sobre_la_constante_de_retencion()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $this->sembrar_eventos(now()->subDays(20)->format('Y-m-d H:i:s'), 3);
        $this->sembrar_eventos(now()->subDays(5)->format('Y-m-d H:i:s'), 2);

        // Con la retención por defecto (90) no se borra nada: los dos grupos son recientes.
        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);
        $this->assertEquals(5, $this->eventos_restantes());

        // Con --dias=10 se va el grupo de 20 días.
        $this->artisan('tracking:purgar-buyers', ['--dias' => 10])->assertExitCode(0);
        $this->assertEquals(2, $this->eventos_restantes());
    }

    /**
     * Un --dias basura CORTA con error y sin borrar nada, en vez de caer en silencio a la
     * retención por defecto.
     *
     * El modo de falla es el operador que quiso purgar a 7 días, escribió mal la opción, y se
     * queda creyendo que lo hizo porque el comando dijo que todo salió bien. Y es además el
     * criterio del comando hermano: `tracking:agregar-buyers` corta con exit 1 ante un --fecha
     * inválido, así que las dos opciones se tienen que comportar igual.
     *
     * `--dias=99999999999999999999` está en la lista porque pasa ctype_digit sin despeinarse y
     * castea a PHP_INT_MAX; de ahí `now()->subDays()` no explota, desborda a una fecha del año
     * 2343668 —un límite de retención en el futuro— que MySQL después rechaza con `SQLSTATE
     * [22007] 1292 Incorrect datetime value` en el medio de la purga. Lo corta MAX_DIAS.
     *
     * @group tracking_buyers
     * @test
     */
    public function un_dias_invalido_corta_con_error_y_no_borra_nada()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        // Eventos viejos: si el comando siguiera adelante con un --dias basura, se irían.
        $this->sembrar_eventos(now()->subDays(400)->format('Y-m-d H:i:s'), 4);

        $invalidos = [
            '0'                    => 'cero borraría el histórico entero',
            '-5'                   => 'un negativo escribiría en el futuro',
            '7.5'                  => 'un decimal no es una cantidad de días',
            'abc'                  => 'texto',
            ''                     => 'la opción vacía',
            '99999999999999999999' => 'un entero que desborda la aritmética de fechas',
        ];

        foreach ($invalidos as $valor => $porque) {
            $this->artisan('tracking:purgar-buyers', ['--dias' => $valor])
                ->assertExitCode(1);

            $this->assertEquals(
                4,
                $this->eventos_restantes(),
                'Con --dias inválido (' . $porque . ') el comando igual borró.'
            );
        }

        // Y con un --dias válido sí purga: el corte es por inválido, no porque no haga nada.
        $this->artisan('tracking:purgar-buyers', ['--dias' => 30])->assertExitCode(0);
        $this->assertEquals(0, $this->eventos_restantes());
    }

    /**
     * Sin la extensión el comando no borra nada. El gate va también adentro del comando —y no
     * sólo en el Kernel— porque el comando se puede correr a mano.
     *
     * @group tracking_buyers
     * @test
     */
    public function sin_la_extencion_no_borra_nada()
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

        $this->sembrar_eventos(now()->subDays(200)->format('Y-m-d H:i:s'), 3);

        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);

        $this->assertEquals(3, $this->eventos_restantes(), 'La purga borró sin la extensión activa.');
    }

    /**
     * La purga alcanza a las filas de CUALQUIER user_id, no sólo al de config('app.USER_ID').
     *
     * El modo de falla es una tabla que crece para siempre mientras el comando dice "se
     * borraron 0". El user_id de cada fila lo decide el `commerce_id` que manda el SPA de la
     * tienda (VUE_APP_COMMERCE_ID), que es otra variable de entorno, en otro repo, sin nada
     * que la ate a la de acá: alcanza un env mal copiado en un despliegue para que se empiecen
     * a escribir filas que la purga vieja no podía tocar nunca. Y es la tabla de alto volumen
     * del sistema.
     *
     * El test también mide que la retención se respeta para ese user_id ajeno: se purga, no se
     * arrasa.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_purga_borra_las_filas_de_un_user_id_que_no_es_el_de_la_config()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        $this->sembrar_eventos(now()->subDays(200)->format('Y-m-d H:i:s'), 3);
        $this->sembrar_eventos(now()->subDays(200)->format('Y-m-d H:i:s'), 4, self::USER_ID_AJENO);
        $this->sembrar_eventos(now()->subDays(2)->format('Y-m-d H:i:s'), 2, self::USER_ID_AJENO);

        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);

        $this->assertEquals(0, $this->eventos_restantes(), 'No se purgaron los eventos viejos de la instancia.');

        $this->assertEquals(
            2,
            $this->eventos_restantes_de(self::USER_ID_AJENO),
            'La purga dejó filas inmortales: las de un user_id distinto al de la config no se borran nunca y la tabla crece para siempre.'
        );
    }

    /**
     * La purga no toca el agregado diario: es la memoria de largo plazo y no se purga nunca.
     *
     * @group tracking_buyers
     * @test
     */
    public function la_purga_no_toca_el_agregado_diario()
    {
        $user = $this->usuario_de_testing();
        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }
        $this->activar_extencion($user);

        DB::table('buyer_tracking_daily')->where('user_id', 500)->delete();
        DB::table('buyer_tracking_daily')->insert([
            'user_id'        => 500,
            'fecha'          => now()->subDays(400)->format('Y-m-d'),
            'buyer_id'       => null,
            'article_id'     => 10,
            'event_type'     => 'product_view',
            'total'          => 12,
            'dwell_ms_total' => 4000,
            'amount_total'   => 0,
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $this->sembrar_eventos(now()->subDays(400)->format('Y-m-d H:i:s'), 2);

        $this->artisan('tracking:purgar-buyers')->assertExitCode(0);

        $this->assertEquals(0, $this->eventos_restantes(), 'Los eventos crudos de 400 días tenían que irse.');
        $this->assertEquals(
            1,
            DB::table('buyer_tracking_daily')->where('user_id', 500)->count(),
            'La purga se llevó puesto el agregado diario, que no se purga nunca.'
        );
    }
}
