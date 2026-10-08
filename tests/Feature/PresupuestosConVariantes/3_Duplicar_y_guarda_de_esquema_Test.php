<?php

namespace Tests\Feature\PresupuestosConVariantes;

use App\Http\Controllers\Helpers\Budget\VarianteEnPresupuestoEsquemaHelper;
use App\Models\Budget;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo 3 — duplicar un presupuesto con variantes y la guarda de esquema de las dos columnas
 * nuevas de `article_budget` (misión presupuestos-con-variantes, 8/10/2026). Casos 10 y 11 del plan.
 *
 * ⚠️ La guarda se prueba como `Presupuestos/4_Guarda_de_esquema_de_combos_Test`: el DDL hace COMMIT
 * implícito de la sesión que lo corre, así que el rename de las columnas va por una conexión PDO
 * APARTE, y se cierra la transacción del trait ANTES (en MySQL 8 `information_schema` responde con
 * el snapshot de la transacción abierta). Se RENOMBRAN y no se borran: restaurar es un statement y,
 * si algo se cortara a la mitad, `setUp()` las devuelve solas en la corrida siguiente.
 *
 * @group presupuestos_con_variantes
 */
class Duplicar_y_guarda_de_esquema_de_variantes_Test extends PresupuestosConVariantesTestCase
{
    /** Nombres a los que se corren las columnas mientras corre el test de la guarda. */
    const COLUMNA_ESCONDIDA = 'zz_article_variant_id_sin_guarda';
    const DESCRIPCION_ESCONDIDA = 'zz_variant_description_sin_guarda';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_columnas_si_quedaron_escondidas();

        VarianteEnPresupuestoEsquemaHelper::olvidar();
    }

    /**
     * 🔴 El memo de la guarda es estático: vive todo el proceso de PHPUnit. Si quedara en `false`,
     * todos los tests que corren después verían las columnas como inexistentes.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        VarianteEnPresupuestoEsquemaHelper::olvidar();

        parent::tearDown();
    }

    /**
     * Caso 10 — duplicar conserva las variantes de cada renglón.
     *
     * @test
     */
    public function duplicar_conserva_las_variantes()
    {
        $this->dar_extension_duplicar();

        $e = $this->escenario();

        $origen = $this->crear_presupuesto([
            $this->renglon_plano($e['articulo'], $e['m'], 2),
            $this->renglon_plano($e['articulo'], $e['l'], 3),
            $this->renglon_plano($e['testigo'], null, 1, 100),
        ], $e['suc2']->id);

        $respuesta = $this->postJson('api/budget/'.$origen->id.'/duplicate');

        $respuesta->assertStatus(201);

        $copia = Budget::find($respuesta->json('model.id'));

        $this->assertNotNull($copia);
        $this->assertNotEquals($origen->id, $copia->id);

        $this->assertCount(2, $this->filas_presupuesto($copia, $e['articulo']));
        $this->assertEquals(2.0, (float) $this->fila_presupuesto_de($copia, $e['articulo'], $e['m'])->amount, 'La copia perdió la M.');
        $this->assertEquals(3.0, (float) $this->fila_presupuesto_de($copia, $e['articulo'], $e['l'])->amount, 'La copia perdió la L.');
        $this->assertEquals('Talle L', $this->fila_presupuesto_de($copia, $e['articulo'], $e['l'])->variant_description);
        $this->assertNotNull($this->fila_presupuesto_de($copia, $e['testigo'], null), 'El testigo sigue sin variante.');
    }

    /**
     * Caso 11 — sin las columnas (la ventana del deploy: archivos nuevos, migración todavía no
     * corrida) el alta con variante, la lectura y la confirmación siguen andando, igual que hoy.
     *
     * @test
     */
    public function sin_las_columnas_el_alta_la_lectura_y_la_confirmacion_siguen_andando()
    {
        DB::rollBack();

        $this->esconder_columnas();

        DB::beginTransaction();

        try {

            $this->sembrar_estados();

            $this->assertFalse(
                VarianteEnPresupuestoEsquemaHelper::hay_columna(),
                'El escenario no se armó: la guarda sigue viendo la columna.'
            );

            /* Control: nombrar la columna tiene que reventar de verdad, como en un cliente sin migrar. */
            $exploto = false;

            try {
                DB::table('article_budget')->select('article_variant_id')->limit(1)->get();
            } catch (\Illuminate\Database\QueryException $ex) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'El escenario no se armó: la columna todavía se puede leer.');

            $e = $this->escenario();

            $budget = $this->crear_presupuesto([
                $this->renglon_plano($e['articulo'], $e['m'], 2),
                $this->renglon_plano($e['testigo'], null, 1, 100),
            ], $e['suc2']->id);

            $this->assertEquals(2, DB::table('article_budget')->where('budget_id', $budget->id)->count());

            $this->getJson('api/budget/'.$budget->id)->assertStatus(200);

            $this->actualizar_presupuesto($budget, [
                $this->renglon_cargado($e['articulo'], $e['m'], 2),
                $this->renglon_cargado($e['testigo'], null, 1, 100),
            ])->assertStatus(200);

            $this->postJson('api/budget/'.$budget->id.'/confirmar')->assertStatus(200);

            $this->assertNotNull(Sale::where('budget_id', $budget->id)->first(), 'Sin las columnas, confirmar tiene que crear la venta igual.');

        } finally {

            DB::rollBack();

            $this->devolver_columnas();

            /* Para que el rollback del tearDown del trait tenga una transacción que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Control final: las columnas quedaron en su lugar.
     *
     * @test
     */
    public function las_columnas_quedaron_en_su_lugar()
    {
        $this->assertTrue(Schema::hasColumn('article_budget', 'article_variant_id'));
        $this->assertTrue(Schema::hasColumn('article_budget', 'variant_description'));
        $this->assertFalse(Schema::hasColumn('article_budget', Self::COLUMNA_ESCONDIDA));
    }

    /**
     * Conexión PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transacción del
     * test. Se arma con la config de Laravel.
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
     * Corre las dos columnas a nombres que la guarda no busca.
     *
     * @return void
     */
    protected function esconder_columnas()
    {
        $this->conexion_aparte()->exec(
            'ALTER TABLE `article_budget`'
            .' RENAME COLUMN `article_variant_id` TO `'.Self::COLUMNA_ESCONDIDA.'`,'
            .' RENAME COLUMN `variant_description` TO `'.Self::DESCRIPCION_ESCONDIDA.'`'
        );

        VarianteEnPresupuestoEsquemaHelper::olvidar();
    }

    /**
     * Las devuelve a su nombre.
     *
     * @return void
     */
    protected function devolver_columnas()
    {
        $this->conexion_aparte()->exec(
            'ALTER TABLE `article_budget`'
            .' RENAME COLUMN `'.Self::COLUMNA_ESCONDIDA.'` TO `article_variant_id`,'
            .' RENAME COLUMN `'.Self::DESCRIPCION_ESCONDIDA.'` TO `variant_description`'
        );

        VarianteEnPresupuestoEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se cortó entre los dos renames, las columnas
     * quedaron con el nombre escondido. Se devuelven solas antes de que ningún test las necesite.
     *
     * @return void
     */
    protected function restaurar_columnas_si_quedaron_escondidas()
    {
        if (!Schema::hasColumn('article_budget', Self::COLUMNA_ESCONDIDA)) {
            return;
        }

        DB::rollBack();

        $this->devolver_columnas();

        DB::beginTransaction();
    }
}
