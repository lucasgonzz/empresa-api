<?php

namespace Tests\Feature\ActividadDeClientes;

use App\Console\Commands\PurgarBuyerTracking;
use App\Console\Commands\SembrarActividadDeClientes;
use App\Models\Buyer;
use App\Models\Client;
use App\Models\ExtencionEmpresa;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Misión actividad-de-clientes-y-oferta-por-whatsapp — unidad 2: el sembrador de datos de prueba.
 *
 * Un sembrador es una herramienta rara de testear, porque lo que produce no es un resultado sino una
 * BASE, y una base sembrada mal no tira ningún error: deja una pantalla llena de números plausibles
 * contra la que después alguien declara "andando" una funcionalidad que no probó. Los cuatro modos
 * de falla que se miden acá son exactamente esos:
 *
 *   1. Que corra donde no tiene que correr. Este comando BORRA filas de `buyer_tracking_daily`, que
 *      es la única memoria de largo plazo del comportamiento de la tienda (los crudos se purgan a los
 *      90 días) y que no se reconstruye con nada. Contra la base de un cliente eso es pérdida
 *      permanente, así que la guarda de entorno se mide, no se confía.
 *   2. Que el tramo VIEJO no exista de verdad. Si el sembrador dejara eventos crudos de hace 100
 *      días, la regla de corte por antigüedad —la decisión más delicada de la misión— se "vería
 *      andar" leyendo siempre de los crudos, y nadie se enteraría hasta que la purga se lleve esos
 *      eventos en producción. Por eso se mide que las fechas del bloque viejo tengan agregado y CERO
 *      crudos.
 *   3. Que --limpiar se lleve puesto algo que no sembró. La marca por `visitor_id` es lo único que
 *      separa un evento de prueba de un evento real de la tienda; si el borrado no la usa, la primera
 *      corrida contra una base con datos reales los borra.
 *   4. Que el agregado llegue hasta HOY. El rollup real corre a las 03:30 sobre el día anterior, así
 *      que el histórico nunca incluye el día en curso. Un sembrador que agrega hoy vuelve
 *      inverificable la leyenda "hasta ayer" de la pantalla, que es justo lo que hay que mirar.
 *
 * 🔴 En testing `config('app.env')` vale 'testing', que la guarda del comando PERMITE. Para probar el
 * rechazo hay que pisarla a mano y devolverla en el tearDown: sin eso, el test 1 pasaría siempre
 * porque nunca ejerce la rama que dice medir.
 *
 * 🔴 EL `@group` DE ESTE ARCHIVO ES `actividad-de-clientes`, CON GUIÓN MEDIO, IGUAL QUE LOS OTROS
 * TRES. Estuvo escrito con guión bajo (`actividad_de_clientes`) y eso convertía a
 * `--group actividad-de-clientes` en una medición que falla devolviendo un valor tranquilizador:
 * corría 38 tests, se salteaba los 8 de este archivo —los del comando que BORRA filas— y salía
 * verde sin una línea que dijera que faltaba algo. Un grupo mal escrito no da error: da menos tests.
 *
 * Casi todos los tests corren con `--fondo=0`: el fondo es cardinalidad para que un EXPLAIN
 * signifique algo, no un caso de la pantalla, y sembrar 9.000 filas en cada test sólo haría la suite
 * más lenta. El único que lo enciende es el que mide justamente eso.
 *
 * DatabaseTransactions (NUNCA RefreshDatabase): la base de testing está sembrada de antes y un
 * refresh la vaciaría, rompiendo el resto de las suites — está escrito en
 * TrackingBuyers/2_Agregacion_Test:34-36. Y como la base queda SEMBRADA de verdad después de la
 * corrida manual del comando, el setUp() limpia el tracking del comercio 500 adentro de la
 * transacción: sin eso, cada aserción de conteo estaría contando también lo que dejó esa corrida.
 *
 * PHP 7.4: sin match, ?->, str_contains ni #[...].
 */
class Sembrador_Test extends TestCase
{
    use DatabaseTransactions;

    /**
     * Slug de la extensión que enciende el tracking de las dos puntas.
     *
     * @var string
     */
    const SLUG = 'tracking_buyers';

    /**
     * Comercio de testing: el mismo que resuelve el comando por config('app.USER_ID'), que en
     * .env.testing vale 500.
     *
     * @var int
     */
    const COMERCIO = 500;

    /**
     * El entorno que había antes de que un test lo pisara, para poder devolverlo.
     *
     * @var string|null
     */
    protected $entorno_original;

    /**
     * El comercio que resolvía config('app.USER_ID') antes de que un test lo pisara. Los tests que
     * miden las validaciones del comando arman su propio comercio y lo apuntan ahí.
     *
     * @var mixed
     */
    protected $comercio_original;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Ningún test de esta misión sale a la red: .env.testing tiene una clave REAL de Anthropic
        // y cada llamada se paga.
        config(['services.anthropic.api_key' => null]);

        $this->entorno_original = config('app.env');
        $this->comercio_original = config('app.USER_ID');

        DB::table('buyer_tracking_daily')->where('user_id', self::COMERCIO)->delete();
        DB::table('buyer_tracking_events')->where('user_id', self::COMERCIO)->delete();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        config(['app.env' => $this->entorno_original]);
        config(['app.USER_ID' => $this->comercio_original]);

        parent::tearDown();
    }

    /**
     * @return User|null
     */
    protected function comercio()
    {
        return User::find(self::COMERCIO);
    }

    /**
     * El comercio sin la extensión no puede sembrar, así que todos los tests menos uno la encienden.
     * Molde: TrackingBuyers/2_Agregacion_Test::activar_extencion().
     *
     * @param  User $user
     * @return void
     */
    protected function activar_extencion($user)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            // forceCreate: el modelo no declara $fillable y acá no rige el Model::unguarded() que
            // aplica `artisan db:seed`, así que un create() común tiraría MassAssignmentException.
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
     * @param  User $user
     * @return void
     */
    protected function desactivar_extencion($user)
    {
        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        if (!$extencion) {
            return;
        }

        DB::table('extencion_empresa_user')
            ->where('user_id', $user->id)
            ->where('extencion_empresa_id', $extencion->id)
            ->delete();
    }

    /**
     * Deja el comercio listo para sembrar, o saltea el test si la base no lo tiene.
     *
     * @return User
     */
    protected function comercio_listo()
    {
        $user = $this->comercio();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario ' . self::COMERCIO . ' sembrado.');
        }

        $this->activar_extencion($user);

        return $user;
    }

    /**
     * @return int
     */
    protected function eventos()
    {
        return DB::table('buyer_tracking_events')->where('user_id', self::COMERCIO)->count();
    }

    /**
     * @return int
     */
    protected function agregado()
    {
        return DB::table('buyer_tracking_daily')->where('user_id', self::COMERCIO)->count();
    }

    /**
     * 🔴 Contra la base de un cliente este comando borra `buyer_tracking_daily`, que es la única
     * memoria de largo plazo del comportamiento de la tienda y no se reconstruye con nada. La guarda
     * de entorno es lo único que lo impide, así que se mide: no alcanza con que esté escrita.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_sembrador_no_corre_en_produccion()
    {
        $this->comercio_listo();

        config(['app.env' => 'production']);

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(1);

        $this->assertEquals(0, $this->eventos(), 'El sembrador escribió eventos con app.env = production.');
        $this->assertEquals(0, $this->agregado(), 'El sembrador escribió agregado con app.env = production.');
    }

    /**
     * LA aserción del archivo: existe un tramo que SÓLO vive en el agregado.
     *
     * Sin él, la regla de corte por antigüedad no se puede verificar: leer siempre de los eventos
     * crudos daría el mismo resultado que leer del agregado cuando corresponde, y la diferencia
     * recién aparecería en producción, tres meses después, cuando la purga se lleve los crudos y los
     * totales "de siempre" se achiquen solos.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function siembra_eventos_recientes_y_agregado_viejo_y_el_corte_se_puede_ver()
    {
        $this->comercio_listo();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $corte = now()->subDays(PurgarBuyerTracking::DIAS_RETENCION)->format('Y-m-d H:i:s');

        $recientes = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->where('occurred_at', '>=', $corte)
            ->count();

        $this->assertGreaterThan(0, $recientes, 'No se sembró un solo evento crudo dentro de la ventana de retención.');

        $viejas = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->where('fecha', '<', substr($corte, 0, 10))
            ->get();

        $this->assertGreaterThan(0, $viejas->count(), 'No hay una sola fila de agregado más vieja que la retención: el corte no se puede ver.');

        foreach ($viejas as $fila) {
            $crudos = DB::table('buyer_tracking_events')
                ->where('user_id', self::COMERCIO)
                ->where('occurred_at', '>=', $fila->fecha . ' 00:00:00')
                ->where('occurred_at', '<=', $fila->fecha . ' 23:59:59')
                ->count();

            $this->assertEquals(
                0,
                $crudos,
                'El día ' . $fila->fecha . ' tiene agregado Y eventos crudos: ese tramo no sirve para ver la regla de corte, '
                . 'porque leyendo siempre de los crudos daría lo mismo.'
            );
        }

        // Y esas filas viejas tienen que hablar de artículos que el bloque reciente no toca: si
        // fueran los mismos, "aparece sólo con periodo 0" no se podría distinguir de "aparece
        // siempre".
        $articulos_viejos = $viejas->pluck('article_id')->filter()->unique()->values()->all();

        $this->assertNotEmpty($articulos_viejos, 'El bloque viejo no tiene ni un artículo: no hay nada que buscar en pantalla.');

        $crudos_de_esos_articulos = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->whereIn('article_id', $articulos_viejos)
            ->count();

        $this->assertEquals(
            0,
            $crudos_de_esos_articulos,
            'Los artículos del bloque viejo también tienen eventos crudos, así que van a aparecer con cualquier periodo.'
        );
    }

    /**
     * El agregado reciente lo escribe el rollup REAL (`tracking:agregar-buyers`), no el sembrador, y
     * no llega hasta hoy.
     *
     * Que no llegue a hoy es fiel a producción —el cron corre a las 03:30 sobre el día anterior— y es
     * lo que hace verificable en pantalla la leyenda "del historial completo tenemos hasta ayer".
     * Si el sembrador agregara también hoy, esa leyenda diría una cosa y la pantalla mostraría otra,
     * y nadie tendría con qué darse cuenta.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_agregado_de_lo_reciente_lo_escribe_el_rollup_y_no_llega_hasta_hoy()
    {
        $this->comercio_listo();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $hoy  = now()->format('Y-m-d');
        $ayer = now()->subDay()->format('Y-m-d');

        $de_hoy = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->whereDate('fecha', $hoy)
            ->count();

        $de_ayer = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->whereDate('fecha', $ayer)
            ->count();

        $eventos_de_hoy = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->whereDate('occurred_at', $hoy)
            ->count();

        $this->assertGreaterThan(0, $eventos_de_hoy, 'No hay eventos crudos de HOY: los periodos cortos no se pueden distinguir del histórico.');
        $this->assertGreaterThan(0, $de_ayer, 'El rollup no dejó agregado de AYER: el histórico no tiene último día que mostrar.');
        $this->assertEquals(
            0,
            $de_hoy,
            'Hay agregado de HOY. El rollup real corre a las 03:30 sobre el día anterior, así que esto no pasa en producción '
            . 'y vuelve inverificable la leyenda "hasta ayer" de la pantalla.'
        );
    }

    /**
     * 🔴 --limpiar borra por la MARCA del `visitor_id`, no todo lo que encuentra. Es lo único que
     * separa un evento sembrado de un evento real de la tienda: sin ese filtro, la primera corrida
     * contra una base que ya tenga tráfico real se lo lleva puesto.
     *
     * 🔴 Y LA OTRA MITAD SE MIDE, NO SE MENCIONA. Este test se llamaba `limpiar_borra_solo_lo_sembrado`
     * y sólo verificaba que sobreviviera una fila de agregado de AFUERA del rango. O sea: el nombre
     * afirmaba "borra sólo lo sembrado" y el cuerpo nunca probaba una fila de ADENTRO del rango, que
     * es justo la que SÍ se borra aunque no la haya sembrado él. `buyer_tracking_daily` no tiene
     * columna de marca y se borra por rango de fechas: es una limitación conocida, está escrita en
     * el docblock de limpiar(), y por eso mismo tiene que estar medida — una limitación que nadie
     * mide es una que el día que cambie nadie se entera.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function limpiar_borra_los_eventos_por_su_marca_y_el_agregado_por_rango_de_fechas()
    {
        $this->comercio_listo();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        // Un evento como los que escribe la tienda de verdad: sin la marca de prueba.
        DB::table('buyer_tracking_events')->insert([
            'user_id'     => self::COMERCIO,
            'buyer_id'    => 1,
            'visitor_id'  => 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee',
            'session_id'  => 'aaaaaaaa-bbbb-4ccc-8ddd-ffffffffffff',
            'event_type'  => 'product_view',
            'occurred_at' => now()->subDays(3)->format('Y-m-d H:i:s'),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // Y una fila de agregado de MÁS ATRÁS que el rango sembrado (--dias por defecto es 120).
        DB::table('buyer_tracking_daily')->insert([
            'user_id'    => self::COMERCIO,
            'fecha'      => now()->subDays(200)->format('Y-m-d'),
            'buyer_id'   => 1,
            'event_type' => 'product_view',
            'total'      => 9,
            'visitantes' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * Y una de ADENTRO del rango, que el sembrador no escribió: es la que SÍ se va a borrar.
         * Sin esta fila, el test no prueba la mitad que duele.
         */
        DB::table('buyer_tracking_daily')->insert([
            'user_id'    => self::COMERCIO,
            'fecha'      => now()->subDays(5)->format('Y-m-d'),
            'buyer_id'   => 1,
            'event_type' => 'cart_add',
            'total'      => 7,
            'visitantes' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--limpiar' => true])->assertExitCode(0);

        $sobrevivio = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->where('visitor_id', 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee')
            ->count();

        $this->assertEquals(
            1,
            $sobrevivio,
            '--limpiar se llevó puesto un evento que no había sembrado él. El filtro por la marca del visitor_id no está.'
        );

        $marcados = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->where('visitor_id', 'LIKE', SembrarActividadDeClientes::PREFIJO_VISITANTE . '%')
            ->count();

        $this->assertEquals(0, $marcados, '--limpiar dejó eventos marcados como de prueba sin borrar.');

        $agregado_de_afuera = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->whereDate('fecha', now()->subDays(200)->format('Y-m-d'))
            ->count();

        $this->assertEquals(
            1,
            $agregado_de_afuera,
            '--limpiar borró agregado de afuera del rango sembrado: el borrado por fechas se está pasando de largo.'
        );

        /*
         * 🔴 Y lo de ADENTRO del rango se lo lleva puesto, lo haya sembrado él o no. Esto NO es un
         * defecto que este test tape: es la consecuencia medida de que `buyer_tracking_daily` no
         * tenga columna de marca, y es la razón por la que el comando sólo corre en local, testing o
         * demo y avisa fuerte antes de borrar. Si algún día el agregado gana una marca, este test se
         * pone rojo y hay que venir a leer esto — que es exactamente para lo que está.
         */
        $agregado_de_adentro = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->whereDate('fecha', now()->subDays(5)->format('Y-m-d'))
            ->where('event_type', 'cart_add')
            ->count();

        $this->assertEquals(
            0,
            $agregado_de_adentro,
            'El borrado del agregado va por RANGO DE FECHAS y se lleva lo que no sembró: si esto dejó de pasar, '
            . 'el docblock de limpiar() y el aviso que el comando imprime antes de borrar quedaron desactualizados.'
        );
    }

    /**
     * 🔴 UNA CORRIDA QUE FALLA TIENE QUE DEJAR LA BASE COMO ESTABA.
     *
     * `limpiar()` corría ANTES de las validaciones de "hace falta un comprador atado a un cliente" y
     * "hacen falta N artículos", y esas validaciones salen con código 1. O sea que en un comercio
     * que no las cumple el comando YA HABÍA BORRADO el agregado del rango —del comercio entero,
     * porque esa tabla no tiene columna de marca— y recién después decía que no había sembrado nada.
     * El operador leía "no se sembró" y se quedaba tranquilo, sin ninguna forma de enterarse de que
     * acababa de perder la única memoria de largo plazo del tracking, que no se reconstruye con nada.
     *
     * Se miden los DOS caminos de salida, porque son dos `return 1` distintos.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function una_corrida_que_no_puede_sembrar_no_borra_el_agregado()
    {
        foreach (['sin_compradores', 'sin_articulos'] as $escenario) {
            $comercio = User::create([
                'name'     => 'Comercio que no puede sembrar ' . $escenario,
                'email'    => 'sembrador-' . $escenario . '-' . uniqid() . '@test.local',
                'password' => Hash::make('secret'),
            ]);

            $this->activar_extencion($comercio);

            if ($escenario === 'sin_articulos') {
                // Tiene el comprador atado a un cliente, así que pasa la primera validación y se
                // frena en la de artículos: el comercio no tiene ninguno.
                $cliente = Client::create(['name' => 'Cliente del sembrador', 'user_id' => $comercio->id]);

                Buyer::create([
                    'name'                    => 'Comprador del sembrador',
                    'email'                   => 'buyer-' . uniqid() . '@test.local',
                    'user_id'                 => $comercio->id,
                    'comercio_city_client_id' => $cliente->id,
                ]);
            }

            // El agregado que el comando NO tiene derecho a borrar si no va a sembrar.
            DB::table('buyer_tracking_daily')->insert([
                'user_id'    => $comercio->id,
                'fecha'      => now()->subDays(5)->format('Y-m-d'),
                'buyer_id'   => 1,
                'event_type' => 'product_view',
                'total'      => 42,
                'visitantes' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            config(['app.USER_ID' => $comercio->id]);

            $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(1);

            $this->assertEquals(
                1,
                DB::table('buyer_tracking_daily')->where('user_id', $comercio->id)->count(),
                'Escenario "' . $escenario . '": el comando se llevó puesto el agregado y después avisó que no sembró nada. '
                . 'Todo lo que puede hacer fracasar la siembra se valida ANTES de borrar.'
            );

            $this->assertEquals(
                0,
                DB::table('buyer_tracking_events')->where('user_id', $comercio->id)->count(),
                'Escenario "' . $escenario . '": no tenía que sembrar un solo evento.'
            );
        }
    }

    /**
     * Y --limpiar SÍ borra aunque el comercio no pueda sembrar: ahí borrar es exactamente lo que se
     * pidió, y negarle el borrado al que tiene la base a medias sería dejarlo sin salida.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function limpiar_borra_aunque_el_comercio_no_tenga_con_que_sembrar()
    {
        $comercio = User::create([
            'name'     => 'Comercio que solo limpia',
            'email'    => 'sembrador-limpia-' . uniqid() . '@test.local',
            'password' => Hash::make('secret'),
        ]);

        $this->activar_extencion($comercio);

        DB::table('buyer_tracking_daily')->insert([
            'user_id'    => $comercio->id,
            'fecha'      => now()->subDays(5)->format('Y-m-d'),
            'buyer_id'   => 1,
            'event_type' => 'product_view',
            'total'      => 42,
            'visitantes' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        config(['app.USER_ID' => $comercio->id]);

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--limpiar' => true])->assertExitCode(0);

        $this->assertEquals(
            0,
            DB::table('buyer_tracking_daily')->where('user_id', $comercio->id)->count(),
            '--limpiar tiene que borrar aunque el comercio no tenga compradores ni artículos.'
        );
    }

    /**
     * Correrlo de nuevo tiene que dejar exactamente lo mismo, no el doble.
     *
     * A un sembrador se lo corre otra vez apenas algo sale raro, y una siembra duplicada en silencio
     * deja números que ya nadie puede distinguir de los buenos: no hay error, hay 24 vistas donde
     * había 12. Es la misma propiedad —y por el mismo motivo— que la idempotencia de
     * `tracking:agregar-buyers`.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function correr_el_sembrador_dos_veces_con_limpiar_en_el_medio_deja_la_misma_cantidad()
    {
        $this->comercio_listo();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $primera_eventos  = $this->eventos();
        $primera_agregado = $this->agregado();

        $this->assertGreaterThan(0, $primera_eventos, 'La primera corrida no sembró nada.');

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--limpiar' => true])->assertExitCode(0);

        $this->assertEquals(0, $this->eventos(), 'Después de --limpiar quedaron eventos de prueba.');

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $this->assertEquals($primera_eventos, $this->eventos(), 'La segunda corrida sembró una cantidad distinta de eventos.');
        $this->assertEquals($primera_agregado, $this->agregado(), 'La segunda corrida dejó un agregado distinto.');

        // Y sin --limpiar en el medio tampoco duplica: el comando limpia lo suyo antes de sembrar.
        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $this->assertEquals(
            $primera_eventos,
            $this->eventos(),
            'Correrlo dos veces seguidas duplicó los eventos: el borrado previo de cada corrida no está limpiando.'
        );
        $this->assertEquals($primera_agregado, $this->agregado(), 'Correrlo dos veces seguidas dejó un agregado distinto.');
    }

    /**
     * Sin la extensión, `tracking:agregar-buyers` sale en su primera línea, así que el agregado
     * quedaría a medias y media siembra estaría mintiendo. Se corta antes, y con código de salida 1:
     * a este comando lo corre una persona, y quedarse esperando datos que nunca se escribieron es
     * peor que un error.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function sin_la_extencion_no_siembra_nada()
    {
        $user = $this->comercio();

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario ' . self::COMERCIO . ' sembrado.');
        }

        $this->desactivar_extencion($user);

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(1);

        $this->assertEquals(0, $this->eventos(), 'Sembró eventos sin la extensión tracking_buyers.');
        $this->assertEquals(0, $this->agregado(), 'Escribió agregado sin la extensión tracking_buyers.');
    }

    /**
     * Los dos casos que hacen que la pantalla sirva para algo, y que son los que más fácil se
     * pierden cuando alguien "simplifica" la siembra:
     *
     *   - Una búsqueda con `results_count = 0` REPETIDA. El 0 no es un vacío a ignorar: es "hay
     *     demanda y no hay oferta", el dato más accionable de todo el tracking. Si el sembrador no lo
     *     deja, la pantalla se verifica sin haber ejercido nunca esa rama.
     *   - Visitantes anónimos sobre el MISMO artículo que miró un cliente conocido. Si estuvieran
     *     sobre artículos distintos, un error que mezcle anónimos con clientes nombrados no se vería.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function la_siembra_deja_una_busqueda_sin_resultado_y_visitantes_anonimos_sobre_un_articulo_de_un_cliente()
    {
        $this->comercio_listo();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 0])->assertExitCode(0);

        $sin_resultado = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->where('event_type', 'search')
            ->whereNotNull('buyer_id')
            ->where('results_count', 0)
            ->count();

        $this->assertGreaterThanOrEqual(
            2,
            $sin_resultado,
            'No hay búsquedas de un comprador con results_count = 0: falta el dato más accionable de la pantalla.'
        );

        // Un artículo con actividad de un comprador identificado Y de anónimos a la vez.
        $articulo = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->whereNotNull('buyer_id')
            ->where('event_type', 'product_view')
            ->whereNotNull('article_id')
            ->value('article_id');

        $this->assertNotNull($articulo, 'No hay una sola vista de un comprador identificado sobre un artículo.');

        $anonimos = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->whereNull('buyer_id')
            ->where('article_id', $articulo)
            ->count();

        $visitantes = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->whereNull('buyer_id')
            ->where('article_id', $articulo)
            ->distinct()
            ->count('visitor_id');

        $this->assertGreaterThan(0, $anonimos, 'No hay eventos anónimos sobre un artículo que también miró un cliente conocido.');
        $this->assertGreaterThan(
            1,
            $visitantes,
            'Los eventos anónimos son todos del mismo visitor_id: "cuántos eventos" y "cuántas personas" darían siempre lo mismo '
            . 'y confundir uno con el otro no se notaría.'
        );
    }

    /**
     * 🔴 EL FONDO NO ES RELLENO: LA FORMA DE LA SIEMBRA DECIDE EL PLAN DE EJECUCIÓN.
     *
     * Medido por la unidad 1 de esta misión: con sólo los 3 compradores reales de la base, un
     * `WHERE buyer_id IN (1,2)` matchea el ~40% de `buyer_tracking_daily` y MySQL elige un full scan
     * —y tiene razón—. Un EXPLAIN sobre esa siembra no dice nada del código: dice que la tabla no
     * tiene la forma de una tabla real. Este test fija que el sembrador deje muchos más compradores
     * distintos que los que hay en `buyers`, que es lo que hace que la medición signifique algo.
     *
     * @group actividad-de-clientes
     * @test
     */
    public function el_fondo_le_da_al_agregado_muchos_compradores_distintos()
    {
        $this->comercio_listo();

        $compradores_reales = DB::table('buyers')->where('user_id', self::COMERCIO)->count();

        $this->artisan('tracking:sembrar-actividad-de-prueba', ['--fondo' => 20])->assertExitCode(0);

        $distintos_en_agregado = DB::table('buyer_tracking_daily')
            ->where('user_id', self::COMERCIO)
            ->whereNotNull('buyer_id')
            ->distinct()
            ->count('buyer_id');

        $this->assertGreaterThan(
            $compradores_reales,
            $distintos_en_agregado,
            'El agregado quedó con tantos compradores distintos como tiene la base: con esa forma, el EXPLAIN de las consultas '
            . 'por comprador va a dar type: ALL y no va a significar nada.'
        );

        $del_fondo = DB::table('buyer_tracking_events')
            ->where('user_id', self::COMERCIO)
            ->where('buyer_id', '>=', SembrarActividadDeClientes::PRIMER_BUYER_DE_FONDO)
            ->count();

        $this->assertGreaterThan(0, $del_fondo, 'No se sembró un solo evento de fondo con --fondo=20.');
    }
}
