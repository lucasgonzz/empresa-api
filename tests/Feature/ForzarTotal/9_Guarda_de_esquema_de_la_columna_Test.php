<?php

namespace Tests\Feature\ForzarTotal;

use App\Http\Controllers\Helpers\sale\ForzarTotalEsquemaHelper;
use App\Models\Budget;
use App\Models\BudgetStatus;
use Database\Seeders\testing\TestingFerreteriaSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 9 — LA GUARDA DE ESQUEMA: se puede vender y presupuestar aunque la columna todavia no
 * exista.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA VENTANA QUE ESTO TAPA, Y POR QUE NO ES HIPOTETICA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa SUBE LOS ARCHIVOS ANTES DE MIGRAR:
 *  `DeploymentService::execute_steps()` de `admin-api` corre `upload_api` -> `sync_env_keys` ->
 *  `run_migrations`. Entre el primero y el tercero, el cliente tiene el codigo nuevo y NO tiene la
 *  columna que crea la migracion `2026_09_17_100000`.
 *
 *  Y ahi el daño no se limita a las ventas forzadas: `Sale` y `Budget` declaran `$guarded = []`,
 *  asi que Eloquent mete `forzar_total_monto` en el INSERT AUNQUE VALGA NULL. Sin guarda, en esa
 *  ventana se cae el alta de TODA venta y de TODO presupuesto, de todos los clientes.
 *
 *  `develop` ya tapo exactamente esta ventana en este mismo circuito con la guarda de
 *  `budget_combo` (commit 5c0da889, 16/9/2026). Aquello dejaba sin presupuestos; esto deja sin
 *  vender.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ COMO SE SACA LA COLUMNA, Y POR QUE EL TEST SE DA VUELTA PARA HACERLO
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Es la misma tecnica de `tests/Feature/Presupuestos/4` (la guarda de `budget_combo`), y resuelve
 *  las mismas dos cosas cruzadas:
 *
 *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo ejecuta. Un `ALTER TABLE` por la
 *      conexion del test cerraria la transaccion de `DatabaseTransactions` y todo lo que escriba
 *      este archivo quedaria pegado en la base del slot. Por eso el ALTER va por una conexion PDO
 *      APARTE, con su propia sesion.
 *   2. En MySQL 8 `information_schema` se sirve del diccionario de datos, que es InnoDB: adentro de
 *      una transaccion REPEATABLE READ ya abierta, `Schema::hasColumn()` responde con el SNAPSHOT y
 *      sigue viendo la columna aunque otra sesion ya la haya renombrado. Sin tener eso en cuenta,
 *      estos tests darian VERDE EN FALSO, con la columna puesta.
 *
 *  Por eso se cierra la transaccion del trait ANTES del rename y se abre una NUEVA despues. El
 *  aislamiento no se pierde: lo que el test escribe va adentro de la transaccion nueva, que se
 *  revierte igual.
 *
 *  Se RENOMBRA y no se borra: restaurar es un solo statement y, si algo se cortara a la mitad, la
 *  columna sigue entera bajo el otro nombre (y `setUp()` la devuelve sola en la corrida siguiente).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promocion de constructor, readonly, enum ni #[...].
 *
 * @group sales
 * @group presupuestos
 * @group forzar_total
 */
class Guarda_de_esquema_de_la_columna_Test extends ForzarTotalTestCase
{
    /** El nombre al que se corre la columna para que la guarda no la encuentre. */
    const COLUMNA_ESCONDIDA = 'forzar_total_monto_escondida_por_el_test';

    /** Ids de `budget_statuses`. */
    const ESTADO_SIN_CONFIRMAR = 1;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        ForzarTotalEsquemaHelper::olvidar();

        if (is_null(BudgetStatus::find(self::ESTADO_SIN_CONFIRMAR))) {
            $estado = new BudgetStatus();
            $estado->id = self::ESTADO_SIN_CONFIRMAR;
            $estado->name = 'Sin confirmar';
            $estado->save();
        }
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        ForzarTotalEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Conexion PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transaccion del
     * test. Se arma con la config de Laravel, nunca con valores escritos a mano.
     *
     * @return \PDO
     */
    protected function conexion_aparte()
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        /* Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año. */
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * Corre la columna de las dos tablas a un nombre que la guarda no busca.
     *
     * @return void
     */
    protected function esconder_columnas()
    {
        $pdo = $this->conexion_aparte();

        foreach (['sales', 'budgets'] as $tabla) {
            $pdo->exec(
                'ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.ForzarTotalEsquemaHelper::COLUMNA.
                '` TO `'.self::COLUMNA_ESCONDIDA.'`'
            );
        }

        ForzarTotalEsquemaHelper::olvidar();
    }

    /**
     * Las devuelve a su nombre.
     *
     * @return void
     */
    protected function devolver_columnas()
    {
        $pdo = $this->conexion_aparte();

        foreach (['sales', 'budgets'] as $tabla) {
            $pdo->exec(
                'ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.self::COLUMNA_ESCONDIDA.
                '` TO `'.ForzarTotalEsquemaHelper::COLUMNA.'`'
            );
        }

        ForzarTotalEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto entre los dos renames, la columna quedo
     * con el nombre escondido. Se devuelve sola antes de que ningun test la necesite.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        if (!Schema::hasColumn('sales', self::COLUMNA_ESCONDIDA)
            && !Schema::hasColumn('budgets', self::COLUMNA_ESCONDIDA)) {
            return;
        }

        $pdo = $this->conexion_aparte();

        foreach (['sales', 'budgets'] as $tabla) {

            if (Schema::hasColumn($tabla, self::COLUMNA_ESCONDIDA)) {
                $pdo->exec(
                    'ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.self::COLUMNA_ESCONDIDA.
                    '` TO `'.ForzarTotalEsquemaHelper::COLUMNA.'`'
                );
            }
        }

        ForzarTotalEsquemaHelper::olvidar();
    }

    /**
     * Corre el cuerpo del test con la base SIN la columna.
     *
     * Antes de largar el cuerpo VERIFICA el escenario con dos aserciones de control. Sin ellas, un
     * test que fallara en esconder la columna pasaria igual —con la columna puesta— y no probaria
     * absolutamente nada.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_la_columna($cuerpo)
    {
        DB::rollBack();

        $this->esconder_columnas();

        DB::beginTransaction();

        try {

            $this->assertFalse(
                ForzarTotalEsquemaHelper::hay_columna_en_sales(),
                'El escenario no se armo: la guarda sigue viendo la columna en sales.'
            );

            $this->assertFalse(
                ForzarTotalEsquemaHelper::hay_columna_en_budgets(),
                'El escenario no se armo: la guarda sigue viendo la columna en budgets.'
            );

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->devolver_columnas();

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Test 1 — sin la columna se puede GUARDAR UNA VENTA, y la venta ni siquiera esta forzada.
     *
     * Es el caso que mas duele: el 99 % de las ventas del parque no fuerza nada, y sin la guarda se
     * caian TODAS, porque con `$guarded = []` Eloquent manda la columna en el INSERT aunque el
     * valor sea null.
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_guardar_una_venta_comun()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $total = 3300.00;

            $self->postJson('api/sale', $self->payload_venta($total, $total))->assertStatus(201);
        });
    }

    /**
     * Test 2 — y tambien una venta que SI viene forzada.
     *
     * El forzado se pierde —no hay donde guardarlo—, pero la venta se guarda. Es lo correcto: en
     * esa ventana la SPA nueva todavia no esta desplegada, asi que nadie puede estar forzando de
     * verdad; y entre perder un ajuste de 12 pesos y no poder vender, no hay discusion.
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_una_venta_forzada_se_guarda_igual()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $self->postJson('api/sale', $self->payload_venta(
                ForzarTotalTestCase::FORZADO,
                ForzarTotalTestCase::BRUTO,
                ['forzar_total_monto' => ForzarTotalTestCase::MONTO]
            ))->assertStatus(201);
        });
    }

    /**
     * Test 3 — sin la columna se puede GUARDAR UN PRESUPUESTO.
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_guardar_un_presupuesto()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $articulo = $self->articulo(TestingFerreteriaSeeder::ARTICULO_CENTINELA);

            $self->postJson('api/budget', [
                'client_id'                        => $self->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
                'start_at'                         => null,
                'finish_at'                        => null,
                'observations'                     => null,
                'price_type_id'                    => null,
                'sale_status_id'                   => null,
                'discount_stock'                   => 0,
                'iva_aplicado'                     => 1,
                'total'                            => ForzarTotalTestCase::BRUTO,
                'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
                'address_id'                       => null,
                'surchages_in_services'            => 1,
                'discounts_in_services'            => 1,
                'aplicar_recargos_directo_a_items' => 0,
                'moneda_id'                        => 1,
                'valor_dolar'                      => null,
                'discounts'                        => [],
                'surchages'                        => [],
                'services'                         => [],
                'promocion_vinotecas'              => [],
                'combos'                           => [],
                'articles'                         => [[
                    'id'     => $articulo->id,
                    'status' => 'active',
                    'pivot'  => [
                        'amount'   => 1,
                        'bonus'    => null,
                        'location' => null,
                        'price'    => ForzarTotalTestCase::BRUTO,
                    ],
                ]],
            ])->assertStatus(201);
        });
    }

    /**
     * Test 4 — y se puede ACTUALIZAR un presupuesto.
     *
     * Es el cuarto punto de escritura. Los otros tres ya estan arriba.
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_actualizar_un_presupuesto()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            /* El presupuesto se crea ADENTRO del closure: lo de afuera se lo llevo el rollBack. */
            $budget = Budget::create([
                'num'                   => 9500,
                'user_id'               => $self->comercio()->id,
                'client_id'             => $self->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
                'budget_status_id'      => self::ESTADO_SIN_CONFIRMAR,
                'total'                 => 100,
                'discount_stock'        => 0,
                'discounts_in_services' => 1,
                'surchages_in_services' => 1,
            ]);

            $self->putJson('api/budget/'.$budget->id, [
                'client_id'             => $budget->client_id,
                'start_at'              => null,
                'finish_at'             => null,
                'observations'          => 'actualizado sin la columna',
                'total'                 => $budget->total,
                'budget_status_id'      => $budget->budget_status_id,
                'address_id'            => null,
                'surchages_in_services' => 1,
                'discounts_in_services' => 1,
                'moneda_id'             => 1,
                'sale_status_id'        => null,
                'discount_stock'        => 0,
                'iva_aplicado'          => 1,
                'articles'              => [],
                'services'              => [],
                'promocion_vinotecas'   => [],
                'combos'                => [],
                'discounts'             => [],
                'surchages'             => [],
            ])->assertStatus(200);
        });
    }

    /**
     * Test 5 — sin la columna se puede CONFIRMAR un presupuesto.
     *
     * 🔴 Es el quinto punto de escritura (`BudgetHelper::saveSale()`), y NO es un caso borde: es
     * mostrador normal. Es exactamente el mismo circuito que `develop` tapo el 16/9 con la guarda
     * de `budget_combo`.
     *
     * El detalle que lo hace facil de pasar por alto: en la ventana, `$budget->forzar_total_monto`
     * devuelve null SIN ERROR —el atributo no existe en el modelo— y ese null viaja igual al
     * INSERT. O sea que el camino "se ve" inofensivo justo donde revienta.
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_confirmar_un_presupuesto()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            if (is_null(BudgetStatus::find(2))) {
                $estado = new BudgetStatus();
                $estado->id = 2;
                $estado->name = 'Confirmado';
                $estado->save();
            }

            $budget = Budget::create([
                'num'                   => 9600,
                'user_id'               => $self->comercio()->id,
                'client_id'             => $self->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
                'budget_status_id'      => self::ESTADO_SIN_CONFIRMAR,
                'total'                 => 100,
                'discount_stock'        => 0,
                'discounts_in_services' => 1,
                'surchages_in_services' => 1,
            ]);

            $self->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);
        });
    }

    /**
     * Test 6 — sin la columna se puede DUPLICAR un presupuesto.
     *
     * Sexto punto de escritura (`BudgetDuplicarHelper::duplicate()`).
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_duplicar_un_presupuesto()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $extencion = \App\Models\ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();

            if (is_null($extencion)) {
                $extencion = \App\Models\ExtencionEmpresa::forceCreate([
                    'slug' => 'duplicar_presupuestos',
                    'name' => 'Duplicar presupuestos',
                ]);
            }

            $user = $self->comercio();
            $user->extencions()->syncWithoutDetaching([$extencion->id]);
            $user->load('extencions');

            $budget = Budget::create([
                'num'                              => 9700,
                'user_id'                          => $user->id,
                'client_id'                        => $self->cliente(TestingFerreteriaSeeder::CLIENTE_CC)->id,
                'budget_status_id'                 => self::ESTADO_SIN_CONFIRMAR,
                'total'                            => 0,
                'discount_stock'                   => 0,
                'discounts_in_services'            => 1,
                'surchages_in_services'            => 1,
                'aplicar_recargos_directo_a_items' => 0,
            ]);

            $self->postJson('api/budget/'.$budget->id.'/duplicate')->assertStatus(201);
        });
    }

    /**
     * Test 7 — sin la columna se puede CONSOLIDAR facturacion.
     *
     * Septimo y ultimo punto de escritura (`ConsolidarFacturacionHelper::consolidar()`).
     *
     * @group forzar_total
     * @test
     */
    public function sin_la_columna_se_puede_consolidar_facturacion()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $cliente = $self->cliente(TestingFerreteriaSeeder::CLIENTE_CC);

            $afip_information = \App\Models\AfipInformation::where('user_id', $self->comercio()->id)->first();

            $self->assertNotNull($afip_information, 'Falta la configuracion de AFIP del fixture.');

            $venta = \App\Models\Sale::create([
                'user_id'                    => $self->comercio()->id,
                'client_id'                  => $cliente->id,
                'omitir_en_cuenta_corriente' => 1,
                'save_current_acount'        => 0,
                'terminada'                  => 1,
                'is_cerrada'                 => 0,
                'sub_total'                  => 1000,
                'total'                      => 1000,
                'moneda_id'                  => 1,
                'descuento'                  => 0,
            ]);

            /* Si esto no explota, la guarda del septimo punto esta puesta. */
            $consolidada = \App\Http\Controllers\Helpers\sale\ConsolidarFacturacionHelper::consolidar(
                [$venta->id],
                $cliente->id,
                $self->comercio()->id,
                $afip_information->id,
                1,
                false,
                [],
                false
            );

            $self->assertNotNull($consolidada->id, 'La venta consolidada tiene que haberse creado.');
        });
    }

    /**
     * Test 8 — CON la columna, el campo se sigue guardando. Es la no-regresion de la guarda: una
     * guarda que apagara el campo siempre tambien pasaria los siete tests de arriba.
     *
     * @group forzar_total
     * @test
     */
    public function con_la_columna_el_campo_se_sigue_guardando()
    {
        $this->assertTrue(
            ForzarTotalEsquemaHelper::hay_columna_en_sales(),
            'La base del slot tiene que tener la columna para este test.'
        );

        $sale = $this->crear_venta_por_endpoint(
            $this->payload_venta(self::FORZADO, self::BRUTO, ['forzar_total_monto' => self::MONTO])
        );

        $this->assertEqualsWithDelta(
            self::MONTO,
            (float) $sale->forzar_total_monto,
            self::DELTA,
            'con la columna puesta, la guarda no puede impedir que el campo se guarde'
        );
    }
}
