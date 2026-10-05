<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Models\Address;
use Illuminate\Support\Facades\DB;

/**
 * Archivo 8 — qué pasa con el STOCK al eliminar una sucursal (misión eliminar-sucursal-con-stock,
 * 5/10/2026; decisiones D4, D5, D6, D7 y D13 del plan).
 *
 * Lo que fija, siempre leyendo las tablas:
 *
 *  - TRANSFERIR: la fila de la sucursal desaparece, el destino suma (con o sin fila previa, también
 *    stock negativo), el stock global NO cambia, y queda UN "Mov entre depositos" por artículo con
 *    origen y destino, firmado y con `stock_resultante` coherente. Un artículo con 0 no genera nada.
 *  - DESCARTAR: el global pasa a ser la suma de las sucursales que quedan (sube si la eliminada
 *    tenía negativo) y queda UN "Eliminacion de sucursal" por artículo.
 *  - VARIANTES: `address_article_variant` queda sin filas de la eliminada, el pivot del artículo se
 *    reconstruye y el global es la suma de las variantes (el defecto viejo: el recálculo deshacía el
 *    descuento del artículo).
 *  - PAPELERA (D7): se resuelve por SQL, sin renglón en el libro.
 *  - ÚLTIMA SUCURSAL (D6): el global queda intacto y no hay movimientos.
 *  - Filas repetidas del mismo par: se juntan antes de mover (sin índice único en el pivot).
 *  - IDEMPOTENCIA: cortado a la mitad, volver a eliminar termina bien y no mueve dos veces.
 *  - En TODOS los casos, ninguna fila de pivot queda nombrando a la sucursal eliminada.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_stock_Test extends SucursalesTestCase
{
    /**
     * Afirma que la sucursal ya no existe y que ninguna fila de pivot la nombra.
     *
     * @param  int  $address_id
     * @return void
     */
    protected function assert_eliminada_sin_rastro($address_id)
    {
        $this->assertNull(Address::find($address_id), 'La sucursal tiene que quedar eliminada.');
        $this->assertSame(0, $this->filas_de_pivot($address_id), 'Ninguna fila de address_article ni address_article_variant puede seguir nombrando a la sucursal eliminada.');
    }

    /**
     * Test 1 — transferir: global intacto, destino suma, un movimiento por artículo.
     *
     * @test
     */
    public function transferir_pasa_el_stock_al_destino_sin_cambiar_el_global()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Stock a borrar');
        $destino   = $this->nueva_sucursal('zz Stock destino');

        $a = $this->nuevo_articulo('zz Transferir A');
        $this->cargar_deposito($a, $borrar, 10);
        $this->cargar_deposito($a, $destino, 4);
        $this->cargar_deposito($a, $principal, 1);

        // Destino sin fila previa.
        $b = $this->nuevo_articulo('zz Transferir B sin fila en destino');
        $this->cargar_deposito($b, $borrar, 6);

        // Stock negativo: el destino BAJA lo mismo (no aparece stock de la nada).
        $c = $this->nuevo_articulo('zz Transferir C negativo');
        $this->cargar_deposito($c, $borrar, -3);
        $this->cargar_deposito($c, $principal, 5);

        // En cero: no genera movimiento.
        $e = $this->nuevo_articulo('zz Transferir E en cero');
        $this->cargar_deposito($e, $principal, 2);
        $e->addresses()->attach($borrar->id, ['amount' => 0]);

        $this->assertEquals(15.0, $this->stock_global($a));
        $this->assertEquals(6.0, $this->stock_global($b));
        $this->assertEquals(2.0, $this->stock_global($c));

        $respuesta = $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ]);

        $respuesta->assertStatus(200);
        $this->assertTrue($respuesta->json('eliminada'));

        $this->assert_eliminada_sin_rastro($borrar->id);

        // A: el destino suma, el global no cambia.
        $this->assertEquals(14.0, $this->stock_en($a, $destino->id));
        $this->assertEquals(1.0, $this->stock_en($a, $principal->id));
        $this->assertEquals(15.0, $this->stock_global($a), 'Transferir no cambia el stock global.');

        // B: el destino nace con lo que tenía la eliminada.
        $this->assertEquals(6.0, $this->stock_en($b, $destino->id));
        $this->assertEquals(6.0, $this->stock_global($b));

        // C: el negativo se traslada.
        $this->assertEquals(-3.0, $this->stock_en($c, $destino->id));
        $this->assertEquals(5.0, $this->stock_en($c, $principal->id));
        $this->assertEquals(2.0, $this->stock_global($c));

        // E: sin movimiento y el global igual.
        $this->assertCount(0, $this->movimientos_de($e, 'Mov entre depositos'));
        $this->assertEquals(2.0, $this->stock_global($e));

        // Un movimiento por artículo, con origen, destino, firma y stock resultante.
        $esperados = [[$a, 10.0, 15.0], [$b, 6.0, 6.0], [$c, -3.0, 2.0]];

        foreach ($esperados as $esperado) {

            list($articulo, $cantidad, $resultante) = $esperado;

            $movimientos = $this->movimientos_de($articulo, 'Mov entre depositos');

            $this->assertCount(1, $movimientos, 'Tiene que quedar UN "Mov entre depositos" para '.$articulo->name.'.');

            $movimiento = $movimientos->first();

            $this->assertSame($borrar->id, (int) $movimiento->from_address_id);
            $this->assertSame($destino->id, (int) $movimiento->to_address_id);
            $this->assertEqualsWithDelta($cantidad, (float) $movimiento->amount, self::DELTA);
            $this->assertSame($this->comercio()->id, (int) $movimiento->user_id);
            $this->assertSame($this->comercio()->id, (int) $movimiento->employee_id, 'El movimiento queda firmado por quien eliminó la sucursal.');
            $this->assertEqualsWithDelta($resultante, (float) $movimiento->stock_resultante, self::DELTA);

            // El invariante de la misión: global = suma de las sucursales que existen.
            $this->assertEqualsWithDelta($this->suma_de_sucursales_vivas($articulo), $this->stock_global($articulo), self::DELTA);
        }
    }

    /**
     * Test 2 — descartar: el global es la suma de las que quedan; sube si la eliminada tenía
     * negativo; un "Eliminacion de sucursal" por artículo.
     *
     * @test
     */
    public function descartar_deja_el_global_igual_a_la_suma_de_las_que_quedan()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Descartar a borrar');

        $a = $this->nuevo_articulo('zz Descartar A');
        $this->cargar_deposito($a, $borrar, 10);
        $this->cargar_deposito($a, $principal, 5);

        $c = $this->nuevo_articulo('zz Descartar C negativo');
        $this->cargar_deposito($c, $borrar, -3);
        $this->cargar_deposito($c, $principal, 5);

        $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar'])
             ->assertStatus(200)
             ->assertJsonPath('eliminada', true)
             ->assertJsonPath('resumen.stock_accion', 'descartar')
             ->assertJsonPath('resumen.movimientos', 2);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertEquals(5.0, $this->stock_global($a), 'Descartar: el global baja en lo que tenía la eliminada.');
        $this->assertEquals(5.0, $this->stock_global($c), 'Descartar con negativo: el global SUBE en lo que le faltaba a la eliminada.');

        $mov_a = $this->movimientos_de($a, 'Eliminacion de sucursal');
        $mov_c = $this->movimientos_de($c, 'Eliminacion de sucursal');

        $this->assertCount(1, $mov_a);
        $this->assertCount(1, $mov_c);
        $this->assertEqualsWithDelta(-10.0, (float) $mov_a->first()->amount, self::DELTA);
        $this->assertEqualsWithDelta(3.0, (float) $mov_c->first()->amount, self::DELTA);
        $this->assertSame($borrar->id, (int) $mov_a->first()->from_address_id);
        $this->assertNull($mov_a->first()->to_address_id);
        $this->assertEqualsWithDelta(5.0, (float) $mov_a->first()->stock_resultante, self::DELTA);

        $this->assertEqualsWithDelta($this->suma_de_sucursales_vivas($a), $this->stock_global($a), self::DELTA);
        $this->assertEqualsWithDelta($this->suma_de_sucursales_vivas($c), $this->stock_global($c), self::DELTA);
    }

    /**
     * Test 3 — variantes, transferir: un movimiento por variante, el pivot del artículo se
     * reconstruye y el global es la suma de las variantes.
     *
     * @test
     */
    public function transferir_mueve_cada_variante_y_reconstruye_el_articulo()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Variantes a borrar');
        $destino   = $this->nueva_sucursal('zz Variantes destino');

        $d  = $this->nuevo_articulo('zz Variantes transferir');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $v2 = $this->nueva_variante($d, 'Azul');
        $this->cargar_variante($d, $v1, $borrar, 2);
        $this->cargar_variante($d, $v1, $principal, 1);
        $this->cargar_variante($d, $v2, $borrar, 3);

        $this->assertEquals(6.0, $this->stock_global($d));

        $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertEquals(2.0, $this->stock_variante_en($v1, $destino->id));
        $this->assertEquals(1.0, $this->stock_variante_en($v1, $principal->id));
        $this->assertEquals(3.0, $this->stock_variante_en($v2, $destino->id));

        $this->assertEquals(3.0, $this->stock_de_variante($v1));
        $this->assertEquals(3.0, $this->stock_de_variante($v2));

        $this->assertEquals(5.0, $this->stock_en($d, $destino->id), 'El pivot del artículo se reconstruye desde las variantes.');
        $this->assertEquals(1.0, $this->stock_en($d, $principal->id));
        $this->assertEquals(6.0, $this->stock_global($d), 'Transferir no cambia el global: suma de las variantes.');

        $movimientos = $this->movimientos_de($d, 'Mov entre depositos');

        $this->assertCount(2, $movimientos, 'Un movimiento por VARIANTE, ninguno del artículo suelto.');
        $this->assertEqualsCanonicalizing([$v1->id, $v2->id], $movimientos->pluck('article_variant_id')->map(function ($id) {
            return (int) $id;
        })->all());

        // Sin huérfanos: ninguna fila del artículo o sus variantes apunta a una sucursal inexistente.
        $huerfanas = DB::table('address_article')
                        ->leftJoin('addresses', 'addresses.id', '=', 'address_article.address_id')
                        ->where('address_article.article_id', $d->id)
                        ->whereNull('addresses.id')
                        ->count();

        $huerfanas += DB::table('address_article_variant')
                        ->leftJoin('addresses', 'addresses.id', '=', 'address_article_variant.address_id')
                        ->whereIn('address_article_variant.article_variant_id', [$v1->id, $v2->id])
                        ->whereNull('addresses.id')
                        ->count();

        $this->assertSame(0, $huerfanas);
    }

    /**
     * Test 4 — variantes, descartar: cada variante baja lo suyo y el global es la suma de variantes.
     * Es el caso que el borrado viejo dejaba roto (el libro decía −N y el stock no se movía).
     *
     * @test
     */
    public function descartar_baja_cada_variante_y_el_global_es_la_suma_de_variantes()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Variantes descartar');

        $d  = $this->nuevo_articulo('zz Variantes descartar');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $v2 = $this->nueva_variante($d, 'Azul');
        $this->cargar_variante($d, $v1, $borrar, 2);
        $this->cargar_variante($d, $v1, $principal, 1);
        $this->cargar_variante($d, $v2, $borrar, 3);

        $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar'])->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertEquals(1.0, $this->stock_de_variante($v1));
        $this->assertEquals(0.0, $this->stock_de_variante($v2));
        $this->assertEquals(1.0, $this->stock_global($d), 'El global es la suma de las variantes que quedan.');
        $this->assertEquals(1.0, $this->stock_en($d, $principal->id));

        $movimientos = $this->movimientos_de($d, 'Eliminacion de sucursal');

        $this->assertCount(2, $movimientos);
        $this->assertEqualsWithDelta(-5.0, (float) $movimientos->sum('amount'), self::DELTA);
    }

    /**
     * Test 5 — artículos en la papelera (D7): se resuelven por SQL, sin renglón en el libro.
     *
     * @test
     */
    public function los_articulos_en_la_papelera_se_resuelven_sin_libro()
    {
        $principal = $this->sucursal_principal();
        $destino   = $this->nueva_sucursal('zz Papelera destino');

        // Transferir.
        $borrar_1 = $this->nueva_sucursal('zz Papelera transferir');

        $p = $this->nuevo_articulo('zz Papelera P');
        $this->cargar_deposito($p, $borrar_1, 4);
        $this->cargar_deposito($p, $principal, 1);
        $p->delete();

        $movimientos_antes = $this->movimientos_de($p)->count();

        $this->eliminar_sucursal($borrar_1->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200)->assertJsonPath('resumen.articulos_en_papelera', 1);

        $this->assert_eliminada_sin_rastro($borrar_1->id);

        $this->assertEquals(4.0, $this->stock_en($p, $destino->id));
        $this->assertEquals(5.0, $this->stock_global($p));
        $this->assertSame($movimientos_antes, $this->movimientos_de($p)->count(), 'D7: sin renglón en el libro.');

        // Descartar.
        $borrar_2 = $this->nueva_sucursal('zz Papelera descartar');

        $q = $this->nuevo_articulo('zz Papelera Q');
        $this->cargar_deposito($q, $borrar_2, 4);
        $this->cargar_deposito($q, $principal, 1);
        $q->delete();

        $this->eliminar_sucursal($borrar_2->id, ['stock_accion' => 'descartar'])->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar_2->id);

        $this->assertEquals(1.0, $this->stock_global($q), 'Descartar recalcula el global del artículo en la papelera.');
    }

    /**
     * Test 6 — la última sucursal (D6): el global queda intacto y no hay movimientos, aunque no se
     * mande ninguna decisión (no hay nada que decidir).
     *
     * @test
     */
    public function la_ultima_sucursal_conserva_el_stock_total_sin_movimientos()
    {
        $borrar = $this->nueva_sucursal('zz Ultima sucursal');

        $a = $this->nuevo_articulo('zz Ultima A');
        $this->cargar_deposito($a, $borrar, 10);

        $d  = $this->nuevo_articulo('zz Ultima variantes');
        $v1 = $this->nueva_variante($d, 'Rojo');
        $this->cargar_variante($d, $v1, $borrar, 2);

        $this->dejar_solo($borrar);

        $movimientos_a = $this->movimientos_de($a)->count();
        $movimientos_d = $this->movimientos_de($d)->count();

        $this->eliminar_sucursal($borrar->id)->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertEquals(10.0, $this->stock_global($a), 'D6: el artículo conserva su stock total.');
        $this->assertEquals(2.0, $this->stock_de_variante($v1));
        $this->assertEquals(2.0, $this->stock_global($d));
        $this->assertSame($movimientos_a, $this->movimientos_de($a)->count(), 'D6: sin movimientos.');
        $this->assertSame($movimientos_d, $this->movimientos_de($d)->count());
    }

    /**
     * Test 6 bis — la última sucursal con `stock_accion=descartar` (lo que puede mandar la SPA): se
     * acepta y se trata igual que sin decisión (D6): el global queda intacto y no hay movimientos.
     * "Descartar" en la última sucursal no puede dejar todo el catálogo en 0.
     *
     * @test
     */
    public function la_ultima_sucursal_con_descartar_tambien_conserva_el_stock_total()
    {
        $borrar = $this->nueva_sucursal('zz Ultima sucursal descartar');

        $a = $this->nuevo_articulo('zz Ultima descartar A');
        $this->cargar_deposito($a, $borrar, 10);

        $this->dejar_solo($borrar);

        $movimientos = $this->movimientos_de($a)->count();

        $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar'])
             ->assertStatus(200)
             ->assertJsonPath('eliminada', true)
             ->assertJsonPath('resumen.es_la_ultima', true)
             ->assertJsonPath('resumen.movimientos', 0);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertEquals(10.0, $this->stock_global($a), 'D6: en la última sucursal "descartar" conserva el stock total.');
        $this->assertSame($movimientos, $this->movimientos_de($a)->count(), 'D6: sin movimientos.');
    }

    /**
     * Test 7 — filas repetidas del mismo par (el pivot no tiene índice único): se juntan antes de
     * mover, y la sucursal queda en 0 exacto (sin juntarlas, el motor le restaba a cada una).
     *
     * @test
     */
    public function las_filas_repetidas_se_juntan_antes_de_mover()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Repetidas a borrar');
        $destino   = $this->nueva_sucursal('zz Repetidas destino');

        $a = $this->nuevo_articulo('zz Repetidas A');
        $this->cargar_deposito($a, $principal, 1);
        $this->cargar_deposito($a, $borrar, 3);
        $this->cargar_deposito($a, $destino, 1);

        // Las segundas filas del mismo par, como las dejan los datos viejos.
        DB::table('address_article')->insert(['article_id' => $a->id, 'address_id' => $borrar->id, 'amount' => 2]);
        DB::table('address_article')->insert(['article_id' => $a->id, 'address_id' => $destino->id, 'amount' => 1]);
        DB::table('articles')->where('id', $a->id)->update(['stock' => 8]);

        $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar->id);

        $this->assertSame(1, DB::table('address_article')->where('article_id', $a->id)->where('address_id', $destino->id)->count(), 'Las filas repetidas del destino quedan en una.');
        $this->assertEquals(7.0, $this->stock_en($a, $destino->id), 'Destino: 1 + 1 de antes, más los 3 + 2 de la eliminada.');
        $this->assertEquals(8.0, $this->stock_global($a));
    }

    /**
     * Test 9 — una fila de variante HUÉRFANA (su artículo se borró físicamente) con stock no deja la
     * sucursal imborrable: la pasada no la mueve (no hay artículo) y la fase final la tiene que contar
     * igual que la pasada, o sea no contarla (segunda ronda, hallazgo C). Se borra con la sucursal.
     *
     * @test
     */
    public function una_variante_huerfana_con_stock_no_deja_la_sucursal_imborrable()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Variante huerfana');

        $articulo = $this->nuevo_articulo('zz Variante huerfana A');
        $variante = $this->nueva_variante($articulo, 'Talle huerfano');

        $this->cargar_variante($articulo, $variante, $borrar, 3);

        // Un artículo con stock de verdad, para que haya una pasada.
        $otro = $this->nuevo_articulo('zz Variante huerfana B');
        $this->cargar_deposito($otro, $borrar, 2);
        $this->cargar_deposito($otro, $principal, 1);

        // El borrado FÍSICO del artículo (dato viejo): la variante y su fila quedan huérfanas.
        DB::table('articles')->where('id', $articulo->id)->delete();

        $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar'])->assertStatus(200);

        $this->assert_eliminada_sin_rastro($borrar->id);
        $this->assertEquals(1.0, $this->stock_global($otro));
    }

    /**
     * Test 8 — idempotencia: se corta a la mitad (un artículo de tres) y volver a eliminar termina
     * bien, sin mover nada dos veces.
     *
     * @test
     */
    public function cortada_a_la_mitad_volver_a_eliminar_termina_sin_mover_dos_veces()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Idempotencia a borrar');
        $destino   = $this->nueva_sucursal('zz Idempotencia destino');

        $articulos = [];

        foreach (['A' => 5, 'B' => 7, 'C' => -2] as $nombre => $cantidad) {
            $articulo = $this->nuevo_articulo('zz Idempotencia '.$nombre);
            $this->cargar_deposito($articulo, $borrar, $cantidad);
            $this->cargar_deposito($articulo, $principal, 10);
            $articulos[] = [$articulo, $cantidad];
        }

        $decision = ['stock_accion' => 'transferir', 'stock_destino_id' => $destino->id];

        // El corte: procesa UN artículo y se "cae" antes de la fase final.
        $parcial = EliminarSucursalHelper::ejecutar($borrar->id, $this->comercio()->id, $this->comercio()->id, $decision, null, 1);

        $this->assertTrue($parcial['ok']);
        $this->assertSame(1, $parcial['resumen']['articulos']);
        $this->assertNotNull(Address::find($borrar->id), 'Cortada a la mitad, la sucursal sigue existiendo.');

        // Volver a apretar Eliminar.
        $this->eliminar_sucursal($borrar->id, $decision)->assertStatus(200)->assertJsonPath('resumen.articulos', 2);

        $this->assert_eliminada_sin_rastro($borrar->id);

        foreach ($articulos as $par) {

            list($articulo, $cantidad) = $par;

            $this->assertCount(1, $this->movimientos_de($articulo, 'Mov entre depositos'), 'Cada artículo se mueve UNA vez, aunque la eliminación se haya cortado.');
            $this->assertEquals((float) $cantidad, $this->stock_en($articulo, $destino->id));
            $this->assertEquals(10.0 + $cantidad, $this->stock_global($articulo));
        }
    }
}
