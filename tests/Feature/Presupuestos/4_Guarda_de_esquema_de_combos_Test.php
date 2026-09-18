<?php

namespace Tests\Feature\Presupuestos;

use App\Http\Controllers\Helpers\Budget\ComboEsquemaHelper;
use App\Http\Controllers\Pdf\BudgetPdf;
use App\Models\Article;
use App\Models\Budget;
use App\Models\BudgetStatus;
use App\Models\Client;
use App\Models\CreditAccount;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 🔴 LA GUARDA DE ESQUEMA DE `budget_combo` (mision combos-y-rangos-de-precio, 16/9/2026).
 *
 * EL AGUJERO QUE FIJAN ESTOS TESTS ES DE DESPLIEGUE, no de funcionalidad. Esta mision agrego la
 * tabla `budget_combo` (migracion `2026_09_16_100300`) y la engancho en todo el circuito de
 * presupuestos. Pero un deploy de empresa sube los archivos y DESPUES corre las migraciones: entre
 * las dos cosas hay una ventana en la que el cliente tiene el codigo nuevo y NO tiene la tabla. Y
 * en cualquier base que por lo que sea todavia no migro, esa ventana no se cierra nunca.
 *
 * Sin guarda, en esa ventana el cliente no puede ABRIR el listado de presupuestos, no puede
 * GUARDAR uno, no puede CONFIRMARLO, no puede DUPLICARLO ni IMPRIMIRLO: los cinco caminos tocan la
 * relacion y se llevan un `SQLSTATE[42S02]: Base table or view not found`. O sea, el modulo entero
 * caido — mucho peor que el defecto que la mision arregla (un combo que no se guardaba).
 *
 * Cada test mide UN camino, y los cinco juntos son la lista completa de puntos que tocan la
 * relacion: `Budget::scopeWithAll()`, `BudgetHelper::attachCombos()`, `BudgetHelper::getTotal()`,
 * `BudgetHelper::attachSaleCombos()`, `BudgetDuplicarHelper::combos_to_payload()` y
 * `BudgetPdf::items()`.
 *
 * ⚠️ COMO SE SACA LA TABLA, y por que el test se da vuelta para hacerlo. Es la misma tecnica que
 * `Tests\Feature\Pedidos\Combos_en_pedidos_Test` (la tabla hermana `order_combo`), y resuelve dos
 * cosas que se cruzan:
 *
 *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo ejecuta. Un `RENAME TABLE` por la
 *      conexion del test cerraria la transaccion de `DatabaseTransactions` y todo lo que escriba
 *      este archivo quedaria pegado en la base del slot. Por eso el rename va por una conexion PDO
 *      APARTE, con su propia sesion.
 *   2. Pero ademas, en MySQL 8 `information_schema` se sirve del diccionario de datos, que es
 *      InnoDB: adentro de una transaccion REPEATABLE READ ya abierta, `Schema::hasTable()` responde
 *      con el SNAPSHOT y sigue viendo la tabla aunque otra sesion ya la haya corrido. Sin tener eso
 *      en cuenta, TODOS estos tests darian verde en falso, con la tabla puesta.
 *
 * Por eso se cierra la transaccion del trait ANTES del rename y se abre una NUEVA despues: el read
 * view de esa transaccion nueva ya nace viendo la base sin `budget_combo`. Al final se revierte, se
 * devuelve la tabla y se deja una transaccion abierta para que el `tearDown` del trait tenga que
 * cerrar. El aislamiento no se pierde en ningun momento: lo que el test escribe lo escribe adentro
 * de la transaccion nueva, y esa se revierte igual.
 *
 * Como TODA la escritura vive adentro de esa transaccion nueva, el fixture de cada test se arma
 * ADENTRO del closure y nunca antes: lo que se cree afuera se lo lleva el `rollBack()`.
 *
 * Se RENOMBRA y no se borra: restaurar es un solo statement y, si algo se cortara a la mitad, la
 * tabla sigue entera bajo el otro nombre (y `setUp()` la devuelve sola en la corrida siguiente).
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados,
 * union types, promocion de constructor, readonly, enum ni #[...].
 */
class Guarda_de_esquema_de_combos_Test extends TestCase
{
    use DatabaseTransactions;

    /** Ids de `budget_statuses`, tabla global sembrada por `BudgetStatusSeeder`. */
    const ESTADO_SIN_CONFIRMAR = 1;
    const ESTADO_CONFIRMADO    = 2;

    /** Nombre al que se corre `budget_combo` mientras corre cada test. */
    const TABLA_ESCONDIDA = 'budget_combo_sin_guarda';

    /** Renglon de articulo con el que se arma cada presupuesto de prueba. */
    const PRECIO_ARTICULO   = 250;
    const CANTIDAD_ARTICULO = 2;

    /** Slug de la extension que gatea `BudgetController::duplicate()`. */
    const EXTENCION_DUPLICAR = 'duplicar_presupuestos';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_budget_combo_si_quedo_escondida();

        ComboEsquemaHelper::olvidar();

        $this->sembrar_estados();
    }

    /**
     * 🔴 El memo de `ComboEsquemaHelper` es estatico: vive todo el proceso de PHPUnit. Si un test de
     * este archivo lo dejara en `false`, TODOS los que corren despues en el mismo proceso —de esta
     * carpeta y de cualquier otra— verian la tabla como inexistente y pasarian sin medir nada. Se
     * limpia en los dos extremos.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        ComboEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * ⚠️ `budget_statuses` puede venir vacia en la base del slot (medido el 21/8/2026). Se siembra
     * con ids explicitos para no depender del auto-increment.
     *
     * Se llama dos veces: en `setUp()` y otra vez adentro de la transaccion nueva de cada escenario,
     * porque el `rollBack()` que abre el escenario se lleva lo sembrado en el `setUp()`.
     *
     * Sin estas filas `BudgetHelper::checkStatus()` revienta en vez de fallar: hace
     * `$budget->budget_status->name` sin chequear null.
     *
     * @return void
     */
    protected function sembrar_estados()
    {
        $estados = [
            Self::ESTADO_SIN_CONFIRMAR => 'Sin confirmar',
            Self::ESTADO_CONFIRMADO    => 'Confirmado',
        ];

        foreach ($estados as $id => $name) {

            $existente = BudgetStatus::find($id);

            if (is_null($existente)) {

                $estado = new BudgetStatus();
                $estado->id = $id;
                $estado->name = $name;
                $estado->save();
            }
        }
    }

    /**
     * Corre el cuerpo del test con la base SIN `budget_combo`.
     *
     * Antes de largar el cuerpo deja armado el escenario y lo VERIFICA con dos aserciones de
     * control. Sin ellas, un test que fallara en esconder la tabla pasaria igual —con la tabla
     * puesta— y no probaria absolutamente nada.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_budget_combo($cuerpo)
    {
        DB::rollBack();

        $this->esconder_budget_combo();

        DB::beginTransaction();

        try {

            $this->sembrar_estados();

            $this->assertFalse(
                ComboEsquemaHelper::hay_tabla(),
                'El escenario no se armo: la guarda sigue viendo la tabla budget_combo.'
            );

            /*
                Control del escenario: consultar la tabla escondida tiene que reventar de verdad.
                Eso es exactamente lo que le pasa a un cliente que corre esta version antes de
                migrar; si no explota, la tabla sigue puesta y el test no mide nada.
            */
            $exploto = false;

            try {
                DB::table(ComboEsquemaHelper::TABLA)->count();
            } catch (\Illuminate\Database\QueryException $e) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'El escenario no se armo: consultar budget_combo todavia funciona.');

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->devolver_budget_combo();

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Autentica al usuario de testing, o saltea el test si la base no lo tiene sembrado.
     *
     * @return \App\Models\User
     */
    protected function autenticar()
    {
        $user = User::find(500);

        if (is_null($user)) {
            $this->markTestSkipped('La base de testing no tiene el usuario 500 sembrado.');
        }

        $this->actingAs($user, 'web');

        return $user;
    }

    /**
     * Cliente del usuario de testing, con su `credit_account` en pesos.
     *
     * El fixture trae `credit_accounts` para los PROVEEDORES y ninguna para los clientes, y
     * `CurrentAcountFromSaleHelper::crear_current_acount()` la usa sin chequear null: sin esta fila
     * el test de confirmar revienta. Mismo apaño que en los otros archivos de esta carpeta.
     *
     * @return \App\Models\Client
     */
    protected function cliente_de_testing()
    {
        $client = Client::where('user_id', 500)->first();

        if (is_null($client)) {
            $this->markTestSkipped('La base de testing no tiene ningun cliente del usuario 500.');
        }

        $credit_account = CreditAccount::where('model_name', 'client')
                                        ->where('model_id', $client->id)
                                        ->where('moneda_id', 1)
                                        ->first();

        if (is_null($credit_account)) {

            CreditAccount::create([
                'model_name' => 'client',
                'model_id'   => $client->id,
                'moneda_id'  => 1,
                'saldo'      => 0,
                'user_id'    => 500,
            ]);
        }

        return $client;
    }

    /**
     * Articulo del renglon, con stock.
     *
     * @return \App\Models\Article
     */
    protected function articulo_de_testing()
    {
        return Article::create([
            'name'       => 'zz Articulo de presupuesto sin budget_combo '.uniqid(),
            'user_id'    => 500,
            'costo_real' => 50,
            'stock'      => 20,
        ]);
    }

    /**
     * Le da al usuario de testing la extension que gatea `duplicate()`, creando la fila del
     * catalogo si la base del slot no la tiene sembrada. Sin la extension, `duplicate()` corta en
     * 403 antes de tocar nada y el test no mediria nada.
     *
     * @return void
     */
    protected function dar_extension_duplicar()
    {
        $extencion = ExtencionEmpresa::where('slug', Self::EXTENCION_DUPLICAR)->first();

        if (is_null($extencion)) {

            $extencion = ExtencionEmpresa::forceCreate([
                'slug' => Self::EXTENCION_DUPLICAR,
                'name' => 'Duplicar presupuestos',
            ]);
        }

        $user = User::find(500);

        if (!$user->extencions()->where('extencion_empresas.id', $extencion->id)->exists()) {
            $user->extencions()->attach($extencion->id);
        }
    }

    /**
     * Payload de POST api/budget con UN renglon de articulo y la clave `combos` PRESENTE Y VACIA.
     *
     * 🔴 Vacia pero presente, y eso es lo que hace que el payload valga. `attachCombos()` distingue
     * los dos casos: con la clave ausente corta antes de tocar nada (y entonces el test no mediria
     * esa guarda), y con la clave vacia hace `$budget->combos()->detach()`, que es un DELETE contra
     * la tabla que no existe. Es el caso real: la SPA y la API se despliegan por separado, asi que
     * en la ventana del deploy una SPA que ya sabe de combos le pega a una API cuya base todavia no
     * tiene la tabla, y manda `combos: []` en cada alta sin combos cargados.
     *
     * Ningun renglon de combo, en cambio, porque un cliente en esa ventana no puede tener ninguno:
     * la tabla donde se guardarian no existe.
     *
     * @param  \App\Models\Client   $client
     * @param  \App\Models\Article  $article
     * @param  array                $overrides
     * @return array
     */
    protected function payload_crear($client, $article, $overrides = [])
    {
        return array_merge([
            'client_id'              => $client->id,
            'start_at'               => null,
            'finish_at'              => null,
            'observations'           => null,
            'price_type_id'          => null,
            'sale_status_id'         => null,
            'discount_stock'         => 0,
            'iva_aplicado'           => 1,
            'total'                  => Self::PRECIO_ARTICULO * Self::CANTIDAD_ARTICULO,
            'budget_status_id'       => Self::ESTADO_SIN_CONFIRMAR,
            'address_id'             => null,
            'surchages_in_services'  => 1,
            'discounts_in_services'  => 1,
            'aplicar_recargos_directo_a_items' => 0,
            'moneda_id'              => 1,
            'valor_dolar'            => null,
            'discounts'              => [],
            'surchages'              => [],
            'services'               => [],
            'promocion_vinotecas'    => [],
            'combos'                 => [],
            'articles'               => [[
                'id'                          => $article->id,
                'status'                      => $article->status,
                'cost_in_dollars'             => null,
                'name'                        => $article->name,
                'name_vender_personalizado'   => null,
                'amount'                      => Self::CANTIDAD_ARTICULO,
                'price'                       => Self::PRECIO_ARTICULO,
                'costo_real'                  => 50,
                'unidades_individuales'       => null,
                'presentacion'                => null,
                'price_type_personalizado_id' => null,
                'bonus'                       => null,
                'location'                    => null,
            ]],
        ], $overrides);
    }

    /**
     * 1. Sin la tabla, el LISTADO de presupuestos sigue abriendo.
     *
     * Es el camino de `Budget::scopeWithAll()`, que eager-loadea `combos.articles`. El presupuesto
     * se crea con Eloquent y no por la API para que lo unico que se mida sea el scope: si el alta
     * fallara, el test tiene que fallar en el test 2 y no acá.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function sin_la_tabla_budget_combo_el_listado_de_presupuestos_sigue_abriendo()
    {
        $this->sin_budget_combo(function () {

            $this->autenticar();

            $client = $this->cliente_de_testing();

            $budget = Budget::create([
                'num'              => (int) DB::table('budgets')->max('num') + 1,
                'client_id'        => $client->id,
                'budget_status_id' => Self::ESTADO_SIN_CONFIRMAR,
                'total'            => 0,
                'discount_stock'   => 0,
                'iva_aplicado'     => 1,
                'moneda_id'        => 1,
                'user_id'          => 500,
            ]);

            /* a. El listado que pega la SPA. */
            $respuesta = $this->get('api/budget/from-date/'.date('Y-m-d'))
                              ->assertStatus(200);

            $ids = array_column($respuesta->json('models'), 'id');

            $this->assertContains(
                $budget->id,
                $ids,
                'Sin budget_combo, el listado de presupuestos dejo de traer el presupuesto.'
            );

            /* b. Y el mismo scope por el que pasa `fullModel()` en cada alta y cada update. */
            $completo = Budget::where('id', $budget->id)->withAll()->first();

            $this->assertNotNull($completo, 'Sin budget_combo, withAll() dejo de traer el presupuesto.');
        });
    }

    /**
     * 2. Sin la tabla, GUARDAR un presupuesto sigue andando.
     *
     * Es el camino de `BudgetHelper::attachCombos()` (que detacha antes de adjuntar, o sea un
     * DELETE contra la tabla) y el de `BudgetHelper::getTotal()`, que hace `load('combos')` en cada
     * alta. La asercion del total no es de adorno: si `getTotal()` devolviera cualquier cosa, el
     * alta moriria con "El total del presupuesto no corresponde con los productos ingresados".
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function sin_la_tabla_budget_combo_se_sigue_guardando_un_presupuesto()
    {
        $this->sin_budget_combo(function () {

            $this->autenticar();

            $client = $this->cliente_de_testing();
            $article = $this->articulo_de_testing();

            $budget_id = $this->post('api/budget', $this->payload_crear($client, $article))
                              ->assertStatus(201)
                              ->json('model.id');

            $this->assertNotNull($budget_id, 'Sin budget_combo, el alta dejo de devolver el presupuesto.');

            $guardado = Budget::find($budget_id);

            $this->assertEquals(
                Self::PRECIO_ARTICULO * Self::CANTIDAD_ARTICULO,
                (float) $guardado->total,
                'Sin budget_combo, el total guardado cambio.'
            );

            $this->assertCount(
                1,
                DB::table('article_budget')->where('budget_id', $budget_id)->get(),
                'Sin budget_combo, el renglon de articulo dejo de guardarse.'
            );
        });
    }

    /**
     * 3. Sin la tabla, CONFIRMAR un presupuesto sigue creando la venta.
     *
     * Es el camino de `BudgetHelper::attachSaleCombos()`, y es el que mas duele de los cinco: la
     * confirmacion es la que le da la venta al comercio.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function sin_la_tabla_budget_combo_confirmar_sigue_creando_la_venta()
    {
        $this->sin_budget_combo(function () {

            $this->autenticar();

            $client = $this->cliente_de_testing();
            $article = $this->articulo_de_testing();

            $budget_id = $this->post('api/budget', $this->payload_crear($client, $article))
                              ->assertStatus(201)
                              ->json('model.id');

            $this->post('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

            $sale = Sale::where('budget_id', $budget_id)->first();

            $this->assertNotNull($sale, 'Sin budget_combo, confirmar dejo de crear la venta.');

            $this->assertEquals(
                Self::PRECIO_ARTICULO * Self::CANTIDAD_ARTICULO,
                (float) $sale->total,
                'Sin budget_combo, el total de la venta cambio.'
            );
        });
    }

    /**
     * 4. Sin la tabla, DUPLICAR un presupuesto sigue andando.
     *
     * Es el camino de `BudgetDuplicarHelper::combos_to_payload()`, que recorre los combos del
     * origen para copiarlos al duplicado.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function sin_la_tabla_budget_combo_duplicar_sigue_andando()
    {
        $this->sin_budget_combo(function () {

            $this->autenticar();
            $this->dar_extension_duplicar();

            $client = $this->cliente_de_testing();
            $article = $this->articulo_de_testing();

            $origen_id = $this->post('api/budget', $this->payload_crear($client, $article))
                              ->assertStatus(201)
                              ->json('model.id');

            $duplicado_id = $this->post('api/budget/'.$origen_id.'/duplicate')
                                 ->assertStatus(201)
                                 ->json('model.id');

            $this->assertNotEquals($origen_id, $duplicado_id, 'El duplicado tiene que ser otro presupuesto.');

            $this->assertCount(
                1,
                DB::table('article_budget')->where('budget_id', $duplicado_id)->get(),
                'Sin budget_combo, el duplicado dejo de llevarse el renglon de articulo.'
            );
        });
    }

    /**
     * 5. Sin la tabla, el PDF del presupuesto se sigue generando.
     *
     * Es el camino de `BudgetPdf::items()` —donde el presupuesto llega con `Budget::find()` pelado,
     * asi que la relacion se carga en el acto— y, de yapa, el de `BudgetPdf::total()`, que llama a
     * `BudgetHelper::getTotal()` desde el `Footer()`. Va con `with_prices = 1` justamente para que
     * ese segundo camino se ejecute.
     *
     * ⚠️ Se instancia `BudgetPdfSinSalir` y no `BudgetPdf` porque el constructor del original
     * termina en `exit`, que mataria el proceso de PHPUnit. La subclase solo toca `Output()`: deja
     * que fpdf arme el PDF entero (`Close()` incluido, que es lo que dispara el `Footer()`), se
     * guarda el resultado y tira una excepcion propia ANTES de que se ejecute el `exit`. Todo el
     * resto del constructor —el `items()`, que es lo que se esta midiendo— corre tal cual.
     *
     * ⚠️ Y se le saca el logo al usuario adentro de la transaccion (se revierte al terminar) porque
     * `PdfHelper::header()` sale a la RED a chequear que la imagen exista (`file_exists_2` con un
     * timeout de 3 segundos). Un test que depende de que comerciocity.com conteste no mide la
     * guarda: mide internet.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function sin_la_tabla_budget_combo_el_pdf_se_sigue_generando()
    {
        $this->sin_budget_combo(function () {

            $user = $this->autenticar();

            $user->image_url = null;
            $user->save();

            $client = $this->cliente_de_testing();
            $article = $this->articulo_de_testing();

            $budget_id = $this->post('api/budget', $this->payload_crear($client, $article))
                              ->assertStatus(201)
                              ->json('model.id');

            BudgetPdfSinSalir::$ultimo_pdf = null;

            $genero = false;

            try {
                new BudgetPdfSinSalir(Budget::find($budget_id), 1, 0);
            } catch (PdfGeneradoSinSalir $e) {
                $genero = true;
            }

            $this->assertTrue($genero, 'Sin budget_combo, el PDF del presupuesto dejo de generarse.');

            $this->assertStringStartsWith(
                '%PDF',
                (string) BudgetPdfSinSalir::$ultimo_pdf,
                'Sin budget_combo, lo que devolvio fpdf no es un PDF.'
            );
        });
    }

    /**
     * 6. Centinela: la base queda como estaba.
     *
     * Va ultimo a proposito (PHPUnit corre los tests en el orden en que estan declarados): si
     * cualquiera de los cinco de arriba se cortara sin devolver la tabla, este lo denuncia en vez
     * de dejar la base del slot rota para las corridas siguientes.
     *
     * @group presupuestos
     * @group combos
     * @test
     */
    public function la_tabla_budget_combo_quedo_en_su_lugar()
    {
        $this->assertTrue(
            Schema::hasTable(ComboEsquemaHelper::TABLA),
            'La tabla budget_combo no volvio a su lugar.'
        );

        $this->assertFalse(
            Schema::hasTable(Self::TABLA_ESCONDIDA),
            'Quedo dando vueltas la tabla escondida '.Self::TABLA_ESCONDIDA.'.'
        );
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
     * Corre `budget_combo` a un nombre que la guarda no busca.
     *
     * @return void
     */
    protected function esconder_budget_combo()
    {
        $this->conexion_aparte()->exec(
            'RENAME TABLE `'.ComboEsquemaHelper::TABLA.'` TO `'.Self::TABLA_ESCONDIDA.'`'
        );

        ComboEsquemaHelper::olvidar();
    }

    /**
     * La devuelve a su nombre.
     *
     * @return void
     */
    protected function devolver_budget_combo()
    {
        $this->conexion_aparte()->exec(
            'RENAME TABLE `'.Self::TABLA_ESCONDIDA.'` TO `'.ComboEsquemaHelper::TABLA.'`'
        );

        ComboEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto entre los dos renames, la tabla quedo con
     * el nombre escondido. Se devuelve sola antes de que ningun test la necesite.
     *
     * @return void
     */
    protected function restaurar_budget_combo_si_quedo_escondida()
    {
        if (!Schema::hasTable(Self::TABLA_ESCONDIDA)) {
            return;
        }

        $this->devolver_budget_combo();
    }
}

/**
 * Excepcion de control del test del PDF: la tira `BudgetPdfSinSalir::Output()` cuando el PDF ya
 * esta armado, para frenar el constructor de `BudgetPdf` justo antes de su `exit`.
 */
class PdfGeneradoSinSalir extends \Exception
{
}

/**
 * `BudgetPdf` sin el `exit` del final del constructor.
 *
 * 🔴 POR QUE HACE FALTA. `BudgetPdf::__construct()` termina en `$this->Output(); exit;`. Ese `exit`
 * mataria el proceso de PHPUnit en el acto —sin resumen, sin XML de junit y con la tabla escondida
 * todavia sin devolver—, asi que el PDF no se puede probar instanciando la clase real.
 *
 * Lo unico que se toca es `Output()`, que es el ultimo paso: se le pide a fpdf el PDF como string
 * (`'S'`, que ademas dispara el `Close()` y con el el `Footer()`) y se corta con una excepcion. El
 * `AddPage()` y el `items()` del constructor —que es donde vive el acceso a los combos que estos
 * tests miden— corren exactamente igual que en produccion.
 */
class BudgetPdfSinSalir extends BudgetPdf
{
    /** El PDF que devolvio fpdf en la ultima instancia, para poder aseverar sobre el. */
    public static $ultimo_pdf = null;

    /**
     * @param  string  $dest
     * @param  string  $name
     * @param  bool    $isUTF8
     * @return void
     *
     * @throws \Tests\Feature\Presupuestos\PdfGeneradoSinSalir siempre.
     */
    function Output($dest = '', $name = '', $isUTF8 = false)
    {
        Self::$ultimo_pdf = parent::Output('S', '', true);

        throw new PdfGeneradoSinSalir();
    }
}
