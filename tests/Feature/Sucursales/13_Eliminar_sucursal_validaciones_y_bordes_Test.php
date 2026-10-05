<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Http\Controllers\Helpers\address\SucursalVigenteHelper;
use App\Http\Controllers\Stock\StockMovementController;
use App\Models\Address;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 13 — validaciones de la decisión y casos borde que pidió la segunda ronda de revisión
 * (misión eliminar-sucursal-con-stock, 5/10/2026, punto E).
 *
 * Lo que fija:
 *
 *  (a) cada destino inválido (`stock_destino_id`, `usuarios_destino_id`, `reemplazo_id` de OTRO
 *      comercio, igual a la sucursal que se elimina, un domicilio de comprador o inexistente),
 *      `transferir` sin destino y acciones que no existen → 422, y NADA cambia (se comparan las
 *      tablas antes y después, no la respuesta);
 *  (b) papelera con variantes, variante con stock NEGATIVO, idempotencia con variantes;
 *  (c) el motor directo (`crear()`) con un id muerto y `article_variant_id`: un concepto que no
 *      nombra depósitos se redirige al reemplazo; un "Mov entre depositos" no mueve nada.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_validaciones_y_bordes_Test extends SucursalesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SucursalVigenteHelper::olvidar();
    }

    /**
     * Todo lo que una eliminación puede cambiar, leído de las tablas.
     *
     * @param  \App\Models\Address  $borrar
     * @param  array                $articulos
     * @return array
     */
    protected function foto($borrar, $articulos)
    {
        $ids = [];
        foreach ($articulos as $articulo) {
            $ids[] = $articulo->id;
        }

        return [
            'sucursal'    => (array) DB::table('addresses')->where('id', $borrar->id)->first(),
            'pivot'       => DB::table('address_article')->whereIn('article_id', $ids)->orderBy('id')->get(['id', 'article_id', 'address_id', 'amount'])->map(function ($f) { return (array) $f; })->all(),
            'variantes'   => DB::table('address_article_variant')->where('address_id', $borrar->id)->orderBy('id')->get(['id', 'amount'])->map(function ($f) { return (array) $f; })->all(),
            'stock'       => DB::table('articles')->whereIn('id', $ids)->orderBy('id')->pluck('stock', 'id')->all(),
            'usuarios'    => DB::table('users')->where('address_id', $borrar->id)->orderBy('id')->pluck('id')->all(),
            'movimientos' => StockMovement::where('user_id', $this->comercio()->id)->count(),
            'marcas'      => DB::table('addresses')->where('user_id', $this->comercio()->id)->orderBy('id')->get(['id', 'default_address'])->map(function ($f) { return (array) $f; })->all(),
        ];
    }

    /**
     * Test 1 — (a) los destinos inválidos y las acciones inválidas: 422 y nada cambia.
     *
     * @test
     */
    public function cada_destino_o_accion_invalida_es_422_y_no_cambia_nada()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Validaciones a borrar', ['default_address' => 1]);
        $valida    = $this->nueva_sucursal('zz Validaciones destino valido');

        $articulo = $this->nuevo_articulo('zz Validaciones A');
        $this->cargar_deposito($articulo, $borrar, 3);
        $this->cargar_deposito($articulo, $principal, 1);

        $this->nuevo_empleado('zz Validaciones empleado', $borrar->id);

        $otro          = $this->otro_comercio();
        $ajena         = Address::create(['street' => 'zz Validaciones ajena', 'user_id' => $otro->id, 'default_address' => 0]);
        $buyer_id      = DB::table('buyers')->insertGetId(['name' => 'zz Validaciones comprador', 'user_id' => $this->comercio()->id, 'isVerified' => 0]);
        $de_comprador  = Address::create(['street' => 'zz Validaciones domicilio', 'buyer_id' => $buyer_id]);
        $inexistente   = (int) DB::table('addresses')->max('id') + 1000;

        $invalidos = [
            'ajena'           => $ajena->id,
            'la misma'        => $borrar->id,
            'de comprador'    => $de_comprador->id,
            'inexistente'     => $inexistente,
        ];

        // Una decisión completa y válida, que cada caso rompe en UN solo punto.
        $valida_completa = [
            'stock_accion'        => 'transferir',
            'stock_destino_id'    => $valida->id,
            'usuarios_accion'     => 'reasignar',
            'usuarios_destino_id' => $valida->id,
            'reemplazo_id'        => $valida->id,
        ];

        $casos = [];

        foreach (['stock_destino_id', 'usuarios_destino_id', 'reemplazo_id'] as $clave) {
            foreach ($invalidos as $nombre => $id) {
                $casos[$clave.' '.$nombre] = array_merge($valida_completa, [$clave => $id]);
            }
        }

        $casos['transferir sin destino'] = array_merge($valida_completa, ['stock_destino_id' => null]);
        $casos['stock_accion inexistente'] = array_merge($valida_completa, ['stock_accion' => 'regalar']);
        $casos['usuarios_accion inexistente'] = array_merge($valida_completa, ['usuarios_accion' => 'echar']);
        $casos['reasignar sin destino'] = array_merge($valida_completa, ['usuarios_destino_id' => null]);

        $antes = $this->foto($borrar, [$articulo]);

        foreach ($casos as $nombre => $parametros) {

            $respuesta = $this->eliminar_sucursal($borrar->id, $parametros);

            $this->assertSame(422, $respuesta->getStatusCode(), 'Caso "'.$nombre.'": tiene que ser 422.');
            $this->assertTrue($respuesta->json('requiere_decision'), 'Caso "'.$nombre.'": eligiendo bien se puede.');
            $this->assertNotEmpty($respuesta->json('message'), 'Caso "'.$nombre.'": sin mensaje.');

            $this->assertSame($antes, $this->foto($borrar, [$articulo]), 'Caso "'.$nombre.'": un 422 no puede cambiar NADA.');
        }

        // Y la decisión válida sí elimina (el escenario no estaba roto por otra cosa).
        $this->eliminar_sucursal($borrar->id, $valida_completa)->assertStatus(200);
        $this->assertNull(Address::find($borrar->id));
    }

    /**
     * Test 2 — (b) artículo en la papelera CON variantes: transferir y descartar por SQL, sin libro.
     *
     * @test
     */
    public function papelera_con_variantes_transferir_y_descartar()
    {
        $principal = $this->sucursal_principal();
        $destino   = $this->nueva_sucursal('zz Papelera variantes destino');

        // Transferir.
        $borrar_1 = $this->nueva_sucursal('zz Papelera variantes transferir');

        $d  = $this->nuevo_articulo('zz Papelera variantes D');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $v2 = $this->nueva_variante($d, 'Azul');
        $this->cargar_variante($d, $v1, $borrar_1, 2);
        $this->cargar_variante($d, $v1, $principal, 1);
        $this->cargar_variante($d, $v2, $borrar_1, 3);
        $d->delete();

        $movimientos = $this->movimientos_de($d)->count();

        $this->eliminar_sucursal($borrar_1->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200)->assertJsonPath('resumen.articulos_en_papelera', 1);

        $this->assertSame(0, $this->filas_de_pivot($borrar_1->id));
        $this->assertEquals(2.0, $this->stock_variante_en($v1, $destino->id));
        $this->assertEquals(3.0, $this->stock_variante_en($v2, $destino->id));
        $this->assertEquals(6.0, $this->stock_global($d), 'Transferir no cambia el global (suma de variantes).');
        $this->assertSame($movimientos, $this->movimientos_de($d)->count(), 'D7: sin renglón en el libro.');

        // Descartar.
        $borrar_2 = $this->nueva_sucursal('zz Papelera variantes descartar');

        $e  = $this->nuevo_articulo('zz Papelera variantes E');
        $w1 = $this->nueva_variante($e, 'Rojo');
        $this->cargar_variante($e, $w1, $borrar_2, 4);
        $this->cargar_variante($e, $w1, $principal, 1);
        $e->delete();

        $this->eliminar_sucursal($borrar_2->id, ['stock_accion' => 'descartar'])->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($borrar_2->id));
        $this->assertEquals(1.0, $this->stock_de_variante($w1));
        $this->assertEquals(1.0, $this->stock_global($e), 'Descartar: el global es la suma de lo que queda.');
    }

    /**
     * Test 3 — (b) variante con stock NEGATIVO: se transfiere (el destino baja) y se descarta (el
     * global sube).
     *
     * @test
     */
    public function variante_con_stock_negativo()
    {
        $principal = $this->sucursal_principal();
        $destino   = $this->nueva_sucursal('zz Variante negativa destino');

        $borrar_1 = $this->nueva_sucursal('zz Variante negativa transferir');

        $d  = $this->nuevo_articulo('zz Variante negativa D');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $this->cargar_variante($d, $v1, $borrar_1, -2);
        $this->cargar_variante($d, $v1, $principal, 5);

        $this->assertEquals(3.0, $this->stock_global($d));

        $this->eliminar_sucursal($borrar_1->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($borrar_1->id));
        $this->assertEquals(-2.0, $this->stock_variante_en($v1, $destino->id), 'El negativo se traslada: el destino baja.');
        $this->assertEquals(3.0, $this->stock_global($d), 'El global no cambia.');

        $borrar_2 = $this->nueva_sucursal('zz Variante negativa descartar');

        $e  = $this->nuevo_articulo('zz Variante negativa E');
        $w1 = $this->nueva_variante($e, 'Rojo');
        $this->cargar_variante($e, $w1, $borrar_2, -2);
        $this->cargar_variante($e, $w1, $principal, 5);

        $this->eliminar_sucursal($borrar_2->id, ['stock_accion' => 'descartar'])->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($borrar_2->id));
        $this->assertEquals(5.0, $this->stock_global($e), 'Descartar un negativo: el global SUBE.');

        $movimiento = $this->movimientos_de($e, 'Eliminacion de sucursal')->first();
        $this->assertEqualsWithDelta(2.0, (float) $movimiento->amount, self::DELTA);
        $this->assertSame($w1->id, (int) $movimiento->article_variant_id);
    }

    /**
     * Test 4 — (b) idempotencia con variantes: cortada a la mitad, volver a eliminar no mueve dos
     * veces ninguna variante.
     *
     * @test
     */
    public function idempotencia_con_variantes()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Idempotencia variantes');
        $destino   = $this->nueva_sucursal('zz Idempotencia variantes destino');

        $d  = $this->nuevo_articulo('zz Idempotencia variantes D');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $v2 = $this->nueva_variante($d, 'Azul');
        $this->cargar_variante($d, $v1, $borrar, 2);
        $this->cargar_variante($d, $v2, $borrar, 3);
        $this->cargar_variante($d, $v1, $principal, 1);

        $e = $this->nuevo_articulo('zz Idempotencia variantes E');
        $this->cargar_deposito($e, $borrar, 4);

        $decision = ['stock_accion' => 'transferir', 'stock_destino_id' => $destino->id];

        // El corte: UN artículo (el de menor id: el de las variantes) y se "cae".
        EliminarSucursalHelper::ejecutar($borrar->id, $this->comercio()->id, $this->comercio()->id, $decision, null, 1);

        $this->assertNotNull(Address::find($borrar->id));

        $this->eliminar_sucursal($borrar->id, $decision)->assertStatus(200);

        $this->assertSame(0, $this->filas_de_pivot($borrar->id));

        $movimientos = $this->movimientos_de($d, 'Mov entre depositos');

        $this->assertCount(2, $movimientos, 'Una vez por variante, aunque se haya cortado.');
        $this->assertEquals(2.0, $this->stock_variante_en($v1, $destino->id));
        $this->assertEquals(3.0, $this->stock_variante_en($v2, $destino->id));
        $this->assertEquals(6.0, $this->stock_global($d));
        $this->assertCount(1, $this->movimientos_de($e, 'Mov entre depositos'));
        $this->assertEquals(4.0, $this->stock_global($e));
    }

    /**
     * Test 5 — (c) el motor directo con un id muerto y `article_variant_id`: un concepto que no nombra
     * depósitos va al reemplazo (CheckVariants con la guarda); "Mov entre depositos" no mueve nada.
     * El test 12-1 no lo ejercita: ahí SaleController reemplaza la sucursal antes de llegar al motor.
     *
     * @test
     */
    public function el_motor_con_id_muerto_y_variante()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Motor variante');

        $d  = $this->nuevo_articulo('zz Motor variante D');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $this->cargar_variante($d, $v1, $principal, 4);

        $muerta = $borrar->id;
        DB::table('addresses')->where('id', $muerta)->delete();
        SucursalVigenteHelper::olvidar();

        $movimiento = (new StockMovementController())->crear([
            'model_id'                     => $d->id,
            'amount'                       => 1,
            'to_address_id'                => $muerta,
            'article_variant_id'           => $v1->id,
            'concepto_stock_movement_name' => 'Nota de credito',
        ]);

        $this->assertNotNull($movimiento);
        $this->assertSame($principal->id, (int) $movimiento->to_address_id, 'El libro dice el reemplazo.');
        $this->assertSame(0, $this->filas_de_pivot($muerta), 'Ni la variante ni el artículo reabren fila en la muerta.');
        $this->assertEquals(5.0, $this->stock_variante_en($v1, $principal->id));
        $this->assertEquals(5.0, $this->stock_global($d));

        $movimientos = $this->movimientos_de($d)->count();

        $nada = (new StockMovementController())->crear([
            'model_id'                     => $d->id,
            'amount'                       => 2,
            'from_address_id'              => $muerta,
            'to_address_id'                => $principal->id,
            'article_variant_id'           => $v1->id,
            'concepto_stock_movement_name' => 'Mov entre depositos',
        ]);

        $this->assertNull($nada, '"Mov entre depositos" con un depósito muerto no mueve nada.');
        $this->assertSame($movimientos, $this->movimientos_de($d)->count());
        $this->assertEquals(5.0, $this->stock_variante_en($v1, $principal->id));
        $this->assertSame(0, $this->filas_de_pivot($muerta));
    }
}
