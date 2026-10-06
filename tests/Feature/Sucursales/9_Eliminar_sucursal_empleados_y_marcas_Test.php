<?php

namespace Tests\Feature\Sucursales;

use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 9 — los EMPLEADOS, las MARCAS y la configuración que apuntan a la sucursal eliminada
 * (misión eliminar-sucursal-con-stock, 5/10/2026; decisiones D8, D9, D10 y D11 del plan).
 *
 * Lo que fija:
 *
 *  - `usuarios_accion = reasignar` pasa el dueño y sus empleados a la sucursal elegida;
 *    `dejar_sin_sucursal` los deja en NULL; sin `usuarios_accion` y con empleados asignados → 422 SIN
 *    tocar nada. Un usuario de OTRO comercio con el mismo `address_id` nunca se toca.
 *  - las marcas (por defecto, madre, origen) pasan al reemplazo; sin reemplazo → 422.
 *  - cajas, puntos de venta y clientes pasan al reemplazo o quedan en NULL; los métodos de pago por
 *    defecto de la sucursal se borran.
 *  - un traslado de stock pendiente bloquea el borrado.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_empleados_y_marcas_Test extends SucursalesTestCase
{
    /**
     * @param  int  $user_id
     * @return int|null
     */
    protected function sucursal_de($user_id)
    {
        $address_id = DB::table('users')->where('id', $user_id)->value('address_id');

        return is_null($address_id) ? null : (int) $address_id;
    }

    /**
     * Test 1 — reasignar: el dueño y el empleado pasan al destino; el usuario de otro comercio no.
     *
     * @test
     */
    public function reasignar_pasa_los_usuarios_del_comercio_y_no_toca_los_ajenos()
    {
        $dueno   = $this->comercio();
        $borrar  = $this->nueva_sucursal('zz Empleados a borrar');
        $destino = $this->nueva_sucursal('zz Empleados destino');

        $empleado = $this->nuevo_empleado('zz Guille', $borrar->id);
        DB::table('users')->where('id', $dueno->id)->update(['address_id' => $borrar->id]);

        $otro  = $this->otro_comercio();
        $ajeno = $this->nuevo_empleado('zz Ajeno', $borrar->id, $otro->id);

        $this->eliminar_sucursal($borrar->id, [
            'usuarios_accion'     => 'reasignar',
            'usuarios_destino_id' => $destino->id,
        ])->assertStatus(200)->assertJsonPath('resumen.usuarios', 2);

        $this->assertNull(Address::find($borrar->id));
        $this->assertSame($destino->id, $this->sucursal_de($empleado->id));
        $this->assertSame($destino->id, $this->sucursal_de($dueno->id));
        $this->assertSame($borrar->id, $this->sucursal_de($ajeno->id), 'Un usuario de otro comercio NUNCA se toca.');
    }

    /**
     * Test 2 — dejar sin sucursal: los usuarios quedan en NULL.
     *
     * @test
     */
    public function dejar_sin_sucursal_deja_los_usuarios_en_null()
    {
        $borrar   = $this->nueva_sucursal('zz Empleados sin sucursal');
        $empleado = $this->nuevo_empleado('zz Ana', $borrar->id);

        $this->eliminar_sucursal($borrar->id, ['usuarios_accion' => 'dejar_sin_sucursal'])->assertStatus(200);

        $this->assertNull(Address::find($borrar->id));
        $this->assertNull($this->sucursal_de($empleado->id));
    }

    /**
     * Test 3 — con empleados asignados y sin `usuarios_accion`: 422 y no se toca NADA.
     *
     * @test
     */
    public function sin_usuarios_accion_con_empleados_es_422_y_no_toca_nada()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Empleados sin decision');
        $destino   = $this->nueva_sucursal('zz Empleados sin decision destino');
        $empleado  = $this->nuevo_empleado('zz Beto', $borrar->id);

        $a = $this->nuevo_articulo('zz Empleados sin decision A');
        $this->cargar_deposito($a, $borrar, 4);
        $this->cargar_deposito($a, $principal, 1);

        $respuesta = $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ]);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('requiere_decision'));
        $this->assertContains('usuarios', $respuesta->json('faltan'));
        $this->assertStringContainsString('zz Beto', $respuesta->json('message'));

        $this->assertNotNull(Address::find($borrar->id), 'Un 422 no borra la sucursal.');
        $this->assertSame($borrar->id, $this->sucursal_de($empleado->id));
        $this->assertEquals(4.0, $this->stock_en($a, $borrar->id), 'Un 422 no mueve el stock (todo se valida antes de escribir).');
        $this->assertNull($this->stock_en($a, $destino->id));
        $this->assertCount(0, $this->movimientos_de($a, 'Mov entre depositos'));
    }

    /**
     * Test 4 — las marcas pasan al reemplazo; sin reemplazo, 422.
     *
     * @test
     */
    public function las_marcas_pasan_al_reemplazo_y_sin_reemplazo_es_422()
    {
        $borrar = $this->nueva_sucursal('zz Marcas a borrar', [
            'default_address'    => 1,
            'es_deposito_origen' => 1,
            'es_deposito_madre'  => 1,
        ]);

        $reemplazo = $this->nueva_sucursal('zz Marcas reemplazo');

        // Sin reemplazo: 422.
        $respuesta = $this->eliminar_sucursal($borrar->id);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('requiere_decision'));
        $this->assertContains('reemplazo', $respuesta->json('faltan'));
        $this->assertNotNull(Address::find($borrar->id));

        // Con reemplazo.
        $this->eliminar_sucursal($borrar->id, ['reemplazo_id' => $reemplazo->id])->assertStatus(200);

        $this->assertNull(Address::find($borrar->id));

        $fila = DB::table('addresses')->where('id', $reemplazo->id)->first();

        $this->assertSame(1, (int) $fila->default_address);
        $this->assertSame(1, (int) $fila->es_deposito_origen);
        $this->assertSame(1, (int) $fila->es_deposito_madre);

        $this->assertSame(1, Address::where('user_id', $this->comercio()->id)->where('es_deposito_madre', 1)->count(), 'Sigue habiendo UNA sola madre.');
    }

    /**
     * Test 5 — el destino del stock es el reemplazo por defecto (D9).
     *
     * @test
     */
    public function el_destino_del_stock_es_el_reemplazo_por_defecto()
    {
        $borrar  = $this->nueva_sucursal('zz Marcas implicito', ['default_address' => 1]);
        $destino = $this->nueva_sucursal('zz Marcas implicito destino');

        $a = $this->nuevo_articulo('zz Marcas implicito A');
        $this->cargar_deposito($a, $borrar, 2);

        $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200);

        $this->assertSame(1, (int) DB::table('addresses')->where('id', $destino->id)->value('default_address'));
    }

    /**
     * Test 6 — cajas, puntos de venta y clientes pasan al reemplazo; los métodos de pago por defecto
     * de la sucursal se borran (los de otra sucursal, no).
     *
     * @test
     */
    public function cajas_puntos_de_venta_y_clientes_pasan_al_reemplazo()
    {
        $owner_id  = $this->comercio()->id;
        $borrar    = $this->nueva_sucursal('zz Config a borrar');
        $reemplazo = $this->nueva_sucursal('zz Config reemplazo');

        $caja_id    = DB::table('cajas')->insertGetId(['num' => 990011, 'name' => 'zz Caja', 'user_id' => $owner_id, 'address_id' => $borrar->id]);
        $punto_id   = DB::table('afip_information')->insertGetId(['description' => 'zz PV', 'user_id' => $owner_id, 'address_id' => $borrar->id]);
        $cliente_id = DB::table('clients')->insertGetId(['name' => 'zz Cliente', 'user_id' => $owner_id, 'address_id' => $borrar->id]);

        $default_borrar = DB::table('default_payment_method_cajas')->insertGetId(['current_acount_payment_method_id' => 1, 'caja_id' => $caja_id, 'address_id' => $borrar->id, 'user_id' => $owner_id]);
        $default_otro   = DB::table('default_payment_method_cajas')->insertGetId(['current_acount_payment_method_id' => 1, 'caja_id' => $caja_id, 'address_id' => $reemplazo->id, 'user_id' => $owner_id]);

        $respuesta = $this->eliminar_sucursal($borrar->id, ['reemplazo_id' => $reemplazo->id])->assertStatus(200);

        $this->assertSame($reemplazo->id, (int) DB::table('cajas')->where('id', $caja_id)->value('address_id'));
        $this->assertSame($reemplazo->id, (int) DB::table('afip_information')->where('id', $punto_id)->value('address_id'));
        $this->assertSame($reemplazo->id, (int) DB::table('clients')->where('id', $cliente_id)->value('address_id'));

        $this->assertFalse(DB::table('default_payment_method_cajas')->where('id', $default_borrar)->exists(), 'Los defaults de la sucursal eliminada se borran.');
        $this->assertTrue(DB::table('default_payment_method_cajas')->where('id', $default_otro)->exists(), 'Los de otra sucursal no se tocan.');

        $this->assertSame(1, $respuesta->json('resumen.cajas'));
    }

    /**
     * Test 7 — sin reemplazo (no hay marcas ni destinos), cajas, puntos de venta y clientes quedan
     * en NULL ("de todas las sucursales").
     *
     * @test
     */
    public function sin_reemplazo_la_configuracion_queda_para_todas_las_sucursales()
    {
        $owner_id = $this->comercio()->id;
        $borrar   = $this->nueva_sucursal('zz Config sin reemplazo');

        $caja_id    = DB::table('cajas')->insertGetId(['num' => 990012, 'name' => 'zz Caja 2', 'user_id' => $owner_id, 'address_id' => $borrar->id]);
        $punto_id   = DB::table('afip_information')->insertGetId(['description' => 'zz PV 2', 'user_id' => $owner_id, 'address_id' => $borrar->id]);
        $cliente_id = DB::table('clients')->insertGetId(['name' => 'zz Cliente 2', 'user_id' => $owner_id, 'address_id' => $borrar->id]);

        $this->eliminar_sucursal($borrar->id)->assertStatus(200);

        $this->assertNull(DB::table('cajas')->where('id', $caja_id)->value('address_id'));
        $this->assertNull(DB::table('afip_information')->where('id', $punto_id)->value('address_id'));
        $this->assertNull(DB::table('clients')->where('id', $cliente_id)->value('address_id'));
    }

    /**
     * Test 8 — un traslado de stock pendiente bloquea (422 sin `requiere_decision`), y no se toca
     * nada.
     *
     * @test
     */
    public function un_traslado_pendiente_bloquea_el_borrado()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Bloqueo a borrar');

        DB::table('deposit_movements')->insert([
            'num' => 990021, 'from_address_id' => $principal->id, 'to_address_id' => $borrar->id,
            'deposit_movement_status_id' => 1, 'user_id' => $this->comercio()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $respuesta = $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar']);

        $respuesta->assertStatus(422);
        $this->assertFalse($respuesta->json('requiere_decision'));
        $this->assertSame('traslados_pendientes', $respuesta->json('bloqueos.0.codigo'));
        $this->assertNotNull(Address::find($borrar->id));
    }
}
