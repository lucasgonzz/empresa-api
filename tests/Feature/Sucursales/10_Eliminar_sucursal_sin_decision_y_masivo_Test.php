<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Jobs\ProcessDeleteModelsJob;
use App\Models\Address;
use App\Models\BackgroundProcess;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * Archivo 10 — los caminos que NO traen decisión, la tenencia y el candado (misión
 * eliminar-sucursal-con-stock, 5/10/2026; decisiones D3, D13 y D15 del plan).
 *
 * Lo que fija:
 *
 *  - la SPA vieja (`DELETE` sin parámetros) sobre una sucursal con stock recibe 422
 *    `requiere_decision` y NADA cambia; sin nada que decidir, se borra como siempre;
 *  - el borrado masivo (`PUT delete/address`) respeta ese 422: con un registro (síncrono) lo devuelve
 *    en `not_deleted`, y con dos (en cola) no borra la que tiene stock y sí la otra;
 *  - IDOR: una sucursal de otro comercio da 404 y queda intacta; un id inexistente da 404 (antes 500);
 *  - el candado: un segundo borrado simultáneo recibe 422 y no toca nada;
 *  - un domicilio de comprador se borra como siempre y no toca stock.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_sin_decision_y_masivo_Test extends SucursalesTestCase
{
    /**
     * Test 1 — DELETE sin parámetros con stock: 422 y nada cambia.
     *
     * @test
     */
    public function sin_decision_y_con_stock_es_422_y_nada_cambia()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Sin decision');

        $a = $this->nuevo_articulo('zz Sin decision A');
        $this->cargar_deposito($a, $borrar, 6);
        $this->cargar_deposito($a, $principal, 2);

        $movimientos = $this->movimientos_de($a)->count();

        $respuesta = $this->eliminar_sucursal($borrar->id);

        $respuesta->assertStatus(422);
        $this->assertTrue($respuesta->json('requiere_decision'));
        $this->assertSame(['stock'], $respuesta->json('faltan'));
        $this->assertStringContainsString('zz Sin decision', $respuesta->json('message'));

        $this->assertNotNull(Address::find($borrar->id));
        $this->assertEquals(6.0, $this->stock_en($a, $borrar->id));
        $this->assertEquals(8.0, $this->stock_global($a));
        $this->assertSame($movimientos, $this->movimientos_de($a)->count(), 'Un 422 no deja movimientos.');
    }

    /**
     * Test 2 — sin nada que decidir: se borra como siempre (200).
     *
     * @test
     */
    public function sin_nada_que_decidir_se_borra_como_siempre()
    {
        $borrar = $this->nueva_sucursal('zz Nada que decidir');

        // Fila en 0: no hay stock que decidir.
        $a = $this->nuevo_articulo('zz Nada que decidir A');
        $a->addresses()->attach($borrar->id, ['amount' => 0]);

        $this->eliminar_sucursal($borrar->id)->assertStatus(200)->assertJsonPath('eliminada', true);

        $this->assertNull(Address::find($borrar->id));
        $this->assertSame(0, $this->filas_de_pivot($borrar->id));
    }

    /**
     * Test 3 — el masivo síncrono (un registro) respeta el 422 y lo devuelve en `not_deleted`.
     *
     * @test
     */
    public function el_masivo_de_un_registro_devuelve_el_motivo_en_not_deleted()
    {
        $borrar = $this->nueva_sucursal('zz Masivo uno');

        $a = $this->nuevo_articulo('zz Masivo uno A');
        $this->cargar_deposito($a, $borrar, 3);

        $respuesta = $this->putJson('api/delete/address', ['models_id' => [$borrar->id]]);

        $respuesta->assertStatus(200);
        $this->assertSame([], $respuesta->json('models'), 'La sucursal que no se borró no vuelve como eliminada.');
        $this->assertSame($borrar->id, (int) $respuesta->json('not_deleted.0.id'));
        $this->assertStringContainsString('zz Masivo uno', $respuesta->json('not_deleted.0.message'));

        $this->assertNotNull(Address::find($borrar->id));
        $this->assertEquals(3.0, $this->stock_en($a, $borrar->id));
    }

    /**
     * Test 4 — el masivo en cola (dos registros): no borra la que tiene stock y sí la otra.
     *
     * @test
     */
    public function el_masivo_en_cola_no_borra_la_que_tiene_stock()
    {
        Queue::fake();

        $con_stock = $this->nueva_sucursal('zz Masivo con stock');
        $sin_nada  = $this->nueva_sucursal('zz Masivo sin nada');

        $a = $this->nuevo_articulo('zz Masivo A');
        $this->cargar_deposito($a, $con_stock, 3);

        $this->putJson('api/delete/address', ['models_id' => [$con_stock->id, $sin_nada->id]])
             ->assertStatus(200)
             ->assertJsonPath('queued', true);

        $job = null;

        Queue::assertPushed(ProcessDeleteModelsJob::class, function ($pushed) use (&$job) {
            $job = $pushed;
            return true;
        });

        /*
         * Después de un request el guard por defecto del test queda en Sanctum (RequestGuard), que no
         * tiene loginUsingId(): el job del masivo moriría por eso y no por lo que se prueba. En
         * producción el worker corre con el guard `web`.
         */
        Auth::shouldUse('web');

        $job->handle();

        $this->assertNotNull(Address::find($con_stock->id), 'La sucursal con stock no se borra sin decisión.');
        $this->assertEquals(3.0, $this->stock_en($a, $con_stock->id));
        $this->assertNull(Address::find($sin_nada->id), 'La que no tenía nada que decidir sí se borra.');

        $proceso = BackgroundProcess::where('user_id', $this->comercio()->id)->where('tipo', 'eliminacion_masiva')->orderBy('id', 'DESC')->first();

        $this->assertNotNull($proceso);
        $this->assertSame(1, (int) $proceso->resultado()['eliminados'], 'El masivo cuenta solo la que borró.');
    }

    /**
     * Test 5 — IDOR: la sucursal de otro comercio da 404 y queda intacta; un id inexistente, 404.
     *
     * @test
     */
    public function una_sucursal_ajena_da_404_y_queda_intacta()
    {
        $otro  = $this->otro_comercio();
        $ajena = Address::create(['street' => 'zz Ajena', 'user_id' => $otro->id, 'default_address' => 0]);

        $articulo_ajeno = $this->nuevo_articulo('zz Articulo ajeno', ['user_id' => $otro->id]);
        $articulo_ajeno->addresses()->attach($ajena->id, ['amount' => 5]);

        $this->eliminar_sucursal($ajena->id, ['stock_accion' => 'descartar'])->assertStatus(404)->assertJsonStructure(['message']);

        $this->assertNotNull(Address::find($ajena->id));
        $this->assertEquals(5.0, $this->stock_en($articulo_ajeno, $ajena->id));

        $inexistente = (int) DB::table('addresses')->max('id') + 1000;

        $this->eliminar_sucursal($inexistente)->assertStatus(404)->assertJsonStructure(['message']);
    }

    /**
     * Test 5 bis — un artículo de OTRO comercio con fila en esta sucursal (base compartida, dato
     * cruzado) NO se mueve con la identidad de este dueño ni se le abre fila en el destino: su fila se
     * borra sin movimiento (segunda ronda, hallazgo D).
     *
     * @test
     */
    public function un_articulo_de_otro_comercio_no_se_mueve_y_su_fila_se_borra()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Fila ajena a borrar');
        $destino   = $this->nueva_sucursal('zz Fila ajena destino');

        $propio = $this->nuevo_articulo('zz Fila ajena propio');
        $this->cargar_deposito($propio, $borrar, 2);
        $this->cargar_deposito($propio, $principal, 1);

        $otro  = $this->otro_comercio();
        $ajeno = $this->nuevo_articulo('zz Fila ajena de otro comercio', ['user_id' => $otro->id, 'stock' => 5]);
        DB::table('address_article')->insert(['article_id' => $ajeno->id, 'address_id' => $borrar->id, 'amount' => 5]);

        $this->assertSame(1, $this->getJson('api/address/'.$borrar->id.'/eliminar-resumen')->json('stock.articulos'), 'El resumen no cuenta el artículo ajeno.');

        $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ])->assertStatus(200)->assertJsonPath('resumen.articulos', 1);

        $this->assertSame(0, $this->filas_de_pivot($borrar->id), 'La fila ajena también se borra.');
        $this->assertCount(0, $this->movimientos_de($ajeno), 'El artículo de otro comercio no tiene movimientos.');
        $this->assertNull($this->stock_en($ajeno, $destino->id), 'Ni se le abre fila en el destino de este comercio.');
        $this->assertEquals(2.0, $this->stock_en($propio, $destino->id), 'El propio sí se mueve.');
    }

    /**
     * Test 5 ter — si la eliminación en línea se corta por un error, el 500 dice un mensaje FIJO (el
     * de la excepción, que puede traer SQL o rutas, va solo al log) y el candado queda libre (segunda
     * ronda, F2).
     *
     * El error se provoca con una sucursal que no deja de recibir stock: cada movimiento de la
     * eliminación "vende" otro artículo desde ella, y después de MAXIMO_DE_PASADAS se rinde.
     *
     * @test
     */
    public function un_corte_en_linea_responde_un_mensaje_fijo()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Corte en linea');
        $destino   = $this->nueva_sucursal('zz Corte en linea destino');

        $a = $this->nuevo_articulo('zz Corte en linea A');
        $this->cargar_deposito($a, $borrar, 1);

        $pool = [];
        for ($i = 0; $i < EliminarSucursalHelper::MAXIMO_DE_PASADAS + 1; $i++) {
            $articulo = $this->nuevo_articulo('zz Corte en linea pool '.$i);
            $this->cargar_deposito($articulo, $principal, 5);
            $pool[] = $articulo->id;
        }

        \Illuminate\Support\Facades\Event::listen('eloquent.created: '.\App\Models\StockMovement::class, function () use (&$pool, $borrar) {
            if (count($pool) == 0) {
                return;
            }
            DB::table('address_article')->insert(['article_id' => array_shift($pool), 'address_id' => $borrar->id, 'amount' => -1]);
        });

        $respuesta = $this->eliminar_sucursal($borrar->id, [
            'stock_accion'     => 'transferir',
            'stock_destino_id' => $destino->id,
        ]);

        $respuesta->assertStatus(500);
        $this->assertSame(EliminarSucursalHelper::MENSAJE_SI_SE_CORTA, $respuesta->json('message'));
        $this->assertStringNotContainsString('pasadas', $respuesta->json('message'), 'El texto de la excepción no llega al usuario.');
        $this->assertNotNull(Address::find($borrar->id), 'Cortada, la sucursal no se borra.');
        $this->assertFalse($this->candado_tomado($borrar->id), 'El candado queda libre después del corte.');
    }

    /**
     * Test 6 — el candado: con un borrado en curso EN OTRA CONEXIÓN (otro proceso), el segundo
     * recibe 422 y no toca nada. El candado es `GET_LOCK` de MySQL, por conexión y re-entrante: por
     * eso "el otro" se simula con una segunda conexión PDO.
     *
     * @test
     */
    public function un_segundo_borrado_simultaneo_recibe_422()
    {
        $borrar = $this->nueva_sucursal('zz Candado');

        $a = $this->nuevo_articulo('zz Candado A');
        $this->cargar_deposito($a, $borrar, 3);

        $otro_proceso = $this->otra_conexion();

        $this->assertTrue($this->tomar_candado_desde($otro_proceso, $borrar->id), 'El escenario: "otro" borrado ya tomó el candado.');

        $respuesta = $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar']);

        $respuesta->assertStatus(422);
        $this->assertStringContainsString('ya se está eliminando', $respuesta->json('message'));
        $this->assertNotNull(Address::find($borrar->id));
        $this->assertEquals(3.0, $this->stock_en($a, $borrar->id));
        $this->assertTrue($this->getJson('api/address/'.$borrar->id.'/eliminar-resumen')->json('ya_en_proceso'));

        // El otro "termina": se cierra su conexión, y MySQL suelta el candado solo (el servidor procesa
        // la desconexión en unos milisegundos: se espera hasta 2 segundos a que lo haga).
        $otro_proceso = null;

        for ($intento = 0; $intento < 40 && $this->candado_tomado($borrar->id); $intento++) {
            usleep(50000);
        }

        $this->assertFalse($this->getJson('api/address/'.$borrar->id.'/eliminar-resumen')->json('ya_en_proceso'), 'Muerta la otra conexión, el candado se libera solo.');

        $this->eliminar_sucursal($borrar->id, ['stock_accion' => 'descartar'])->assertStatus(200);
        $this->assertFalse($this->candado_tomado($borrar->id), 'El borrado en línea libera su candado al terminar.');
    }

    /**
     * Test 7 — un domicilio de comprador se borra como siempre y no toca stock.
     *
     * @test
     */
    public function un_domicilio_de_comprador_se_borra_como_siempre()
    {
        $principal = $this->sucursal_principal();

        $a = $this->nuevo_articulo('zz Comprador A');
        $this->cargar_deposito($a, $principal, 4);

        $movimientos = $this->movimientos_de($a)->count();

        $buyer_id  = DB::table('buyers')->insertGetId(['name' => 'zz Comprador', 'user_id' => $this->comercio()->id, 'isVerified' => 0]);
        $domicilio = Address::create(['street' => 'zz Domicilio de envio', 'buyer_id' => $buyer_id]);

        $this->eliminar_sucursal($domicilio->id)->assertStatus(200)->assertJsonPath('eliminada', true);

        $this->assertNull(Address::find($domicilio->id));
        $this->assertEquals(4.0, $this->stock_global($a));
        $this->assertSame($movimientos, $this->movimientos_de($a)->count());
    }
}
