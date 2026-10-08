<?php

namespace Tests\Feature\DescuentosEnPrecios;

use App\Http\Controllers\Helpers\sale\DescuentosEnPreciosEsquemaHelper;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 15 — una SPA ANTERIOR a la opcion no puede editar una venta con los descuentos ADENTRO de
 * los precios (`SaleController::update()` -> `DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva()`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 *  🔴 EL CASO: LA CAJA CON LA PESTAÑA VIEJA
 * ─────────────────────────────────────────────────────────────────────────────
 *
 *  La venta se guardo en una caja con la version nueva (flag en 1, renglones ya descontados) y en
 *  OTRA caja del mismo comercio quedo abierta la version anterior. Esa SPA no conoce el flag: su pie
 *  vuelve a restar el descuento y su PUT manda un `total` descontado dos veces, que `update()` guarda
 *  tal cual (no lo valida contra los renglones) mientras el flag se preserva. La venta quedaria con
 *  los renglones descontados una vez y el total dos, para siempre.
 *
 *  Lo que se fija:
 *
 *   (a) flag guardado en 1 + PUT SIN la clave          -> 409 y la venta no cambia en nada;
 *   (b) el mismo PUT CON la clave (0 o 1)              -> 200, como siempre (la SPA nueva manda la
 *                                                         clave SIEMPRE, incluido el 0);
 *   (c) flag guardado en 0 o en NULL + PUT SIN clave   -> 200: nada cambia para las ventas de hoy;
 *   (d) la columna todavia no existe (ventana del deploy) -> el candado no se tiene en cuenta.
 *
 * ⚠️ Solo ventas. Los presupuestos no llevan este candado (ver el helper).
 *
 * @group descuentos_en_precios
 */
class Spa_vieja_no_edita_una_venta_con_descuentos_adentro_Test extends DescuentosEnPreciosTestCase
{
    /** El nombre al que se corre el flag para que la guarda no lo encuentre (caso d). */
    const FLAG_ESCONDIDO = 'aplicar_descuentos_directo_a_items_escondido_por_el_test_15';

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->restaurar_flag_si_quedo_escondido();

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
     * Crea por el endpoint una venta de mostrador de un articulo, con el 10 % de descuento en su
     * lista. Con el flag en 1 el renglon va a 90 (original 100); con el flag en 0 va a 100 y el
     * descuento queda al pie; con `null` la venta se manda SIN la clave, como la SPA vieja, y queda
     * con la columna en NULL.
     *
     * @param  int|null  $flag
     * @param  int       $cantidad  Sirve para darle a cada venta de un mismo test un total distinto
     *                              (la guarda anti-duplicados de `store()` es por comercio, cliente y total).
     * @return array  ['sale', 'articulo', 'descuento']
     */
    protected function venta($flag, $cantidad = 1)
    {
        $articulo  = $this->articulo_centinela();
        $descuento = $this->descuento();

        $precio = $flag ? 90 : 100;

        $payload = $this->payload_venta([
            $this->item_vender('article', $articulo->id, $precio, $flag ? 100 : false, $cantidad),
        ], 90.00 * $cantidad, (int) $flag, [$this->descuento_del_payload($descuento)], ['sub_total' => $precio * $cantidad]);

        if (is_null($flag)) {
            unset($payload['aplicar_descuentos_directo_a_items']);
        }

        return ['sale' => $this->crear_venta($payload), 'articulo' => $articulo, 'descuento' => $descuento];
    }

    /**
     * El PUT que armaria una SPA anterior sobre esa venta: la clave del flag NO viaja y, como su
     * pie vuelve a restar el descuento, el `total` viene descontado dos veces (81).
     *
     * @param  array  $v  De `venta()`.
     * @return array
     */
    protected function put_de_la_spa_vieja($v)
    {
        $payload = $this->payload_actualizar($v['sale'], [
            $this->item_vender('article', $v['articulo']->id, 90, false),
        ], 81.00, 0, [$this->descuento_del_payload($v['descuento'])], ['sub_total' => 90]);

        unset($payload['aplicar_descuentos_directo_a_items']);

        return $payload;
    }

    /**
     * Lo que importa de la venta, leido DIRECTO de la base: total, flag, y el renglon con sus bases.
     *
     * @param  \App\Models\Sale  $sale
     * @param  \App\Models\Article  $articulo
     * @return array
     */
    protected function foto($sale, $articulo)
    {
        $fila = DB::table('article_sale')->where('sale_id', $sale->id)->where('article_id', $articulo->id)->first();

        return [
            'total' => (float) DB::table('sales')->where('id', $sale->id)->value('total'),
            'flag'  => DB::table('sales')->where('id', $sale->id)->value(DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG),
            'price' => (float) $fila->price,
            'base'  => $fila->price_sin_descuentos_de_venta,
        ];
    }

    /**
     * Test (a) — flag en 1 + PUT sin la clave: 409, con un mensaje que el vendedor entiende, y la
     * venta no cambia en NADA (ni el total doble-descontado, ni los renglones, ni el flag).
     *
     * @test
     */
    public function una_venta_con_descuentos_adentro_rechaza_el_put_de_una_spa_vieja()
    {
        $v = $this->venta(1);

        $antes = $this->foto($v['sale'], $v['articulo']);

        $this->assertEqualsWithDelta(90.00, $antes['total'], self::DELTA, 'Control: la venta nace con el total 90.');
        $this->assertEquals(1, (int) $antes['flag'], 'Control: la venta nace con la opcion prendida.');

        $response = $this->putJson('api/sale/'.$v['sale']->id, $this->put_de_la_spa_vieja($v));

        $response->assertStatus(409);

        $this->assertTrue((bool) $response->json('requiere_spa_nueva'));

        $mensaje = (string) $response->json('message');

        $this->assertStringContainsString('descuentos aplicados directamente en los precios', $mensaje);
        $this->assertStringContainsString('version anterior', $mensaje);
        $this->assertStringContainsString('Recarga la pagina', $mensaje);

        $despues = $this->foto($v['sale'], $v['articulo']);

        $this->assertSame($antes, $despues, 'El rechazo no puede tocar el total, el flag ni los renglones.');

        $this->assertEqualsWithDelta(90.00, $despues['total'], self::DELTA, 'El total doble-descontado (81) no se guardo.');
    }

    /**
     * Test (b) — el MISMO PUT con la clave (la SPA nueva la manda siempre) pasa, tanto con 1 como con
     * el 0 que apaga la opcion.
     *
     * @test
     */
    public function el_mismo_put_con_la_clave_pasa_con_uno_y_con_cero()
    {
        $con_uno  = $this->venta(1, 1);
        $con_cero = $this->venta(1, 2);

        /* Con 1: la SPA nueva guarda sin tocar los precios. */
        $this->putJson('api/sale/'.$con_uno['sale']->id, $this->payload_actualizar($con_uno['sale'], [
            $this->item_vender('article', $con_uno['articulo']->id, 90, 100),
        ], 90.00, 1, [$this->descuento_del_payload($con_uno['descuento'])]))->assertStatus(200);

        $this->assertEquals(1, (int) $this->foto($con_uno['sale'], $con_uno['articulo'])['flag']);

        /* Con 0: la SPA nueva apaga la opcion; los precios vuelven al original y el 10 % pasa al pie. */
        $this->putJson('api/sale/'.$con_cero['sale']->id, $this->payload_actualizar($con_cero['sale'], [
            $this->item_vender('article', $con_cero['articulo']->id, 100, null, 2),
        ], 180.00, 0, [$this->descuento_del_payload($con_cero['descuento'])], ['sub_total' => 200]))->assertStatus(200);

        $foto = $this->foto($con_cero['sale'], $con_cero['articulo']);

        $this->assertEquals(0, (int) $foto['flag'], 'El 0 explicito apaga la opcion.');
        $this->assertEqualsWithDelta(100, $foto['price'], self::DELTA);
        $this->assertNull($foto['base']);
    }

    /**
     * Test (c) — una venta con el flag en 0 o en NULL (todas las de hoy) no cambia: el PUT sin la
     * clave sigue pasando con 200.
     *
     * @test
     */
    public function las_ventas_sin_la_opcion_prendida_siguen_editandose_sin_la_clave()
    {
        $en_cero = $this->venta(0, 1);
        $en_null = $this->venta(null, 2);

        $this->assertNull($this->foto($en_null['sale'], $en_null['articulo'])['flag'], 'Control: la venta sin la clave queda con la columna en NULL.');

        foreach ([[$en_cero, 100, 90.00], [$en_null, 100, 180.00]] as $caso) {

            list($v, $precio, $total) = $caso;

            $payload = $this->payload_actualizar($v['sale'], [
                $this->item_vender('article', $v['articulo']->id, $precio, false, $total / 90),
            ], $total, 0, [$this->descuento_del_payload($v['descuento'])], ['sub_total' => $precio * ($total / 90)]);

            unset($payload['aplicar_descuentos_directo_a_items']);

            $this->putJson('api/sale/'.$v['sale']->id, $payload)->assertStatus(200);
        }
    }

    /**
     * Test (d) — con la columna todavia sin crear (ventana del deploy) el candado no se tiene en
     * cuenta: ninguna venta puede tener el flag en 1, aunque el modelo en memoria diga otra cosa, y el
     * PUT sin la clave pasa. Se esconde la columna como en el archivo 7 (RENAME por una conexion
     * aparte, con la transaccion del trait cerrada mientras tanto).
     *
     * @test
     */
    public function sin_la_columna_el_candado_no_se_tiene_en_cuenta()
    {
        $articulo = $this->articulo_centinela();

        DB::rollBack();

        $this->renombrar_flag(DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG, self::FLAG_ESCONDIDO);

        DB::beginTransaction();

        try {

            /* Despues del beginTransaction(): lo que se crea antes del rollback se perderia con el. */
            $descuento = $this->descuento();

            $this->assertFalse(DescuentosEnPreciosEsquemaHelper::hay_columna_flag('sales'), 'El escenario no se armo: la guarda sigue viendo la columna.');

            $sale = $this->crear_venta($this->payload_venta([
                $this->item_vender('article', $articulo->id, 100, false),
            ], 90.00, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 100]));

            /* El helper mira la guarda ANTES que el modelo: ni con un atributo en 1 en memoria frena. */
            $sale->aplicar_descuentos_directo_a_items = 1;

            $this->assertFalse(
                DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva($sale, Request::create('api/sale/'.$sale->id, 'PUT', [])),
                'Sin la columna no hay nada que proteger.'
            );

            $payload = $this->payload_actualizar($sale, [
                $this->item_vender('article', $articulo->id, 100, false),
            ], 90.00, 0, [$this->descuento_del_payload($descuento)], ['sub_total' => 100]);

            unset($payload['aplicar_descuentos_directo_a_items']);

            $this->putJson('api/sale/'.$sale->id, $payload)->assertStatus(200);

        } finally {

            DB::rollBack();

            $this->renombrar_flag(self::FLAG_ESCONDIDO, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);

            /* Para que el rollback del tearDown del trait tenga una transaccion que cerrar. */
            DB::beginTransaction();
        }
    }

    /**
     * Test (e) — el helper solo mira la AUSENCIA de la clave: un null explicito es una SPA nueva y
     * pasa, igual que un 0.
     *
     * @test
     */
    public function una_clave_en_null_cuenta_como_presente()
    {
        $v = $this->venta(1);

        $con_clave_null = Request::create('api/sale/'.$v['sale']->id, 'PUT', ['aplicar_descuentos_directo_a_items' => null]);
        $sin_clave      = Request::create('api/sale/'.$v['sale']->id, 'PUT', []);

        $this->assertFalse(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva(Sale::find($v['sale']->id), $con_clave_null));
        $this->assertTrue(DescuentosEnPreciosEsquemaHelper::requiere_spa_nueva(Sale::find($v['sale']->id), $sin_clave));
    }

    /**
     * Conexion PDO aparte contra la MISMA base (el DDL de MySQL hace COMMIT implicito de la sesion
     * que lo corre y cerraria la transaccion del test).
     *
     * @param  string  $de
     * @param  string  $a
     * @return void
     */
    protected function renombrar_flag($de, $a)
    {
        $config = config('database.connections.'.config('database.default'));

        $dsn = 'mysql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'];

        $pdo = new \PDO($dsn, $config['username'], $config['password'], [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);

        $pdo->exec('SET SESSION lock_wait_timeout = 10');

        foreach (DescuentosEnPreciosEsquemaHelper::TABLAS_FLAG as $tabla) {

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
     * Red de seguridad: si una corrida anterior se corto con el flag escondido, se devuelve.
     *
     * @return void
     */
    protected function restaurar_flag_si_quedo_escondido()
    {
        $this->renombrar_flag(self::FLAG_ESCONDIDO, DescuentosEnPreciosEsquemaHelper::COLUMNA_FLAG);
    }
}
