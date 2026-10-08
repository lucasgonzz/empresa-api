<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper;
use App\Models\Budget;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 7 — LA GUARDA DE ESQUEMA: sin las columnas todavia, se puede vender, abrir la venta,
 * presupuestar, confirmar y actualizar precios.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA VENTANA QUE ESTO TAPA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()`:
 *  `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente tiene este
 *  codigo y no tiene ni `price_sin_descuentos_de_venta` (las ocho tablas de renglones) ni
 *  `aplicar_descuentos_directo_a_items` (`sales` y `budgets`). Y aca el daño es doble:
 *
 *   - la columna de los renglones va en el `withPivot()` de `Sale` y `Budget`: sin guarda se
 *     caeria LEER cualquier venta —el listado, el detalle, los PDF—, no solo el alta;
 *   - el flag viaja en el INSERT de `Sale::create()` / `Budget::create()` (los dos declaran
 *     `$guarded = []`) y en el UPDATE de cualquier edicion: sin guarda se caeria el alta y la
 *     edicion de TODA venta y TODO presupuesto.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ COMO SE ESCONDEN LAS COLUMNAS (tecnica de `tests/Feature/RecargosEnPrecios/7`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo corre: el ALTER va por una
 *      conexion PDO APARTE, para no cerrar la transaccion del test.
 *   2. En MySQL 8 `information_schema` responde con el snapshot de la transaccion REPEATABLE READ
 *      abierta: se cierra la transaccion del trait ANTES del rename y se abre una nueva despues. Sin
 *      eso la guarda seguiria viendo las columnas y los tests darian VERDE EN FALSO.
 *
 *  Se RENOMBRA y no se borra: si algo se corta a la mitad, las columnas siguen enteras bajo el otro
 *  nombre y `setUp()` las devuelve solas en la corrida siguiente.
 *
 * @group descuentos_en_precios
 */
class Guarda_de_esquema_de_la_columna_Test extends DescuentosEnPreciosTestCase
{
    /** El nombre al que se corre la columna de los renglones para que la guarda no la encuentre. */
    const COLUMNA_ESCONDIDA = 'price_sin_descuentos_de_venta_escondida_por_el_test';

    /** El nombre al que se corre el flag de `sales` y `budgets`. */
    const FLAG_ESCONDIDO = 'aplicar_descuentos_directo_a_items_escondido_por_el_test';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        DescuentosEnPreciosEsquemaHelper::olvidar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        DescuentosEnPreciosEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Conexion PDO aparte contra la MISMA base, armada con la config de Laravel.
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
     * Renombra una columna en cada una de las tablas dadas donde este con el nombre `$de`.
     *
     * @param  array   $tablas
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    protected function renombrar($tablas, $de, $a)
    {
        $pdo = $this->conexion_aparte();

        foreach ($tablas as $tabla) {

            $existe = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ".
                "AND TABLE_NAME = '".$tabla."' AND COLUMN_NAME = '".$de."'"
            )->fetchColumn();

            if ((int) $existe === 0) {
                continue;
            }

            $pdo->exec('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$de.'` TO `'.$a.'`');
        }

        DescuentosEnPreciosEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto con las columnas escondidas, se devuelven.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS, self::COLUMNA_ESCONDIDA, DescuentosEnPreciosEsquemaHelper::COLUMNA);
        $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG, self::FLAG_ESCONDIDO, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);
    }

    /**
     * Corre el cuerpo con la base SIN la(s) columna(s) pedidas.
     *
     * Antes del cuerpo verifica el escenario: sin esa asercion de control, un test que no lograra
     * esconder las columnas pasaria igual —con ellas puestas— sin probar nada.
     *
     * @param  \Closure  $cuerpo
     * @param  bool      $renglones  Esconde `price_sin_descuentos_de_venta` en las ocho tablas.
     * @param  bool      $flag       Esconde `aplicar_descuentos_directo_a_items` en sales y budgets.
     * @return void
     */
    protected function sin_las_columnas($cuerpo, $renglones = true, $flag = true)
    {
        DB::rollBack();

        if ($renglones) {
            $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS, DescuentosEnPreciosEsquemaHelper::COLUMNA, self::COLUMNA_ESCONDIDA);
        }

        if ($flag) {
            $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG, self::FLAG_ESCONDIDO);
        }

        DB::beginTransaction();

        try {

            foreach (DescuentosEnPreciosEsquemaHelper::TABLAS as $tabla) {
                $this->assertEquals(
                    !$renglones,
                    DescuentosEnPreciosEsquemaHelper::hay_columna($tabla),
                    'El escenario no se armo: la guarda no ve lo esperado de la columna de renglones en '.$tabla.'.'
                );
            }

            foreach (DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG as $tabla) {
                $this->assertEquals(
                    !$flag,
                    DescuentosEnPreciosEsquemaHelper::hay_columna_flag($tabla),
                    'El escenario no se armo: la guarda no ve lo esperado del flag en '.$tabla.'.'
                );
            }

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS, self::COLUMNA_ESCONDIDA, DescuentosEnPreciosEsquemaHelper::COLUMNA);
            $this->renombrar(DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG, self::FLAG_ESCONDIDO, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Test 1 — sin las columnas se puede GUARDAR una venta con la opcion prendida (las claves viajan
     * y se descartan) y ABRIRLA despues: es el camino de la lectura, el que mas duele.
     *
     * @test
     */
    public function sin_las_columnas_se_puede_vender_y_abrir_la_venta()
    {
        $self = $this;

        $this->sin_las_columnas(function () use ($self) {

            $articulo  = $self->articulo_centinela();
            $servicio  = $self->servicio(50);
            $combo     = $self->combo(200);
            $promo     = $self->promocion(300);
            $descuento = $self->descuento();

            $response = $self->postJson('api/sale', $self->payload_venta([
                $self->item_vender('article', $articulo->id, 90, 100),
                $self->item_vender('service', $servicio->id, 45, 50),
                $self->item_vender('combo', $combo->id, 180, 200),
                $self->item_vender('promocion_vinoteca', $promo->id, 270, 300),
            ], 585.00, 1, [$self->descuento_del_payload($descuento)]));

            $response->assertStatus(201);

            $sale_id = $response->json('model.id');

            $self->assertCount(1, $response->json('model.articles'), 'La venta se guardo con su renglon.');

            $self->assertArrayNotHasKey(
                'price_sin_descuentos_de_venta',
                $response->json('model.articles.0.pivot'),
                'Sin la columna el pivot no la trae (y el SELECT no la nombro).'
            );

            $self->getJson('api/sale/'.$sale_id)->assertStatus(200);

            /* Una edicion: el PUT asigna el flag al modelo y el UPDATE no puede nombrar la columna. */
            $self->putJson('api/sale/'.$sale_id, $self->payload_actualizar(Sale::find($sale_id), [
                $self->item_vender('article', $articulo->id, 90, 100),
            ], 90.00, 1, [$self->descuento_del_payload($descuento)]))->assertStatus(200);

            /* Y el update-prices, que escribe el pivot existente. */
            $self->putJson('api/sale/update-prices/'.$sale_id, [
                'items' => [[
                    'is_article'                  => true,
                    'id'                          => $articulo->id,
                    'price_vender'                => 108,
                    'price_vender_sin_descuentos' => 120,
                ]],
            ])->assertStatus(200);

            $self->assertEqualsWithDelta(108, (float) Sale::find($sale_id)->articles()->first()->pivot->price, self::DELTA);
        });
    }

    /**
     * Test 2 — sin las columnas se puede PRESUPUESTAR, ACTUALIZAR, CONFIRMAR y DUPLICAR con el
     * payload de la SPA que hay antes de que la API migre (la opcion apagada, los descuentos al
     * pie): ninguna escritura nombra una columna que no existe.
     *
     * Y con el payload de la SPA NUEVA y la opcion prendida el alta se RECHAZA por el total, no
     * por un `Unknown column`: sin la columna del flag la opcion no se puede guardar, asi que
     * `getTotal()` volveria a restar el descuento que los precios ya traen y la validacion de
     * `store()` (margen de 3) corta, con rollback. Es el modo de falla seguro de la ventana —la SPA
     * nueva llega despues de la migracion—: nunca un presupuesto guardado con un total mal.
     *
     * @test
     */
    public function sin_las_columnas_se_puede_presupuestar_actualizar_confirmar_y_duplicar()
    {
        $self = $this;

        $this->sin_las_columnas(function () use ($self) {

            $articulo  = $self->articulo_centinela();
            $servicio  = $self->servicio(50);
            $descuento = $self->descuento();

            /* La SPA NUEVA con la opcion prendida: rechazada por el total (rollback), no por SQL. */
            $rechazado = $self->postJson('api/budget', $self->payload_presupuesto(135.00, 1, [$self->descuento_del_payload($descuento)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 90, 100, false)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 45, 50)],
            ]));

            $rechazado->assertStatus(500);

            $self->assertStringContainsString('El total del presupuesto no corresponde', (string) $rechazado->json('message'), 'Tiene que rechazar por el total, no por una columna inexistente.');
            $self->assertStringNotContainsString('Unknown column', (string) $rechazado->getContent());

            /* La SPA de antes: opcion apagada, descuento al pie, precios de lista. 150 − 10 % = 135. */
            $response = $self->postJson('api/budget', $self->payload_presupuesto(135.00, 0, [$self->descuento_del_payload($descuento)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 100, false, false)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 50, false)],
            ]));

            $response->assertStatus(201);

            $budget_id = $response->json('model.id');

            /* Update SIN la clave: el camino que arma el snapshot de bases antes del detach. */
            $self->putJson('api/budget/'.$budget_id, $self->payload_presupuesto(135.00, 0, [$self->descuento_del_payload($descuento)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 100, false, true)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 50, false)],
            ]))->assertStatus(200);

            $self->postJson('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

            $self->assertNotNull(Sale::where('budget_id', $budget_id)->first(), 'Confirmar tiene que haber creado la venta.');

            $self->getJson('api/budget/'.$budget_id)->assertStatus(200);

            /* Duplicar: `BudgetDuplicarHelper` tiene su propio `Budget::create()` y copia el flag. */
            $extencion = ExtencionEmpresa::where('slug', 'duplicar_presupuestos')->first();

            if (is_null($extencion)) {
                $extencion = ExtencionEmpresa::forceCreate([
                    'slug' => 'duplicar_presupuestos',
                    'name' => 'Duplicar presupuestos',
                ]);
            }

            $self->comercio()->extencions()->syncWithoutDetaching([$extencion->id]);

            $self->postJson('api/budget/'.$budget_id.'/duplicate')->assertStatus(201);
        });
    }

    /**
     * Test 3 — las DOS guardas son independientes: con la columna de los renglones PRESENTE y solo
     * el flag escondido (una base a medio migrar), todo sigue andando, y al reves.
     *
     * @test
     */
    public function una_base_a_medio_migrar_tambien_anda()
    {
        $self = $this;

        /* Solo el flag escondido. */
        $this->sin_las_columnas(function () use ($self) {

            $articulo  = $self->articulo_centinela();
            $descuento = $self->descuento();

            $sale = $self->crear_venta($self->payload_venta([
                $self->item_vender('article', $articulo->id, 90, 100),
            ], 90.00, 1, [$self->descuento_del_payload($descuento)]));

            /* La base de los renglones SI se guarda: la columna esta. */
            $self->assert_precio_y_base($self->fila('article_sale', 'sale_id', $sale->id, 'article_id', $articulo->id), 90, 100, 'con la columna y sin el flag');

        }, false, true);

        /* Solo la columna de los renglones escondida. */
        $this->sin_las_columnas(function () use ($self) {

            $articulo  = $self->articulo_centinela();
            $descuento = $self->descuento();

            $sale = $self->crear_venta($self->payload_venta([
                $self->item_vender('article', $articulo->id, 90, 100),
            ], 90.00, 1, [$self->descuento_del_payload($descuento)]));

            /* El flag SI se guarda: la columna esta. */
            $self->assertEquals(1, (int) Sale::find($sale->id)->aplicar_descuentos_directo_a_items);

        }, true, false);
    }

    /**
     * Test 4 — la guarda corta con una excepcion ante una tabla que no es una de las ocho (o una de
     * las dos del flag), en vez de responder `false` en silencio (un nombre mal escrito dejaria la
     * base en NULL para siempre sin que nada lo denuncie).
     *
     * @test
     */
    public function una_tabla_desconocida_es_un_error_de_programacion()
    {
        try {
            DescuentosEnPreciosEsquemaHelper::hay_columna('article_sales');
            $this->fail('hay_columna() tenia que cortar con una excepcion ante una tabla desconocida.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('article_sales', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);

        DescuentosEnPreciosEsquemaHelper::hay_columna_flag('sale');
    }

    /**
     * Test 5 — CON las columnas, la guarda las ve en todas las tablas. Es la no-regresion: una guarda
     * que las apagara siempre pasaria los tests de arriba, y el resto de la suite daria rojo por otro
     * lado sin decir por que.
     *
     * @test
     */
    public function con_las_columnas_la_guarda_las_ve_en_todas_las_tablas()
    {
        foreach (DescuentosEnPreciosEsquemaHelper::TABLAS as $tabla) {
            $this->assertTrue(
                DescuentosEnPreciosEsquemaHelper::hay_columna($tabla),
                'La base del slot tiene que tener la columna en '.$tabla.' (¿falta migrar?).'
            );
        }

        foreach (DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG as $tabla) {
            $this->assertTrue(
                DescuentosEnPreciosEsquemaHelper::hay_columna_flag($tabla),
                'La base del slot tiene que tener el flag en '.$tabla.' (¿falta migrar?).'
            );
        }
    }

    /**
     * Test 6 — la guarda del flag arma el payload y el UPDATE sin nombrar la columna: sin la
     * columna saca la clave del `create()` y no toca el modelo en el update; con la columna deja
     * ambas cosas como estan. Se prueba la unidad, sin pasar por ningun endpoint.
     *
     * @test
     */
    public function las_dos_funciones_del_flag_respetan_la_columna()
    {
        /* CON la columna: el payload queda tal cual y el update asigna lo que vino... */
        $payload = DescuentosEnPreciosEsquemaHelper::quitar_flag_si_no_hay_columna(['a' => 1, 'aplicar_descuentos_directo_a_items' => 1], 'sales');

        $this->assertArrayHasKey('aplicar_descuentos_directo_a_items', $payload);

        $sale = new Sale();
        DescuentosEnPreciosEsquemaHelper::asignar_flag_en_update($sale, 1, 'sales');
        $this->assertEquals(1, $sale->aplicar_descuentos_directo_a_items);

        /* ...y si el request no trae la clave (null), preserva lo guardado. */
        DescuentosEnPreciosEsquemaHelper::asignar_flag_en_update($sale, null, 'sales');
        $this->assertEquals(1, $sale->aplicar_descuentos_directo_a_items, 'Un null del request no pisa el valor guardado.');

        $self = $this;

        /* SIN la columna: la clave se va y el modelo no se toca. */
        $this->sin_las_columnas(function () use ($self) {

            $payload = DescuentosEnPreciosEsquemaHelper::quitar_flag_si_no_hay_columna(['a' => 1, 'aplicar_descuentos_directo_a_items' => 1], 'budgets');

            $self->assertArrayNotHasKey('aplicar_descuentos_directo_a_items', $payload);
            $self->assertArrayHasKey('a', $payload, 'Solo se saca la clave del flag.');

            $budget = new Budget();
            DescuentosEnPreciosEsquemaHelper::asignar_flag_en_update($budget, 1, 'budgets');
            $self->assertArrayNotHasKey('aplicar_descuentos_directo_a_items', $budget->getAttributes(), 'Asignarlo marcaria sucio el modelo y el UPDATE nombraria la columna.');
        }, false, true);
    }
}
