<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\BackgroundProcess;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 15 — tercera ronda de revisión (misión eliminar-sucursal-con-stock, 5/10/2026): lo que
 * pidieron el revisor de concurrencia y el revisor del delta de la segunda ronda.
 *
 * Lo que fija:
 *
 *  1. El ORDEN de los candados de fila de la pasada: variantes primero, artículo después (el mismo
 *     orden en que escribe la venta de una variante; al revés, una venta y la pasada se esperan
 *     mutuamente: deadlock).
 *  2. La fase final bloquea TODAS las filas de la sucursal, también las que están en 0: con READ
 *     COMMITTED, InnoDB suelta el candado de las filas que no cumplen el WHERE del conteo
 *     (`amount != 0`), y una venta podía escribir en una fila en 0 entre el conteo y el DELETE.
 *  3. Si la sucursal que hereda (o la que recibe a los empleados) desaparece antes de la fase final,
 *     no se escribe nada y se avisa.
 *  4. Si la cola no acepta el trabajo, el registro `pendiente` no queda colgado como "ya en proceso".
 *  5. "Poner stock en 0" con un depósito muerto no mueve nada, y la guarda del motor sin dueño deja
 *     pasar el movimiento como antes.
 *  6. El texto del 404 "la sucursal no existe": la SPA lo distingue del 404 de una API vieja por la
 *     palabra "sucursal".
 *
 * La concurrencia real (dos conexiones peleando por una fila) no se puede armar de forma determinista
 * dentro de la transacción de un test: los tests 1 y 2 fijan el ORDEN y el ALCANCE de los candados
 * mirando las consultas que corren (`DB::getQueryLog()`), que es lo que se rompería si alguien los
 * cambiara.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_concurrencia_y_bordes_Test extends SucursalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SucursalVigenteHelper::olvidar();

        EliminarSucursalHelper::$filas_en_linea = null;
    }

    protected function tearDown(): void
    {
        EliminarSucursalHelper::$filas_en_linea = null;

        parent::tearDown();
    }

    /**
     * Las consultas que corren mientras se ejecuta `$accion`, en minúsculas y con los `?` sin reemplazar.
     *
     * @param  callable  $accion
     * @return array
     */
    protected function consultas_de($accion)
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            $accion();
        } finally {
            DB::disableQueryLog();
        }

        $consultas = [];

        foreach (DB::getQueryLog() as $registro) {
            $consultas[] = strtolower($registro['query']);
        }

        return $consultas;
    }

    /**
     * Posición de la primera consulta con candado (`for update`) sobre una tabla.
     *
     * @param  array   $consultas
     * @param  string  $desde      Fragmento "from `tabla`" (con el acento grave de cierre: así
     *                             `address_article` no confunde con `address_article_variant`).
     * @return int|null
     */
    protected function primera_con_candado_sobre($consultas, $desde)
    {
        foreach ($consultas as $posicion => $consulta) {

            if (strpos($consulta, 'for update') !== false && strpos($consulta, $desde) !== false) {
                return $posicion;
            }
        }

        return null;
    }

    /**
     * Test 1 — la pasada bloquea primero las filas de las VARIANTES y después las del artículo.
     *
     * Rojo con el orden anterior (artículo y después variantes): una venta de esa variante y la pasada
     * quedaban esperándose entre sí (deadlock 1213, y la víctima podía ser el cajero).
     *
     * @test
     */
    public function la_pasada_bloquea_primero_las_variantes_y_despues_el_articulo()
    {
        $borrar  = $this->nueva_sucursal('zz Orden de candados');
        $destino = $this->nueva_sucursal('zz Orden de candados destino');

        $d  = $this->nuevo_articulo('zz Orden de candados D');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $this->cargar_variante($d, $v1, $borrar, 3);

        $comercio = $this->comercio();

        $consultas = $this->consultas_de(function () use ($borrar, $d, $destino, $comercio) {
            EliminarSucursalHelper::procesar_articulo($borrar, $d->id, $comercio, $comercio->id, EliminarSucursalHelper::STOCK_TRANSFERIR, $destino);
        });

        $de_variantes = $this->primera_con_candado_sobre($consultas, 'from `address_article_variant`');
        $del_articulo = $this->primera_con_candado_sobre($consultas, 'from `address_article`');

        $this->assertNotNull($de_variantes, 'La pasada tiene que bloquear las filas de las variantes.');
        $this->assertNotNull($del_articulo, 'La pasada tiene que bloquear las filas del artículo.');
        $this->assertLessThan($del_articulo, $de_variantes, 'Primero las variantes, después el artículo (el orden de la venta de una variante).');

        // Y la pasada hizo su trabajo: el test no puede pasar sin haber movido nada.
        $this->assertEquals(3.0, $this->stock_variante_en($v1, $destino->id));
        $this->assertEquals(0.0, $this->stock_variante_en($v1, $borrar->id));
    }

    /**
     * Test 2 — la fase final bloquea TODAS las filas de la sucursal (variantes primero), también las
     * que están en 0, antes de contar y de borrar.
     *
     * Rojo con la versión anterior: solo bloqueaba (dentro del conteo) las filas con `amount != 0`, y
     * con READ COMMITTED las filas en 0 quedaban libres para una venta de un navegador con la sucursal
     * todavía elegida.
     *
     * @test
     */
    public function la_fase_final_bloquea_todas_las_filas_de_la_sucursal_tambien_las_que_estan_en_cero()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Ultima mirada');

        $d  = $this->nuevo_articulo('zz Ultima mirada D');
        $v1 = $this->nueva_variante($d, 'Azul');

        // Filas EN CERO en las dos tablas: justo las que el conteo (amount != 0) no alcanza.
        DB::table('address_article')->insert(['article_id' => $d->id, 'address_id' => $borrar->id, 'amount' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('address_article_variant')->insert(['article_variant_id' => $v1->id, 'address_id' => $borrar->id, 'amount' => 0]);

        $decision = EliminarSucursalHelper::normalizar_decision([
            'stock_accion'    => 'descartar',
            'usuarios_accion' => 'dejar_sin_sucursal',
            'reemplazo_id'    => $principal->id,
        ]);

        $comercio_id = $this->comercio()->id;

        $resultado = null;

        $consultas = $this->consultas_de(function () use ($borrar, $comercio_id, $decision, &$resultado) {
            $resultado = EliminarSucursalHelper::fase_final($borrar, $comercio_id, $decision, false);
        });

        $this->assertIsArray($resultado);
        $this->assertArrayNotHasKey('error', $resultado);
        $this->assertNull(Address::find($borrar->id), 'La sucursal se eliminó.');

        $candado_variantes = array_search('select `id` from `address_article_variant` where `address_id` = ? for update', $consultas, true);
        $candado_articulo  = array_search('select `id` from `address_article` where `address_id` = ? for update', $consultas, true);

        $this->assertNotFalse($candado_variantes, 'Tiene que bloquear TODAS las filas de variantes de la sucursal, sin filtrar por amount.');
        $this->assertNotFalse($candado_articulo, 'Tiene que bloquear TODAS las filas del artículo en la sucursal, sin filtrar por amount.');
        $this->assertLessThan($candado_articulo, $candado_variantes, 'Primero las variantes, después el artículo.');

        // Y los dos candados van ANTES de cualquier borrado.
        foreach ($consultas as $posicion => $consulta) {

            if (strpos($consulta, 'delete from') === 0) {
                $this->assertLessThan($posicion, $candado_articulo, 'Los candados van antes del primer DELETE.');
                break;
            }
        }
    }

    /**
     * Test 3 — si la sucursal que hereda (y la que recibe a los empleados) desaparece antes de la fase
     * final, no se escribe nada: ni las marcas, ni las cajas, ni los empleados, ni se borra la sucursal.
     *
     * Rojo con la versión anterior: la fase final seguía, salteaba en silencio el traspaso de marcas,
     * dejaba a los empleados y las cajas con el id muerto y borraba la sucursal.
     *
     * @test
     */
    public function si_la_sucursal_heredera_desaparece_antes_de_la_fase_final_no_se_toca_nada()
    {
        $owner_id = $this->comercio()->id;

        $borrar   = $this->nueva_sucursal('zz Heredera', ['default_address' => 1]);
        $heredera = $this->nueva_sucursal('zz Heredera destino');
        $empleado = $this->nuevo_empleado('zz Heredera empleado', $borrar->id);
        $caja_id  = DB::table('cajas')->insertGetId(['num' => 990031, 'name' => 'zz Caja heredera', 'user_id' => $owner_id, 'address_id' => $borrar->id]);

        $decision = EliminarSucursalHelper::normalizar_decision([
            'stock_accion'        => 'descartar',
            'usuarios_accion'     => 'reasignar',
            'usuarios_destino_id' => $heredera->id,
            'reemplazo_id'        => $heredera->id,
        ]);

        // Alguien la elimina mientras corría la pasada de stock.
        DB::table('addresses')->where('id', $heredera->id)->delete();
        SucursalVigenteHelper::olvidar();

        $resultado = EliminarSucursalHelper::fase_final($borrar, $owner_id, $decision, false);

        $this->assertIsArray($resultado);
        $this->assertArrayHasKey('error', $resultado, 'Sin la sucursal que hereda no se puede terminar.');

        $this->assertNotNull(Address::find($borrar->id), 'La sucursal NO se borra.');
        $this->assertSame(1, (int) DB::table('addresses')->where('id', $borrar->id)->value('default_address'), 'Las marcas siguen donde estaban.');
        $this->assertSame((int) $borrar->id, (int) DB::table('users')->where('id', $empleado->id)->value('address_id'), 'El empleado no se movió a un id muerto.');
        $this->assertSame((int) $borrar->id, (int) DB::table('cajas')->where('id', $caja_id)->value('address_id'), 'La caja no se movió a un id muerto.');
    }

    /**
     * Test 4 — si la cola no acepta el trabajo, el registro que nació `pendiente` se cierra en fallo
     * y no queda como "ya en proceso" bloqueando todo nuevo "Eliminar".
     *
     * Rojo con la versión anterior: `dispatch()` tiraba la excepción sin atrapar (500 del Handler) y el
     * registro quedaba `pendiente`, o sea "ya se está eliminando" hasta que `cerrar_colgados()` lo
     * limpiara, 3 horas después.
     *
     * @test
     */
    public function si_no_se_puede_encolar_el_job_el_proceso_no_queda_pendiente()
    {
        $borrar  = $this->nueva_sucursal('zz Cola caida');
        $destino = $this->nueva_sucursal('zz Cola caida destino');

        $a = $this->nuevo_articulo('zz Cola caida A');
        $b = $this->nuevo_articulo('zz Cola caida B');
        $this->cargar_deposito($a, $borrar, 3);
        $this->cargar_deposito($b, $borrar, 2);

        // Con dos filas ya es "muchas": va a segundo plano.
        EliminarSucursalHelper::$filas_en_linea = 1;

        // Una conexión de cola que no existe: `dispatch()` tira al resolverla.
        config(['queue.default' => 'conexion_de_cola_que_no_existe']);

        $respuesta = $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'transferir', 'stock_destino_id' => $destino->id]);

        $respuesta->assertStatus(500);
        $this->assertSame(EliminarSucursalHelper::MENSAJE_SI_SE_CORTA, $respuesta->json('message'));

        $proceso = BackgroundProcess::where('tipo', 'eliminacion_sucursal')
                                    ->where('referencia_id', $borrar->id)
                                    ->orderBy('id', 'desc')
                                    ->first();

        $this->assertNotNull($proceso, 'El registro visible nació antes de encolar.');
        $this->assertSame('fallo', $proceso->status, 'Y se cerró en fallo: no queda pendiente.');

        $this->assertNotNull(Address::find($borrar->id), 'La sucursal sigue existiendo.');
        $this->assertFalse(EliminarSucursalHelper::proceso_activo($borrar), 'Ya no figura "en proceso": se puede volver a intentar.');
        $this->assertEquals(3.0, $this->stock_en($a, $borrar->id), 'No se movió nada.');
    }

    /**
     * Test 5 — "Poner stock en 0" con un depósito que ya no existe no mueve nada.
     *
     * El monto del reseteo sale del pivot de ESE depósito: redirigirlo a otra sucursal le aplicaría un
     * número que no es suyo. Rojo con la versión anterior (el concepto no figuraba entre los que nombran
     * depósitos y se reemplazaba por la sucursal por defecto).
     *
     * @test
     */
    public function poner_el_stock_en_cero_con_una_sucursal_muerta_no_mueve_nada()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Reseteo muerto');

        $articulo = $this->nuevo_articulo('zz Reseteo muerto A');
        $this->cargar_deposito($articulo, $principal, 4);

        $this->assertNotNull($this->concepto_id('Reseteo de Stock'), 'El fixture tiene el concepto del reseteo.');

        $muerta = $borrar->id;
        DB::table('addresses')->where('id', $muerta)->delete();
        SucursalVigenteHelper::olvidar();

        $movimientos = $this->movimientos_de($articulo)->count();

        // Con la grafía que usa ResetStockHelper ('stock' en minúscula): la guarda compara sin distinguir mayúsculas.
        $resultado = (new StockMovementController())->crear([
            'model_id'                     => $articulo->id,
            'amount'                       => 2,
            'to_address_id'                => $muerta,
            'concepto_stock_movement_name' => 'Reseteo de stock',
        ]);

        $this->assertNull($resultado, 'Con el depósito muerto, el reseteo no mueve nada.');
        $this->assertSame($movimientos, $this->movimientos_de($articulo)->count());
        $this->assertEquals(4.0, $this->stock_en($articulo, $principal->id), 'La sucursal por defecto no recibe un monto que no es suyo.');
        $this->assertSame(0, $this->filas_de_pivot($muerta));
    }

    /**
     * Test 6 — sin dueño, la guarda del motor deja pasar los datos como antes de la guarda (con un dueño
     * nulo TODO id parecía "muerto" y el movimiento se redirigía o se descartaba sin motivo).
     *
     * @test
     */
    public function la_guarda_sin_dueno_deja_pasar_los_datos_como_antes()
    {
        $datos = ['model_id' => 1, 'amount' => 1, 'from_address_id' => 987654321];

        $this->assertSame($datos, SucursalVigenteHelper::aplicar_guarda_del_motor($datos, null, null));
    }

    /**
     * Test 7 — el 404 de una sucursal inexistente (resumen y baja) dice "sucursal": la SPA lo
     * distingue del 404 de una API vieja (que no tiene la ruta) buscando esa palabra
     * (`empresa-spa/src/store/address.js`). Fija el texto para que una reformulación no rompa el corte.
     *
     * @test
     */
    public function el_404_de_una_sucursal_inexistente_nombra_la_sucursal()
    {
        $this->assertSame(1, preg_match('/sucursal/i', AddressController::MENSAJE_SUCURSAL_INEXISTENTE), 'La SPA decide por esta palabra.');

        $resumen = $this->getJson('api/address/987654321/eliminar-resumen');
        $resumen->assertStatus(404);
        $this->assertSame(AddressController::MENSAJE_SUCURSAL_INEXISTENTE, $resumen->json('message'));

        $baja = $this->eliminar_sucursal(987654321);
        $baja->assertStatus(404);
        $this->assertSame(AddressController::MENSAJE_SUCURSAL_INEXISTENTE, $baja->json('message'));
    }
}
