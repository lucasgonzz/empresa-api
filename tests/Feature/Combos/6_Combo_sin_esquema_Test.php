<?php

namespace Tests\Feature\Combos;

use App\Http\Controllers\Helpers\ArticleHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoEsquemaHelper;
use App\Http\Controllers\Helpers\combo\ComboCalculadoHelper;
use App\Models\Combo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🔴 LA GUARDA DE ESQUEMA DE LOS COMBOS CALCULADOS (misión combos-calculados, 30/9/2026).
 *
 * EL AGUJERO QUE FIJAN ESTOS TESTS ES DE DESPLIEGUE, no de funcionalidad. Un deploy de empresa sube
 * los archivos y DESPUÉS corre las migraciones: entre las dos cosas hay una ventana en la que el
 * cliente tiene el código nuevo y NO tiene ni las tres columnas de `combos` ni la tabla
 * `combo_price_type`. Y los ganchos de esta misión están en el corazón del sistema: el guardado de
 * TODO artículo (`setFinalPrice`), el cierre de las importaciones, el listado de combos y el
 * alta y la edición de un combo. Sin la guarda, en esa ventana no se puede guardar un artículo ni
 * abrir el ABM de combos.
 *
 * Cada test esconde el esquema (una vez la columna y otra la tabla, porque una base a medio migrar
 * se trata entera como "no disponible") y recorre los caminos que lo tocan, comprobando que el
 * sistema se comporta como el de ANTES de la misión: nada revienta y nada se calcula.
 *
 * ⚠️ COMO SE ESCONDE, y por qué el test se da vuelta para hacerlo (mismo mecanismo que
 * `Presupuestos\Guarda_de_esquema_de_combos_Test`): el DDL de MySQL hace COMMIT IMPLÍCITO de la
 * sesión que lo ejecuta, y `information_schema` se sirve del diccionario de datos, que dentro de una
 * transacción REPEATABLE READ ya abierta responde con el SNAPSHOT. Por eso el rename va por una
 * conexión PDO aparte, se cierra la transacción del trait ANTES de esconder y se abre una NUEVA
 * después; sin eso todos estos tests darían verde en falso, con el esquema puesto. Se RENOMBRA y no
 * se borra: restaurar es un statement y si algo se corta a la mitad los datos siguen enteros bajo el
 * otro nombre (y `setUp()` los devuelve solos en la corrida siguiente).
 *
 * No hay DatabaseTransactions "de verdad" acá: lo que el test escribe lo escribe adentro de la
 * transacción nueva, que se revierte igual.
 *
 * @group combos-calculados
 */
class Combo_sin_esquema_Test extends ComboCalculadoTestCase
{
    /** Nombre al que se corre la columna mientras corre cada test. */
    const COLUMNA_ESCONDIDA = 'calcular_desde_articulos_oculta';

    /** Nombre al que se corre la tabla de precios por lista mientras corre cada test. */
    const TABLA_ESCONDIDA = 'combo_price_type_oculta';

    protected function setUp(): void
    {
        parent::setUp();

        $this->devolver_todo_si_quedo_escondido();
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Los caminos
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @test
     */
    public function sin_la_columna_el_esquema_no_esta_disponible_y_todo_es_un_no_op()
    {
        $this->con_esquema_escondido('columna', function () {
            $this->recorrer_los_caminos();
        });
    }

    /**
     * @test
     */
    public function sin_la_tabla_de_precios_por_lista_el_esquema_no_esta_disponible_y_todo_es_un_no_op()
    {
        $this->con_esquema_escondido('tabla', function () {
            $this->recorrer_los_caminos();
        });
    }

    /**
     * 🔴 Sin esquema, el disparador del guardado de un artículo no consulta `article_combo` ni deja
     * nada en el log. Que no rompa ya lo garantiza el try/catch del helper; lo que garantiza LA
     * GUARDA es que en la ventana del deploy no haya una consulta fallida y una línea de advertencia
     * por CADA artículo que se guarde (una importación de 20.000 filas son 20.000 líneas).
     *
     * @test
     */
    public function sin_esquema_el_disparador_no_consulta_ni_ensucia_el_log()
    {
        $this->con_esquema_escondido('columna', function () {

            $articulo = $this->nuevo_articulo();

            \Illuminate\Support\Facades\Log::spy();

            $consultas = [];

            DB::listen(function ($consulta) use (&$consultas) {
                $consultas[] = $consulta->sql;
            });

            ComboCalculadoHelper::recalcular_por_articulos([$articulo->id]);
            ComboCalculadoHelper::recalcular_de_un_dueno(self::DUENO);

            $de_combos = array_filter($consultas, function ($sql) {
                return strpos($sql, 'article_combo') !== false || strpos($sql, '`combos`') !== false;
            });

            $this->assertSame([], array_values($de_combos), 'sin esquema no se toca ni article_combo ni combos');

            \Illuminate\Support\Facades\Log::shouldNotHaveReceived('warning');
        });
    }

    /**
     * Después de devolver el esquema la guarda vuelve a verlo (el memo se olvida y el `false` no
     * queda clavado): el mismo proceso que arrancó en la ventana empieza a calcular apenas se migra.
     *
     * @test
     */
    public function al_devolver_el_esquema_la_guarda_lo_vuelve_a_ver()
    {
        $this->con_esquema_escondido('columna', function () {
            $this->assertFalse(ComboCalculadoEsquemaHelper::disponible());
        });

        $this->assertTrue(ComboCalculadoEsquemaHelper::disponible());
    }

    /**
     * Los caminos que tocan el esquema, todos con la base sin él.
     *
     * @return void
     */
    protected function recorrer_los_caminos()
    {
        $this->assertFalse(ComboCalculadoEsquemaHelper::disponible(), 'el escenario tiene que ser "sin esquema"');

        $articulo = $this->nuevo_articulo(['costo_real' => 100, 'final_price' => 250, 'cost' => 100, 'percentage_gain' => 50]);

        /*
         * Un combo cargado como siempre (sin columnas nuevas): se crea con el modelo, que no
         * nombra ninguna. Es el combo que ya tiene un cliente al desplegar.
         */
        $combo = Combo::create([
            'num'     => 971000 + rand(1, 999),
            'name'    => 'zz Combo en la ventana del deploy ' . uniqid(),
            'user_id' => self::DUENO,
            'cost'    => 7,
            'price'   => 9,
        ]);

        $combo->articles()->attach($articulo->id, ['amount' => 2]);

        // 1. Los helpers de la misión no hacen nada.
        $this->assertSame(0, ComboCalculadoHelper::recalcular_por_articulos([$articulo->id]));
        $this->assertSame(0, ComboCalculadoHelper::recalcular_de_un_dueno(self::DUENO));
        $this->assertNull(ComboCalculadoHelper::guardar($combo));
        ComboCalculadoHelper::limpiar_precios_por_lista($combo->id);

        // 2. El listado y la lectura de combos (Combo::scopeWithAll) no piden la tabla que falta.
        $listado = Combo::where('user_id', self::DUENO)->withAll()->get();
        $this->assertGreaterThanOrEqual(1, count($listado));

        $this->get('api/combo')->assertStatus(200);

        $json = $this->get('api/combo/' . $combo->id);
        $json->assertStatus(200);
        $this->assertArrayNotHasKey('price_types', $json->json('model'), 'sin la tabla no se pide la relación');
        $this->assertSame($combo->name, $json->json('model.name'), 'el resto del combo se serializa');

        // 3. El stock, que no depende del esquema nuevo, sigue funcionando.
        $this->assertSame(25, $json->json('model.stock_disponible'), 'stock 50 / 2 por combo');

        // 4. Guardar un artículo (el gancho de setFinalPrice) no revienta ni recalcula el combo.
        ArticleHelper::setFinalPrice($articulo, self::DUENO, $this->dueno);

        $this->assertSame(7.0, $this->costo_en_base($combo), 'el combo no se recalcula sin esquema');
        $this->assertSame(9.0, $this->precio_en_base($combo));

        // 5. El alta con las claves nuevas (un SPA nuevo contra una base sin migrar) no las nombra.
        $alta = $this->post('api/combo', [
            'name'                     => 'zz Combo creado sin esquema ' . uniqid(),
            'cost'                     => 11,
            'price'                    => 22,
            'calcular_desde_articulos' => 1,
            'descuento_tipo'           => 'porcentaje',
            'descuento_valor'          => 10,
            'articles'                 => [['id' => $articulo->id, 'pivot' => ['amount' => 1]]],
        ]);

        $alta->assertStatus(201);

        $nuevo_id = $alta->json('model.id');

        $this->assertSame(11.0, $this->costo_en_base($nuevo_id), 'sin esquema el combo nace manual, con los números del request');
        $this->assertSame(22.0, $this->precio_en_base($nuevo_id));

        // 6. La edición con las claves nuevas tampoco las nombra y no rechaza un descuento que no existe.
        $this->put('api/combo/' . $nuevo_id, [
            'name'                     => 'zz Combo editado sin esquema',
            'cost'                     => 33,
            'price'                    => 44,
            'calcular_desde_articulos' => 1,
            'descuento_tipo'           => 'porcentaje',
            'descuento_valor'          => 500,
            'articles'                 => [['id' => $articulo->id, 'pivot' => ['amount' => 1]]],
        ])->assertStatus(200);

        $this->assertSame(33.0, $this->costo_en_base($nuevo_id));
        $this->assertSame(44.0, $this->precio_en_base($nuevo_id));

        // 7. Borrar el artículo (el gancho de ArticleController::destroy) y la red de seguridad.
        $this->delete('api/article/' . $articulo->id)->assertStatus(200);

        $this->assertSame(0, Artisan::call('combos:recalcular', ['--user' => self::DUENO]));
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  El esquema escondido
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Corre el cuerpo con la base SIN la columna (o SIN la tabla), verificando el escenario antes.
     *
     * @param  string    $que     'columna' | 'tabla'
     * @param  \Closure  $cuerpo
     * @return void
     */
    protected function con_esquema_escondido($que, $cuerpo)
    {
        DB::rollBack();

        $this->esconder($que);

        DB::beginTransaction();

        try {

            /*
             * Control del escenario: lo que se escondió tiene que reventar de verdad al consultarlo.
             * Eso es exactamente lo que le pasa a un cliente que corre esta versión antes de migrar;
             * si no explota, el esquema sigue puesto y el test no mide nada.
             */
            $exploto = false;

            try {

                if ($que === 'columna') {
                    DB::table('combos')->where('calcular_desde_articulos', 1)->count();
                } else {
                    DB::table('combo_price_type')->count();
                }

            } catch (\Illuminate\Database\QueryException $e) {
                $exploto = true;
            }

            $this->assertTrue($exploto, 'El escenario no se armó: el esquema escondido todavía responde.');

            $cuerpo();

        } finally {

            DB::rollBack();

            $this->devolver($que);

            /* Para que el rollback del tearDown del trait tenga una transacción que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Conexión PDO aparte contra la MISMA base, para correr el DDL sin cerrar la transacción del
     * test. Se arma con la config de Laravel, nunca con valores escritos a mano.
     *
     * @return \PDO
     */
    protected function conexion_aparte()
    {
        $config = config('database.connections.' . config('database.default'));

        $dsn = 'mysql:host=' . $config['host'] . ';port=' . $config['port'] . ';dbname=' . $config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        /* Sin esto, un metadata lock cuelga la suite: el default de MySQL para MDL es 1 año. */
        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        return $pdo;
    }

    /**
     * @param  string  $que  'columna' | 'tabla'
     * @return void
     */
    protected function esconder($que)
    {
        $pdo = $this->conexion_aparte();

        if ($que === 'columna') {
            $pdo->exec('ALTER TABLE `combos` RENAME COLUMN `' . ComboCalculadoEsquemaHelper::COLUMNA . '` TO `' . self::COLUMNA_ESCONDIDA . '`');
        } else {
            $pdo->exec('RENAME TABLE `' . ComboCalculadoEsquemaHelper::TABLA_DE_PRECIOS . '` TO `' . self::TABLA_ESCONDIDA . '`');
        }

        ComboCalculadoEsquemaHelper::olvidar();
    }

    /**
     * @param  string  $que  'columna' | 'tabla'
     * @return void
     */
    protected function devolver($que)
    {
        $pdo = $this->conexion_aparte();

        if ($que === 'columna') {
            $pdo->exec('ALTER TABLE `combos` RENAME COLUMN `' . self::COLUMNA_ESCONDIDA . '` TO `' . ComboCalculadoEsquemaHelper::COLUMNA . '`');
        } else {
            $pdo->exec('RENAME TABLE `' . self::TABLA_ESCONDIDA . '` TO `' . ComboCalculadoEsquemaHelper::TABLA_DE_PRECIOS . '`');
        }

        ComboCalculadoEsquemaHelper::olvidar();
    }

    /**
     * Red de seguridad: si una corrida anterior se cortó entre el esconder y el devolver, el
     * esquema quedó con el nombre escondido. Se devuelve solo antes de que ningún test lo necesite.
     *
     * @return void
     */
    protected function devolver_todo_si_quedo_escondido()
    {
        if (Schema::hasColumn('combos', self::COLUMNA_ESCONDIDA)) {
            $this->devolver('columna');
        }

        if (Schema::hasTable(self::TABLA_ESCONDIDA)) {
            $this->devolver('tabla');
        }
    }
}
