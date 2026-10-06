<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 7 — `GET api/address/{id}/eliminar-resumen` (misión eliminar-sucursal-con-stock,
 * 5/10/2026): todo lo que el modal de la SPA necesita para preguntar antes de eliminar.
 *
 * Lo que fija:
 *
 *  - los conteos de stock con signo: filas de las DOS tablas de pivot, negativos, variantes,
 *    artículos en la papelera, y las unidades SIN contar dos veces la fila del artículo que es suma
 *    de sus variantes;
 *  - los usuarios del comercio que la tienen elegida (y no los de otro comercio), las marcas, si
 *    tiene ventas, cajas, puntos de venta y clientes;
 *  - los bloqueos (traslado de stock pendiente), `en_segundo_plano` y `es_la_ultima`;
 *  - 404 para una sucursal ajena o inexistente (antes: 500), y el domicilio de comprador.
 *
 * Toda afirmación se cruza con lo que hay en las tablas: el resumen no puede inventar.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_resumen_Test extends SucursalesTestCase
{
    protected function tearDown(): void
    {
        EliminarSucursalHelper::$filas_en_linea = null;

        parent::tearDown();
    }

    /**
     * @param  int  $address_id
     * @return \Illuminate\Testing\TestResponse
     */
    protected function resumen($address_id)
    {
        return $this->getJson('api/address/'.$address_id.'/eliminar-resumen');
    }

    /**
     * Test 1 — conteos de stock: con signo, negativos, variantes y papelera.
     *
     * @test
     */
    public function el_resumen_cuenta_el_stock_con_signo_las_variantes_y_la_papelera()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Resumen a borrar');

        $a = $this->nuevo_articulo('zz Resumen A');
        $this->cargar_deposito($a, $borrar, 10);
        $this->cargar_deposito($a, $principal, 5);

        $b = $this->nuevo_articulo('zz Resumen B negativo');
        $this->cargar_deposito($b, $borrar, -3);

        $c = $this->nuevo_articulo('zz Resumen C papelera');
        $this->cargar_deposito($c, $borrar, 4);
        $c->delete();

        // Artículo con variantes: la fila del ARTÍCULO en la sucursal es la suma de las variantes (3).
        $d  = $this->nuevo_articulo('zz Resumen D variantes');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $v2 = $this->nueva_variante($d, 'Azul');
        $this->cargar_variante($d, $v1, $borrar, 2);
        $this->cargar_variante($d, $v2, $borrar, 1);
        $this->cargar_variante($d, $v2, $principal, 7);

        $this->assertEquals(3.0, $this->stock_en($d, $borrar->id), 'El escenario no quedó armado: la fila del artículo tiene que ser la suma de sus variantes.');

        // Artículo con fila en 0: no cuenta.
        $e = $this->nuevo_articulo('zz Resumen E en cero');
        $e->addresses()->attach($borrar->id, ['amount' => 0]);

        $respuesta = $this->resumen($borrar->id)->assertStatus(200);

        $stock = $respuesta->json('stock');

        $this->assertSame(4, $stock['articulos'], 'Artículos con stock: A, B, C (papelera) y D (por sus variantes).');
        $this->assertSame(2, $stock['variantes']);
        $this->assertSame(6, $stock['filas'], 'Filas con stock de las dos tablas: A, B, C, D del artículo + 2 de variantes.');
        $this->assertEqualsWithDelta(14.0, (float) $stock['unidades'], self::DELTA, 'Unidades netas: 10 − 3 + 4 + (2 + 1), sin sumar dos veces la fila de D.');
        $this->assertSame(1, $stock['filas_negativas']);
        $this->assertEqualsWithDelta(-3.0, (float) $stock['unidades_negativas'], self::DELTA);
        $this->assertSame(1, $stock['articulos_en_papelera']);

        $this->assertFalse($respuesta->json('es_domicilio_de_comprador'));
        $this->assertFalse($respuesta->json('es_la_ultima'));
        $this->assertSame($borrar->id, $respuesta->json('address.id'));

        $otras = collect($respuesta->json('otras_sucursales'))->pluck('id')->all();
        $this->assertContains($principal->id, $otras);
        $this->assertNotContains($borrar->id, $otras, 'La sucursal que se elimina no puede ofrecerse como destino.');

        // Y nada se tocó: el resumen solo lee.
        $this->assertNotNull(Address::find($borrar->id));
        $this->assertEquals(10.0, $this->stock_en($a, $borrar->id));
    }

    /**
     * Test 2 — usuarios, marcas, ventas, cajas, puntos de venta y clientes.
     *
     * @test
     */
    public function el_resumen_lista_usuarios_del_comercio_marcas_y_configuracion_que_apunta_a_la_sucursal()
    {
        $dueno  = $this->comercio();
        $borrar = $this->nueva_sucursal('zz Resumen marcas', ['default_address' => 1, 'es_deposito_origen' => 1]);

        $empleado = $this->nuevo_empleado('zz Guille', $borrar->id);

        DB::table('users')->where('id', $dueno->id)->update(['address_id' => $borrar->id]);

        // Un usuario de OTRO comercio con el mismo address_id (dato viejo): no es de este comercio.
        $otro = $this->otro_comercio();
        $ajeno = $this->nuevo_empleado('zz Ajeno', $borrar->id, $otro->id);

        DB::table('sales')->insert(['num' => 990001, 'user_id' => $dueno->id, 'address_id' => $borrar->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cajas')->insert(['num' => 990001, 'name' => 'zz Caja sucursal', 'user_id' => $dueno->id, 'address_id' => $borrar->id]);
        DB::table('afip_information')->insert(['description' => 'zz Punto de venta', 'user_id' => $dueno->id, 'address_id' => $borrar->id]);
        DB::table('clients')->insert(['name' => 'zz Cliente sucursal', 'user_id' => $dueno->id, 'address_id' => $borrar->id]);

        $respuesta = $this->resumen($borrar->id)->assertStatus(200);

        $usuarios = collect($respuesta->json('usuarios'));

        $this->assertCount(2, $usuarios, 'Tienen que estar el dueño y el empleado, y nadie más.');
        $this->assertTrue($usuarios->firstWhere('id', $dueno->id)['es_dueno']);
        $this->assertFalse($usuarios->firstWhere('id', $empleado->id)['es_dueno']);
        $this->assertSame('zz Guille', $usuarios->firstWhere('id', $empleado->id)['name']);
        $this->assertNull($usuarios->firstWhere('id', $ajeno->id), 'Un usuario de otro comercio no se lista.');

        $this->assertEqualsCanonicalizing(['default_address', 'es_deposito_origen'], $respuesta->json('marcas'));
        $this->assertTrue($respuesta->json('requiere_reemplazo'));
        $this->assertTrue($respuesta->json('tiene_ventas'));
        $this->assertSame(1, $respuesta->json('cajas'));
        $this->assertSame(1, $respuesta->json('puntos_de_venta'));
        $this->assertSame(1, $respuesta->json('clientes'));
        $this->assertSame([], $respuesta->json('bloqueos'));
        $this->assertSame(0, $respuesta->json('stock.filas'));
    }

    /**
     * Test 3 — un traslado pendiente es un bloqueo; uno ya movido no.
     *
     * @test
     */
    public function un_traslado_pendiente_aparece_como_bloqueo_y_uno_movido_no()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Resumen bloqueo');
        $owner_id  = $this->comercio()->id;

        DB::table('deposit_movements')->insert([
            'num' => 990001, 'from_address_id' => $borrar->id, 'to_address_id' => $principal->id,
            'deposit_movement_status_id' => 1, 'user_id' => $owner_id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // Ya movido (por el botón o por la versión vieja): no bloquea.
        DB::table('deposit_movements')->insert([
            'num' => 990002, 'from_address_id' => $principal->id, 'to_address_id' => $borrar->id,
            'deposit_movement_status_id' => 1, 'user_id' => $owner_id, 'stock_moved_at' => now(), 'recibido_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $bloqueos = $this->resumen($borrar->id)->assertStatus(200)->json('bloqueos');

        $this->assertCount(1, $bloqueos);
        $this->assertSame('traslados_pendientes', $bloqueos[0]['codigo']);
        $this->assertSame(1, $bloqueos[0]['cantidad']);
        $this->assertNotEmpty($bloqueos[0]['mensaje']);
    }

    /**
     * Test 4 — `en_segundo_plano` cuando las filas con stock superan el umbral, y `es_la_ultima`.
     *
     * @test
     */
    public function en_segundo_plano_y_es_la_ultima()
    {
        $borrar = $this->nueva_sucursal('zz Resumen umbral');

        $a = $this->nuevo_articulo('zz Resumen umbral A');
        $b = $this->nuevo_articulo('zz Resumen umbral B');
        $this->cargar_deposito($a, $borrar, 1);
        $this->cargar_deposito($b, $borrar, 1);

        $this->assertFalse($this->resumen($borrar->id)->json('en_segundo_plano'), 'Con 2 filas y el umbral de 150 se hace en línea.');

        EliminarSucursalHelper::$filas_en_linea = 1;

        $this->assertTrue($this->resumen($borrar->id)->json('en_segundo_plano'), 'Con 2 filas y umbral 1 va a segundo plano.');

        // La última sucursal: no hay a dónde pasar nada ni nada que mover (D6).
        $this->dejar_solo($borrar);

        $respuesta = $this->resumen($borrar->id)->assertStatus(200);

        $this->assertTrue($respuesta->json('es_la_ultima'));
        $this->assertSame([], $respuesta->json('otras_sucursales'));
        $this->assertFalse($respuesta->json('en_segundo_plano'), 'En la última sucursal no se mueve stock: nunca va a segundo plano.');
    }

    /**
     * Test 5 — 404 para una sucursal de otro comercio y para un id que no existe.
     *
     * @test
     */
    public function una_sucursal_ajena_o_inexistente_da_404()
    {
        $otro = $this->otro_comercio();

        $ajena = Address::create(['street' => 'zz Sucursal ajena', 'user_id' => $otro->id, 'default_address' => 0]);

        // El 404 es el de la API ("no existe"), no el de una ruta que no existe.
        $respuesta = $this->resumen($ajena->id)->assertStatus(404);
        $this->assertStringContainsString('no existe', (string) $respuesta->json('message'));

        $inexistente = (int) DB::table('addresses')->max('id') + 1000;

        $respuesta = $this->resumen($inexistente)->assertStatus(404);
        $this->assertStringContainsString('no existe', (string) $respuesta->json('message'));
    }

    /**
     * Test 6 — un domicilio de comprador (lo escribe tienda-api, sin user_id) es del comercio si el
     * comprador es suyo, y se marca como tal.
     *
     * @test
     */
    public function un_domicilio_de_comprador_se_marca_como_tal()
    {
        $buyer_id = DB::table('buyers')->insertGetId(['name' => 'zz Comprador', 'user_id' => $this->comercio()->id, 'isVerified' => 0]);

        $domicilio = Address::create(['street' => 'zz Domicilio de envio', 'buyer_id' => $buyer_id]);

        $respuesta = $this->resumen($domicilio->id)->assertStatus(200);

        $this->assertTrue($respuesta->json('es_domicilio_de_comprador'));
        $this->assertSame(0, $respuesta->json('stock.filas'));
        $this->assertSame([], $respuesta->json('usuarios'));

        // El de un comprador de OTRO comercio no.
        $otro = $this->otro_comercio();
        $buyer_ajeno = DB::table('buyers')->insertGetId(['name' => 'zz Comprador ajeno', 'user_id' => $otro->id, 'isVerified' => 0]);
        $domicilio_ajeno = Address::create(['street' => 'zz Domicilio ajeno', 'buyer_id' => $buyer_ajeno]);

        $this->resumen($domicilio_ajeno->id)->assertStatus(404);
    }
}
