<?php

namespace Tests\Feature\Puntos;

use App\Http\Controllers\Helpers\DemoSetupHelper;
use App\Http\Controllers\Helpers\UserSetupHelper;
use App\Models\ExtencionEmpresa;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;

/**
 * Archivo 1 — el esquema del módulo, el bloqueante que se arregló y el gate por extensión.
 *
 * Los tipos se leen de `information_schema` y no se asume que las migraciones digan la verdad: hay
 * columnas alteradas después de su migración original, y este módulo nació justamente de una de
 * ellas (`sales.price_type_id`, que era `tinyint(1)` y aguantaba hasta la lista de precio N° 127).
 *
 * El 403 de los seis endpoints no es decorativo: es la única aserción que prueba que el middleware
 * `check_extencion_empresa:puntos_clientes` está de verdad conectado al grupo de rutas. Una ruta
 * que se agregue afuera del grupo queda abierta para todos los comercios y no rompe ningún test
 * funcional.
 */
class Esquema_y_extencion_Test extends PuntosTestCase
{
    /**
     * Tipo de columna tal como lo ve MySQL (`bigint unsigned`, `decimal(20,2)`, ...).
     *
     * @param  string  $tabla
     * @param  string  $columna
     * @return string|null
     */
    protected function tipo_de_columna($tabla, $columna)
    {
        $fila = DB::selectOne(
            'SELECT COLUMN_TYPE AS tipo FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$tabla, $columna]
        );

        return $fila ? $fila->tipo : null;
    }

    /**
     * Columnas de un índice, en orden, o null si el índice no existe.
     *
     * @param  string  $tabla
     * @param  string  $nombre
     * @return array|null
     */
    protected function columnas_del_indice($tabla, $nombre)
    {
        $filas = DB::select("SHOW INDEX FROM {$tabla} WHERE Key_name = ?", [$nombre]);

        if (empty($filas)) {
            return null;
        }

        usort($filas, function ($a, $b) {
            return $a->Seq_in_index - $b->Seq_in_index;
        });

        return array_map(function ($fila) {
            return $fila->Column_name;
        }, $filas);
    }

    /**
     * Las cuatro tablas nuevas con el tipo exacto de cada columna. Los tipos de las claves lógicas
     * espejan su tabla de origen: `users.id` es `int unsigned` (su migración usa increments(), no
     * bigIncrements) y `clients.id`, `sales.id` y `price_types.id` son `bigint unsigned`. Que esto
     * quede clavado es el punto: una FK más angosta que su fuente es la clase de error que originó
     * el bloqueante de esta misión.
     *
     * @group puntos
     * @test
     */
    public function las_cuatro_tablas_nuevas_tienen_sus_columnas_con_el_tipo_de_su_fuente()
    {
        $esperados = [
            'sistemas_de_puntos' => [
                'id'                => 'bigint unsigned',
                'user_id'           => 'int unsigned',
                'nombre'            => 'varchar(191)',
                'activo'            => 'tinyint(1)',
                'puntos_cada'       => 'decimal(20,2)',
                'puntos_por_tramo'  => 'decimal(20,2)',
                'valor_punto'       => 'decimal(20,2)',
                'vencimiento_meses' => 'smallint unsigned',
                'minimo_canje'      => 'decimal(20,2)',
                'tope_porcentaje'   => 'decimal(5,2)',
            ],
            'price_type_sistema_de_puntos' => [
                'id'                   => 'bigint unsigned',
                'sistema_de_puntos_id' => 'bigint unsigned',
                'price_type_id'        => 'bigint unsigned',
                'multiplicador'        => 'decimal(6,2)',
            ],
            'movimiento_puntos' => [
                'id'                   => 'bigint unsigned',
                'user_id'              => 'int unsigned',
                'client_id'            => 'bigint unsigned',
                'sistema_de_puntos_id' => 'bigint unsigned',
                'tipo'                 => 'varchar(20)',
                'puntos'               => 'decimal(20,2)',
                'sale_id'              => 'bigint unsigned',
                'price_type_id'        => 'bigint unsigned',
                'monto_base'           => 'decimal(20,2)',
                'detalle'              => 'varchar(191)',
                'vence_at'             => 'datetime',
                'consumido'            => 'decimal(20,2)',
                'anulado_at'           => 'datetime',
                'employee_id'          => 'int unsigned',
            ],
            'movimiento_punto_consumos' => [
                'id'                    => 'bigint unsigned',
                'movimiento_origen_id'  => 'bigint unsigned',
                'movimiento_consumo_id' => 'bigint unsigned',
                'puntos'                => 'decimal(20,2)',
            ],
        ];

        foreach ($esperados as $tabla => $columnas) {

            $this->assertTrue(Schema::hasTable($tabla), 'Falta la tabla '.$tabla.'.');

            foreach ($columnas as $columna => $tipo) {
                $this->assertEquals(
                    $tipo,
                    $this->tipo_de_columna($tabla, $columna),
                    $tabla.'.'.$columna.' no tiene el tipo esperado.'
                );
            }
        }
    }

    /**
     * Las dos columnas del canje en `sales`.
     *
     * Van en `sales` y no solo en el libro de movimientos porque `sales.total` baja por el canje y
     * cualquiera que lea la venta —el PDF, el ticket, la contabilidad— tiene que poder explicar la
     * diferencia sin joinear el libro de puntos.
     *
     * @group puntos
     * @test
     */
    public function sales_tiene_las_dos_columnas_del_canje()
    {
        $this->assertEquals('decimal(20,2)', $this->tipo_de_columna('sales', 'puntos_canjeados'));
        $this->assertEquals('decimal(20,2)', $this->tipo_de_columna('sales', 'descuento_puntos'));

        foreach (['puntos_canjeados', 'descuento_puntos'] as $columna) {

            $nulabilidad = DB::selectOne(
                "SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = ?",
                [$columna]
            );

            $this->assertEquals(
                'YES',
                $nulabilidad->n,
                'sales.'.$columna.' tiene que aceptar null: la abrumadora mayoría de las ventas no canjea nada.'
            );
        }
    }

    /**
     * 🔴 EL BLOQUEANTE DE LA MISIÓN.
     *
     * `sales.price_type_id` nació como `$table->boolean()`, o sea `tinyint(1)`, y aguantaba hasta
     * la lista de precio N° 127. Su valor sale de `price_types.id` (bigint unsigned) o de
     * `clients.price_type_id` (int unsigned): las dos son más anchas. El motor de puntos decide
     * con esa columna si un renglón cobra o no, así que un id truncado sería plata mal repartida.
     *
     * No alcanza con mirar el tipo: se escribe un id de 500 y se vuelve a leer. Con `tinyint(1)`
     * MySQL lo habría guardado como 127 (o rechazado, según el modo estricto), y el test lo diría.
     *
     * @group puntos
     * @test
     */
    public function sales_price_type_id_es_int_unsigned_y_aguanta_un_id_mayor_a_127()
    {
        $this->assertEquals(
            'int unsigned',
            $this->tipo_de_columna('sales', 'price_type_id'),
            'sales.price_type_id volvió a quedar más angosta que price_types.id.'
        );

        $venta = Sale::create([
            'user_id'       => $this->comercio()->id,
            'moneda_id'     => 1,
            'total'         => 1000,
            'terminada'     => 1,
            'price_type_id' => 500,
        ]);

        $leido = DB::table('sales')->where('id', $venta->id)->value('price_type_id');

        $this->assertEquals(
            500,
            (int) $leido,
            'sales.price_type_id truncó el id 500: la columna sigue siendo más angosta que su fuente.'
        );
    }

    /**
     * Los cuatro índices del libro de movimientos, con el unique a la cabeza.
     *
     * 🔴 El unique `(sale_id, tipo, price_type_id)` es la RED contra los puntos duplicados:
     * `CurrentAcountHelper::checkPagos()` re-imputa la cuenta corriente entera en cada guardado de
     * venta, cada alta y baja de pago y en dos jobs de fondo, así que la transición "el débito
     * llegó a pagado" se dispara N veces por UN solo hecho económico. El reconciliador ya es
     * idempotente por código; esto lo respalda en la base.
     *
     * @group puntos
     * @test
     */
    public function el_libro_de_movimientos_tiene_su_unique_y_sus_tres_indices()
    {
        $this->assertEquals(
            ['sale_id', 'tipo', 'price_type_id'],
            $this->columnas_del_indice('movimiento_puntos', 'movimiento_puntos_sale_tipo_price_type_unique'),
            'Falta el unique que impide dos movimientos del mismo tipo para la misma venta y lista.'
        );

        $unicos = DB::select("SHOW INDEX FROM movimiento_puntos WHERE Key_name = 'movimiento_puntos_sale_tipo_price_type_unique'");

        $this->assertEquals(
            0,
            (int) $unicos[0]->Non_unique,
            'El índice (sale_id, tipo, price_type_id) existe pero NO es único: no protege de nada.'
        );

        $esperados = [
            'movimiento_puntos_user_client_created_index' => ['user_id', 'client_id', 'created_at'],
            'movimiento_puntos_user_tipo_created_index'   => ['user_id', 'tipo', 'created_at'],
            'movimiento_puntos_tipo_vence_index'          => ['tipo', 'vence_at'],
        ];

        foreach ($esperados as $nombre => $columnas) {
            $this->assertEquals(
                $columnas,
                $this->columnas_del_indice('movimiento_puntos', $nombre),
                'El índice '.$nombre.' no existe o no tiene las columnas esperadas.'
            );
        }
    }

    /**
     * 🔴 `price_type_id` del libro es NOT NULL con centinela 0, y eso es lo que hace que el unique
     * sirva: MySQL deja pasar N filas con NULL en un índice único, así que con la columna nullable
     * la red no protegería nada justo en el caso más común (el comercio que no usa listas).
     *
     * @group puntos
     * @test
     */
    public function el_price_type_id_del_libro_es_not_null_con_centinela_cero()
    {
        $fila = DB::selectOne(
            "SELECT IS_NULLABLE AS nulable, COLUMN_DEFAULT AS valor
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'movimiento_puntos'
             AND COLUMN_NAME = 'price_type_id'"
        );

        $this->assertEquals('NO', $fila->nulable, 'movimiento_puntos.price_type_id tiene que ser NOT NULL.');
        $this->assertEquals(0, (int) $fila->valor, 'El centinela "sin lista de precio" tiene que ser 0.');
    }

    /**
     * Dos corridas del seeder standalone dejan UNA sola fila con el slug y no tocan el resto del
     * catálogo. `ExtencionSeeder` hace `create()` en un foreach —sin firstOrCreate— así que
     * correrlo dos veces duplica el catálogo entero; el standalone de esta misión, que es el que
     * Lucas corre en las bases de los clientes, tiene que ser idempotente.
     *
     * @group puntos
     * @test
     */
    public function el_seeder_de_la_extencion_es_idempotente_y_no_altera_el_resto_del_catalogo()
    {
        ExtencionEmpresa::where('slug', self::SLUG)->delete();

        /*
         * Fila testigo: la base del slot tiene `extencion_empresas` VACÍA, y contra una tabla
         * vacía la comparación del final sería [] contra [] — una aserción que pasa sin medir nada.
         */
        // forceCreate: el modelo no declara $fillable y acá no rige el Model::unguarded() del
        // comando db:seed.
        $testigo = ExtencionEmpresa::forceCreate([
            'name' => 'Testigo de catalogo (solo test de puntos)',
            'slug' => 'testigo_de_catalogo_del_test_de_puntos',
        ]);

        $total_antes = ExtencionEmpresa::count();
        $otras_antes = $this->foto_del_resto_del_catalogo();

        $this->assertContains(
            $testigo->id.'|testigo_de_catalogo_del_test_de_puntos|Testigo de catalogo (solo test de puntos)',
            $otras_antes,
            'La foto del catálogo no incluye la fila testigo: la comparación del final no mediría nada.'
        );

        $this->correr_seeder();

        $total_primera = ExtencionEmpresa::count();

        $this->assertEquals(1, ExtencionEmpresa::where('slug', self::SLUG)->count());
        $this->assertEquals($total_antes + 1, $total_primera);

        $this->correr_seeder();

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El seeder duplicó la extensión al correrlo dos veces: no es idempotente.'
        );
        $this->assertEquals(
            $total_primera,
            ExtencionEmpresa::count(),
            'La segunda corrida cambió la cantidad de filas de extencion_empresas.'
        );
        $this->assertEquals($otras_antes, $this->foto_del_resto_del_catalogo());
    }

    /**
     * La fila que siembra es la que esperan los tres gates: el middleware de las rutas, el
     * reconciliador y la SPA.
     *
     * @group puntos
     * @test
     */
    public function la_extencion_queda_con_el_slug_y_el_nombre_esperados()
    {
        ExtencionEmpresa::where('slug', self::SLUG)->delete();

        $this->correr_seeder();

        $extencion = ExtencionEmpresa::where('slug', self::SLUG)->first();

        $this->assertNotNull($extencion, 'El seeder no creó la extensión.');
        $this->assertEquals(self::NOMBRE_EXTENCION, $extencion->name);
    }

    /**
     * 🔴 La extensión tiene que salir también del seeder GENERAL, no solo del standalone.
     *
     * `UserSetupHelper` y `DemoSetupHelper` corren SOLO `ExtencionSeeder` antes de asignar
     * extensiones: una que no esté en su array no existe para una instancia nueva. Hasta el
     * 4/10/2026 `puntos_clientes` vivía solo en el standalone, que entró al camino de upgrade
     * con la 4.0.0 — así que toda demo y todo cliente instalado de cero después de esa versión
     * quedó sin la fila y sin forma de prender el programa de puntos (la demo listaba 97
     * extensiones y ninguna era esta).
     *
     * La fila se borra antes de sembrar para que el `count` mida lo que hizo el seeder general y
     * no una fila que ya estaba en la base. Después se corre el standalone encima, que es lo que
     * pasa cuando una instancia nueva recibe más tarde el seeder por el camino de upgrade: tiene
     * que seguir habiendo una sola.
     *
     * @group puntos
     * @test
     */
    public function el_seeder_general_de_extensiones_trae_puntos_clientes_con_el_nombre_del_standalone()
    {
        ExtencionEmpresa::where('slug', self::SLUG)->delete();

        // Por artisan, mismo motivo que en correr_seeder(): sin el Model::unguarded() del
        // comando, ExtencionEmpresa no acepta asignación masiva.
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionSeeder',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'ExtencionSeeder no sembró puntos_clientes: una demo o un cliente instalado de cero nace sin la extensión.'
        );
        $this->assertEquals(
            self::NOMBRE_EXTENCION,
            ExtencionEmpresa::where('slug', self::SLUG)->value('name'),
            'El nombre de puntos_clientes en ExtencionSeeder no coincide con el del standalone.'
        );

        $this->correr_seeder();

        $this->assertEquals(
            1,
            ExtencionEmpresa::where('slug', self::SLUG)->count(),
            'El standalone duplicó la fila que ya había sembrado ExtencionSeeder.'
        );
    }

    /**
     * La extensión se siembra pero NO se prende: ni el alta de un negocio ni el armado de una
     * demo la incluyen en las extensiones que le asignan al dueño, con ninguna combinación del
     * formulario. Prenderla es una decisión comercial, comercio por comercio.
     *
     * Se resuelve la lista por el mismo camino que `run()` —la base más las dos reglas— con todas
     * las casillas prendidas y cada tipo de negocio que los helpers distinguen. El contraste con
     * `motor_de_ofertas`, que sí se otorga de base, prueba que la lista se resolvió de verdad: sin
     * él, una lista vacía haría pasar el test sin medir nada.
     *
     * @group puntos
     * @test
     */
    public function ningun_setup_prende_puntos_clientes_por_defecto()
    {
        $casillas = [
            'produccion'                   => 1,
            'codigos_de_barra_por_defecto' => 1,
            'ventas_con_fecha_de_entrega'  => 1,
            'use_deposits'                 => 1,
            'use_price_lists'              => 1,
            'costos_en_dolares'            => 1,
            'ventas_en_dolares'            => 1,
            'consultora_de_precios'        => 1,
            'usar_codigos_de_barra'        => 1,
            'cajas'                        => 1,
            'imagenes'                     => 1,
        ];

        foreach ([UserSetupHelper::class, DemoSetupHelper::class] as $clase) {

            foreach ([null, 'ropa', 'ferreteria', 'forrajeria'] as $tipo) {

                $extencions = $this->extenciones_que_asigna($clase, array_merge($casillas, ['business_type' => $tipo]));

                $this->assertContains(
                    'motor_de_ofertas',
                    $extencions,
                    $clase.' no resolvió las extensiones de base: la aserción de abajo no mediría nada.'
                );
                $this->assertNotContains(
                    self::SLUG,
                    $extencions,
                    $clase.' prende puntos_clientes (tipo '.var_export($tipo, true).'): tiene que nacer apagada.'
                );
            }
        }
    }

    /**
     * 🔴 Con la extensión APAGADA, los seis endpoints del módulo devuelven 403.
     *
     * Es la única aserción que prueba que el gate está conectado. Y se chequea uno por uno a
     * propósito: una ruta que se agregue afuera del grupo `check_extencion_empresa` queda abierta
     * para los ~40 comercios y no rompe ningún test funcional.
     *
     * @group puntos
     * @test
     */
    public function sin_la_extencion_los_seis_endpoints_devuelven_403()
    {
        $this->quitar_extencion();

        $cliente = $this->cliente();

        $this->getJson('api/sistema-de-puntos')
            ->assertStatus(403, 'GET api/sistema-de-puntos quedó sin el gate por extensión.');

        $this->getJson('api/puntos/cliente/'.$cliente->id)
            ->assertStatus(403, 'GET api/puntos/cliente/{id} quedó sin el gate por extensión.');

        $this->getJson('api/puntos/disponible/'.$cliente->id)
            ->assertStatus(403, 'GET api/puntos/disponible/{id} quedó sin el gate por extensión.');

        $this->postJson('api/puntos/ajuste', ['client_id' => $cliente->id, 'puntos' => 10, 'detalle' => 'x'])
            ->assertStatus(403, 'POST api/puntos/ajuste quedó sin el gate por extensión.');

        $this->getJson('api/puntos/reporte')
            ->assertStatus(403, 'GET api/puntos/reporte quedó sin el gate por extensión.');

        $this->getJson('api/puntos/reporte/detalle?tipo=ganados')
            ->assertStatus(403, 'GET api/puntos/reporte/detalle quedó sin el gate por extensión.');
    }

    /**
     * Contraste del test anterior: con la extensión prendida, el mismo comercio entra.
     *
     * Sin este contraste, el test del 403 pasaría igual si las rutas no existieran (un 404 no es
     * un 403, pero una ruta rota tampoco sirve de nada).
     *
     * @group puntos
     * @test
     */
    public function con_la_extencion_prendida_los_endpoints_responden()
    {
        $this->dar_extencion();

        $cliente = $this->cliente();

        $this->getJson('api/sistema-de-puntos')->assertStatus(200)->assertJsonStructure(['models']);

        $this->getJson('api/puntos/disponible/'.$cliente->id)
            ->assertStatus(200)
            ->assertJsonStructure(['saldo', 'valor_punto', 'minimo_canje', 'tope_porcentaje', 'equivalencia_pesos', 'puede_canjear']);

        $this->getJson('api/puntos/cliente/'.$cliente->id)
            ->assertStatus(200)
            ->assertJsonStructure(['saldo', 'por_vencer_90_dias', 'valor_punto', 'movimientos', 'paginacion']);

        $this->getJson('api/puntos/reporte')->assertStatus(200)->assertJsonStructure(['emitidos', 'canjeados', 'saldo_vivo']);
    }

    /**
     * 🔴 `/puntos/disponible` SIN programa configurado devuelve 200 con todo en cero, no un 4xx.
     *
     * Es a propósito y está escrito en el controller: el comercio compró la extensión (si no, no
     * habría pasado el middleware) y todavía no configuró el programa. Un 4xx acá le abriría el
     * modal de error global de la SPA en el medio de una venta.
     *
     * @group puntos
     * @test
     */
    public function disponible_sin_programa_activo_devuelve_200_con_todo_en_cero()
    {
        $this->dar_extencion();

        $cliente = $this->cliente();

        $response = $this->getJson('api/puntos/disponible/'.$cliente->id);

        $response->assertStatus(200);

        $cuerpo = json_decode($response->getContent(), true);

        $this->assertEquals(0, $cuerpo['saldo']);
        $this->assertEquals(0, $cuerpo['valor_punto']);
        $this->assertEquals(0, $cuerpo['minimo_canje']);
        $this->assertEquals(0, $cuerpo['tope_porcentaje']);
        $this->assertEquals(0, $cuerpo['equivalencia_pesos']);
        $this->assertFalse($cuerpo['puede_canjear']);
    }

    /**
     * Un `client_id` que no es de este comercio da 404, no el saldo de un desconocido. El
     * middleware corta al comercio sin el módulo, pero no dice nada sobre de quién es el cliente.
     *
     * @group puntos
     * @test
     */
    public function un_cliente_de_otro_comercio_da_404()
    {
        $this->dar_extencion();

        $ajeno = \App\Models\Client::where('user_id', '!=', $this->comercio()->id)->first();

        if (is_null($ajeno)) {
            // No hay clientes de otros comercios en el fixture: se usa un id inexistente, que
            // prueba la misma rama del controller.
            $id_ajeno = \App\Models\Client::max('id') + 10000;
        } else {
            $id_ajeno = $ajeno->id;
        }

        $this->getJson('api/puntos/disponible/'.$id_ajeno)->assertStatus(404);
        $this->getJson('api/puntos/cliente/'.$id_ajeno)->assertStatus(404);
    }

    /**
     * Corre el seeder standalone, que es el que se declara en el despliegue.
     *
     * @return void
     */
    protected function correr_seeder()
    {
        // 🔴 Por artisan y NO con (new ExtencionPuntosClientesSeeder)->run(): ExtencionEmpresa no
        // declara $guarded ni $fillable, así que el firstOrCreate del seeder tira
        // MassAssignmentException afuera del Model::unguarded() que aplica el comando.
        $this->artisan('db:seed', [
            '--class' => 'Database\Seeders\ExtencionPuntosClientesSeeder',
            '--force' => true,
        ])->assertExitCode(0);
    }

    /**
     * Las extensiones que un setup le asigna al dueño, resueltas por el mismo camino que su
     * `run()`: `base_extencions()` más las reglas por tipo de negocio y por casillas. Por
     * reflexión porque los tres métodos son privados, igual que en `AlineacionLocalDemoTest`; las
     * reglas solo mutan los arrays por referencia y no escriben en la base.
     *
     * @param  string  $clase
     * @param  array   $data
     * @return array
     */
    protected function extenciones_que_asigna($clase, array $data)
    {
        $base = new ReflectionMethod($clase, 'base_extencions');
        $base->setAccessible(true);

        $extencions = $base->invoke(null);
        $seeders    = [];

        foreach (['apply_business_type_rules', 'apply_flag_rules'] as $regla) {
            $metodo = new ReflectionMethod($clase, $regla);
            $metodo->setAccessible(true);
            $metodo->invokeArgs(null, [$data, &$extencions, &$seeders]);
        }

        return $extencions;
    }

    /**
     * Foto del resto del catálogo: id, slug y nombre de cada fila que no es la de esta misión.
     *
     * @return array
     */
    protected function foto_del_resto_del_catalogo()
    {
        return ExtencionEmpresa::where('slug', '!=', self::SLUG)
                                ->orderBy('id')
                                ->get(['id', 'slug', 'name'])
                                ->map(function ($extencion) {
                                    return $extencion->id.'|'.$extencion->slug.'|'.$extencion->name;
                                })
                                ->toArray();
    }
}
