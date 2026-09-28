<?php

namespace Tests\Feature\RecargosEnPrecios;

use App\Http\Controllers\Helpers\sale\RecargosEnPreciosEsquemaHelper;
use App\Models\Budget;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 7 — LA GUARDA DE ESQUEMA: sin la columna todavia, se puede vender, abrir la venta,
 * presupuestar, confirmar y actualizar precios.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 LA VENTANA QUE ESTO TAPA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  Un deploy de empresa sube los archivos ANTES de migrar (`DeploymentService::execute_steps()`:
 *  `upload_api` -> `sync_env_keys` -> `run_migrations`). En esa ventana el cliente tiene este
 *  codigo y no tiene `price_sin_recargos_de_venta`. Y aca el daño seria peor que el de
 *  `forzar_total_monto`: la columna va en el `withPivot()` de `Sale` y `Budget`, asi que sin guarda
 *  se caeria LEER cualquier venta —el listado, el detalle, los PDF—, no solo el alta.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  ⚠️ COMO SE ESCONDE LA COLUMNA (tecnica de `tests/Feature/ForzarTotal/9`)
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *   1. El DDL de MySQL hace COMMIT IMPLICITO de la sesion que lo corre: el ALTER va por una
 *      conexion PDO APARTE, para no cerrar la transaccion del test.
 *   2. En MySQL 8 `information_schema` responde con el snapshot de la transaccion REPEATABLE READ
 *      abierta: se cierra la transaccion del trait ANTES del rename y se abre una nueva despues. Sin
 *      eso la guarda seguiria viendo la columna y los tests darian VERDE EN FALSO.
 *
 *  Se RENOMBRA y no se borra: si algo se corta a la mitad, la columna sigue entera bajo el otro
 *  nombre y `setUp()` la devuelve sola en la corrida siguiente.
 *
 * @group recargos_en_precios
 */
class Guarda_de_esquema_de_la_columna_Test extends RecargosEnPreciosTestCase
{
    /** El nombre al que se corre la columna para que la guarda no la encuentre. */
    const COLUMNA_ESCONDIDA = 'price_sin_recargos_de_venta_escondida_por_el_test';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        RecargosEnPreciosEsquemaHelper::olvidar();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        RecargosEnPreciosEsquemaHelper::olvidar();

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
     * Renombra la columna en cada una de las ocho tablas donde este con el nombre `$de`.
     *
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    protected function renombrar_columnas($de, $a)
    {
        $pdo = $this->conexion_aparte();

        foreach (RecargosEnPreciosEsquemaHelper::TABLAS as $tabla) {

            $existe = $pdo->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() ".
                "AND TABLE_NAME = '".$tabla."' AND COLUMN_NAME = '".$de."'"
            )->fetchColumn();

            if ((int) $existe === 0) {
                continue;
            }

            $pdo->exec('ALTER TABLE `'.$tabla.'` RENAME COLUMN `'.$de.'` TO `'.$a.'`');
        }

        RecargosEnPreciosEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se corto con la columna escondida, se devuelve.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        foreach (RecargosEnPreciosEsquemaHelper::TABLAS as $tabla) {

            if (Schema::hasColumn($tabla, self::COLUMNA_ESCONDIDA)) {

                $this->renombrar_columnas(self::COLUMNA_ESCONDIDA, RecargosEnPreciosEsquemaHelper::COLUMNA);

                return;
            }
        }
    }

    /**
     * Corre el cuerpo con la base SIN la columna en ninguna de las ocho tablas.
     *
     * Antes del cuerpo verifica el escenario: sin esa asercion de control, un test que no lograra
     * esconder la columna pasaria igual —con la columna puesta— sin probar nada.
     *
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function sin_la_columna($cuerpo)
    {
        DB::rollBack();

        $this->renombrar_columnas(RecargosEnPreciosEsquemaHelper::COLUMNA, self::COLUMNA_ESCONDIDA);

        DB::beginTransaction();

        try {

            foreach (RecargosEnPreciosEsquemaHelper::TABLAS as $tabla) {
                $this->assertFalse(
                    RecargosEnPreciosEsquemaHelper::hay_columna($tabla),
                    'El escenario no se armo: la guarda sigue viendo la columna en '.$tabla.'.'
                );
            }

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->renombrar_columnas(self::COLUMNA_ESCONDIDA, RecargosEnPreciosEsquemaHelper::COLUMNA);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Test 1 — sin la columna se puede GUARDAR una venta con la opcion prendida (la clave viaja y
     * se descarta) y ABRIRLA despues: es el camino de la lectura, el que mas duele.
     *
     * @test
     */
    public function sin_la_columna_se_puede_vender_y_abrir_la_venta()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $articulo = $self->articulo_centinela();
            $servicio = $self->servicio(50);
            $combo    = $self->combo(200);
            $promo    = $self->promocion(300);
            $recargo  = $self->recargo();

            $response = $self->postJson('api/sale', $self->payload_venta([
                $self->item_vender('article', $articulo->id, 110, 100),
                $self->item_vender('service', $servicio->id, 55, 50),
                $self->item_vender('combo', $combo->id, 220, 200),
                $self->item_vender('promocion_vinoteca', $promo->id, 330, 300),
            ], 715.00, 1, [$self->recargo_del_payload($recargo)]));

            $response->assertStatus(201);

            $sale_id = $response->json('model.id');

            $self->assertCount(1, $response->json('model.articles'), 'La venta se guardo con su renglon.');

            $self->assertArrayNotHasKey(
                'price_sin_recargos_de_venta',
                $response->json('model.articles.0.pivot'),
                'Sin la columna el pivot no la trae (y el SELECT no la nombro).'
            );

            $self->getJson('api/sale/'.$sale_id)->assertStatus(200);

            /* Y el update-prices, que escribe el pivot existente. */
            $self->putJson('api/sale/update-prices/'.$sale_id, [
                'items' => [[
                    'is_article'                => true,
                    'id'                        => $articulo->id,
                    'price_vender'              => 132,
                    'price_vender_sin_recargos' => 120,
                ]],
            ])->assertStatus(200);

            $self->assertEqualsWithDelta(132, (float) Sale::find($sale_id)->articles()->first()->pivot->price, self::DELTA);
        });
    }

    /**
     * Test 2 — sin la columna se puede PRESUPUESTAR, ACTUALIZAR y CONFIRMAR.
     *
     * @test
     */
    public function sin_la_columna_se_puede_presupuestar_actualizar_y_confirmar()
    {
        $self = $this;

        $this->sin_la_columna(function () use ($self) {

            $articulo = $self->articulo_centinela();
            $servicio = $self->servicio(50);
            $recargo  = $self->recargo();

            $response = $self->postJson('api/budget', $self->payload_presupuesto(165.00, 1, [$self->recargo_del_payload($recargo)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 110, 100, false)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 55, 50)],
            ]));

            $response->assertStatus(201);

            $budget_id = $response->json('model.id');

            /* Update SIN la clave: el camino que arma el snapshot de bases antes del detach. */
            $self->putJson('api/budget/'.$budget_id, $self->payload_presupuesto(165.00, 1, [$self->recargo_del_payload($recargo)], [
                'articles' => [$self->renglon_articulo_presupuesto($articulo->id, 110, false, true)],
                'services' => [$self->renglon_pivot_presupuesto($servicio->id, 55, false)],
            ]))->assertStatus(200);

            $self->postJson('api/budget/'.$budget_id.'/confirmar')->assertStatus(200);

            $self->assertNotNull(Sale::where('budget_id', $budget_id)->first(), 'Confirmar tiene que haber creado la venta.');

            $self->getJson('api/budget/'.$budget_id)->assertStatus(200);
        });
    }

    /**
     * Test 3 — la guarda corta con una excepcion ante una tabla que no es una de las ocho, en vez
     * de responder `false` en silencio (un nombre mal escrito dejaria la base en NULL para siempre
     * sin que nada lo denuncie).
     *
     * @test
     */
    public function una_tabla_desconocida_es_un_error_de_programacion()
    {
        $this->expectException(\InvalidArgumentException::class);

        RecargosEnPreciosEsquemaHelper::hay_columna('article_sales');
    }

    /**
     * Test 4 — CON la columna, la guarda la ve en las ocho tablas. Es la no-regresion: una guarda
     * que la apagara siempre pasaria los tests de arriba, y el resto de la suite daria rojo por
     * otro lado sin decir por que.
     *
     * @test
     */
    public function con_la_columna_la_guarda_la_ve_en_las_ocho_tablas()
    {
        foreach (RecargosEnPreciosEsquemaHelper::TABLAS as $tabla) {
            $this->assertTrue(
                RecargosEnPreciosEsquemaHelper::hay_columna($tabla),
                'La base del slot tiene que tener la columna en '.$tabla.' (¿falta migrar?).'
            );
        }
    }
}
