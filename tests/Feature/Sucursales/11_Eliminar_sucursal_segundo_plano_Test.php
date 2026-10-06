<?php

namespace Tests\Feature\Sucursales;

use App\Http\Controllers\Helpers\address\EliminarSucursalHelper;
use App\Jobs\EliminarSucursalJob;
use App\Models\Address;
use App\Models\BackgroundProcess;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/**
 * Archivo 11 — la eliminación en SEGUNDO PLANO (misión eliminar-sucursal-con-stock, 5/10/2026;
 * decisiones D13 y D14 del plan).
 *
 * Lo que fija:
 *
 *  - con más filas con stock que el umbral, `DELETE` responde 202 con el registro de proceso, encola
 *    `EliminarSucursalJob`, deja el candado tomado y NO borra todavía;
 *  - el job (su `handle()`, llamado directo) termina con el MISMO resultado que el camino en línea, y
 *    cierra el registro y el candado;
 *  - si entra stock a la sucursal mientras se vacía (una venta), el job repite la pasada antes de
 *    borrar;
 *  - `failed()` (worker muerto) libera el candado y cierra el registro en fallo.
 *
 * El umbral se baja con `EliminarSucursalHelper::$filas_en_linea` (el gancho existe para esto) y se
 * devuelve en tearDown.
 *
 * IMPORTANTE (PHP 7.4): sin match, str_contains, nullsafe (?->), argumentos nombrados, union
 * types, promoción de constructor, readonly, enum ni #[...].
 *
 * @group sucursales
 */
class Eliminar_sucursal_segundo_plano_Test extends SucursalesTestCase
{
    protected function tearDown(): void
    {
        EliminarSucursalHelper::$filas_en_linea = null;

        parent::tearDown();
    }

    /**
     * Arma una sucursal con tres artículos con stock (uno negativo) y un empleado.
     *
     * @param  string  $nombre
     * @return array  [Address, [Article...], User]
     */
    protected function sucursal_con_stock($nombre)
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal($nombre);

        $articulos = [];

        foreach (['A' => 5, 'B' => 7, 'C' => -2] as $sufijo => $cantidad) {
            $articulo = $this->nuevo_articulo($nombre.' '.$sufijo);
            $this->cargar_deposito($articulo, $borrar, $cantidad);
            $this->cargar_deposito($articulo, $principal, 10);
            $articulos[] = $articulo;
        }

        $empleado = $this->nuevo_empleado($nombre.' empleado', $borrar->id);

        return [$borrar, $articulos, $empleado];
    }

    /**
     * Lo que dejó la eliminación, por artículo, para comparar los dos caminos.
     *
     * @param  array  $articulos
     * @param  int    $destino_id
     * @return array
     */
    protected function foto($articulos, $destino_id)
    {
        $foto = [];

        foreach ($articulos as $articulo) {
            $foto[] = [
                'global'      => $this->stock_global($articulo),
                'destino'     => $this->stock_en($articulo, $destino_id),
                'movimientos' => $this->movimientos_de($articulo, 'Mov entre depositos')->count(),
            ];
        }

        return $foto;
    }

    /**
     * Test 1 — 202 + registro + job; el job termina igual que el camino en línea.
     *
     * @test
     */
    public function con_el_umbral_superado_se_encola_y_el_job_termina_igual_que_en_linea()
    {
        Queue::fake();

        $destino = $this->nueva_sucursal('zz Segundo plano destino');

        $decision = [
            'stock_accion'        => 'transferir',
            'stock_destino_id'    => $destino->id,
            'usuarios_accion'     => 'reasignar',
            'usuarios_destino_id' => $destino->id,
        ];

        // El camino en línea, como referencia.
        list($en_linea, $articulos_en_linea, $empleado_en_linea) = $this->sucursal_con_stock('zz En linea');

        $this->eliminar_sucursal($en_linea->id, $decision)->assertStatus(200);

        // El camino en segundo plano.
        list($en_cola, $articulos_en_cola, $empleado_en_cola) = $this->sucursal_con_stock('zz En cola');

        EliminarSucursalHelper::$filas_en_linea = 2;

        $respuesta = $this->eliminar_sucursal($en_cola->id, $decision);

        $respuesta->assertStatus(202);
        $this->assertTrue($respuesta->json('queued'));

        $proceso = BackgroundProcess::find($respuesta->json('background_process_id'));

        $this->assertNotNull($proceso, 'El registro visible nace en el request.');
        $this->assertSame('eliminacion_sucursal', $proceso->tipo);
        $this->assertSame('pendiente', $proceso->status);

        $this->assertNotNull(Address::find($en_cola->id), 'Encolada, la sucursal todavía existe.');

        /*
         * Encolada, nadie tiene el candado de MySQL (lo toma el job al arrancar): lo que dice "ya se
         * está eliminando" es el registro visible activo, y un segundo "Eliminar" recibe 422.
         */
        $this->assertTrue($this->getJson('api/address/'.$en_cola->id.'/eliminar-resumen')->json('ya_en_proceso'));
        $this->eliminar_sucursal($en_cola->id, $decision)->assertStatus(422);
        Queue::assertPushed(EliminarSucursalJob::class, 1);

        $job = null;

        Queue::assertPushed(EliminarSucursalJob::class, function ($pushed) use (&$job) {
            $job = $pushed;
            return true;
        });

        $job->handle();

        $this->assertNull(Address::find($en_cola->id));
        $this->assertSame(0, $this->filas_de_pivot($en_cola->id));
        $this->assertSame($destino->id, (int) DB::table('users')->where('id', $empleado_en_cola->id)->value('address_id'));

        $this->assertSame(
            $this->foto($articulos_en_linea, $destino->id),
            $this->foto($articulos_en_cola, $destino->id),
            'El job tiene que dejar exactamente lo mismo que el camino en línea.'
        );

        $proceso = $proceso->fresh();

        $this->assertSame('completado', $proceso->status);
        $this->assertSame(3, (int) $proceso->resultado()['movimientos']);
        $this->assertFalse($this->candado_tomado($en_cola->id), 'El job libera el candado.');
    }

    /**
     * Test 2 — si entra stock a la sucursal mientras se vacía, el job repite la pasada.
     *
     * @test
     */
    public function el_job_repite_la_pasada_si_entro_una_venta_en_el_medio()
    {
        Queue::fake();

        $principal = $this->sucursal_principal();
        $destino   = $this->nueva_sucursal('zz Pasadas destino');

        list($borrar, $articulos) = $this->sucursal_con_stock('zz Pasadas');

        // Un artículo SIN fila en la sucursal: la "venta del medio" se la va a abrir.
        $tardio = $this->nuevo_articulo('zz Pasadas venta del medio');
        $this->cargar_deposito($tardio, $principal, 5);

        EliminarSucursalHelper::$filas_en_linea = 1;

        $this->eliminar_sucursal($borrar->id, [
            'stock_accion'        => 'transferir',
            'stock_destino_id'    => $destino->id,
            'usuarios_accion'     => 'dejar_sin_sucursal',
        ])->assertStatus(202);

        /*
         * La "venta del medio": cuando el job crea su PRIMER movimiento, entra una venta de 1 unidad
         * del artículo tardío desde la sucursal que se está eliminando (el motor le abre la fila en −1,
         * como hace CheckFromAddress con un artículo que reparte por depósitos). La lista de la pasada
         * ya se armó, así que este artículo solo lo puede encontrar una SEGUNDA pasada.
         */
        $ya_vendio = false;

        Event::listen('eloquent.created: '.StockMovement::class, function () use (&$ya_vendio, $tardio, $borrar) {
            if ($ya_vendio) {
                return;
            }
            $ya_vendio = true;
            DB::table('address_article')->insert(['article_id' => $tardio->id, 'address_id' => $borrar->id, 'amount' => -1]);
        });

        $job = null;

        Queue::assertPushed(EliminarSucursalJob::class, function ($pushed) use (&$job) {
            $job = $pushed;
            return true;
        });

        $job->handle();

        $this->assertTrue($ya_vendio, 'El escenario no se armó: la venta del medio no entró.');

        $this->assertNull(Address::find($borrar->id));
        $this->assertSame(0, $this->filas_de_pivot($borrar->id), 'La fila que abrió la venta del medio también se vació y se borró.');

        $movimientos = $this->movimientos_de($tardio, 'Mov entre depositos');

        $this->assertCount(1, $movimientos, 'La segunda pasada movió el artículo de la venta del medio.');
        $this->assertEqualsWithDelta(-1.0, (float) $movimientos->first()->amount, self::DELTA);
        $this->assertEquals(-1.0, $this->stock_en($tardio, $destino->id));
        $this->assertEqualsWithDelta($this->suma_de_sucursales_vivas($tardio), $this->stock_global($tardio), self::DELTA);

        foreach ($articulos as $articulo) {
            $this->assertCount(1, $this->movimientos_de($articulo, 'Mov entre depositos'));
        }
    }

    /**
     * Test 3 — `failed()` libera el candado y cierra el registro en fallo.
     *
     * @test
     */
    public function failed_libera_el_candado_y_cierra_el_registro()
    {
        $borrar = $this->nueva_sucursal('zz Failed');

        // Como lo haría el job en ESTE proceso antes de morir.
        $this->assertTrue(EliminarSucursalHelper::tomar_candado($this->comercio()->id, $borrar->id));

        $proceso = \App\Http\Controllers\Helpers\BackgroundProcessHelper::iniciar(
            $this->comercio()->id,
            EliminarSucursalHelper::TIPO_DE_PROCESO,
            'zz Eliminación de prueba',
            ['status' => 'pendiente', 'referencia' => $borrar]
        );

        $this->assertTrue($this->getJson('api/address/'.$borrar->id.'/eliminar-resumen')->json('ya_en_proceso'), 'El escenario: el registro abierto dice "ya en proceso".');

        $job = new EliminarSucursalJob($borrar->id, $this->comercio()->id, $this->comercio()->id, ['stock_accion' => 'descartar'], $proceso->id);

        $job->failed(new \Exception('worker muerto'));

        $this->assertFalse($this->candado_tomado($borrar->id), 'failed() no deja el candado tomado.');

        $proceso = $proceso->fresh();

        $this->assertSame('fallo', $proceso->status);
        // Mensaje fijo hacia el usuario (segunda ronda, F2): el de la excepción va solo al log.
        $this->assertSame(EliminarSucursalHelper::MENSAJE_SI_SE_CORTA, $proceso->error_message);
        $this->assertStringNotContainsString('worker muerto', $proceso->error_message);
        $this->assertNotNull(Address::find($borrar->id), 'failed() no borra nada.');

        // Y no queda nada colgado: la sucursal se puede volver a eliminar.
        $this->assertFalse($this->getJson('api/address/'.$borrar->id.'/eliminar-resumen')->json('ya_en_proceso'));
        $this->eliminar_sucursal($borrar->id)->assertStatus(200);
    }

    /**
     * Test 3 bis — el job vuelve a verificar la tenencia: con una sucursal de OTRO comercio no hace
     * nada y cierra su registro en fallo (segunda ronda, F8).
     *
     * @test
     */
    public function el_job_no_toca_una_sucursal_de_otro_comercio()
    {
        $otro  = $this->otro_comercio();
        $ajena = Address::create(['street' => 'zz Job sucursal ajena', 'user_id' => $otro->id, 'default_address' => 0]);

        $articulo_ajeno = $this->nuevo_articulo('zz Job articulo ajeno', ['user_id' => $otro->id]);
        $articulo_ajeno->addresses()->attach($ajena->id, ['amount' => 5]);

        $proceso = \App\Http\Controllers\Helpers\BackgroundProcessHelper::iniciar(
            $this->comercio()->id,
            EliminarSucursalHelper::TIPO_DE_PROCESO,
            'zz Eliminación de prueba',
            ['status' => 'pendiente']
        );

        // El job dice "dueño = el comercio del fixture", pero la sucursal es de otro.
        (new EliminarSucursalJob($ajena->id, $this->comercio()->id, $this->comercio()->id, ['stock_accion' => 'descartar'], $proceso->id))->handle();

        $this->assertNotNull(Address::find($ajena->id), 'La sucursal ajena no se borra.');
        $this->assertEquals(5.0, $this->stock_en($articulo_ajeno, $ajena->id), 'Ni se le toca el stock.');
        $this->assertSame('fallo', $proceso->fresh()->status);
    }

    /**
     * Test 4 — si al salir de la cola el job encuentra el candado tomado por OTRA conexión, no hace
     * nada y cierra su registro en fallo (no lo deja `pendiente` para siempre).
     *
     * @test
     */
    public function el_job_no_hace_nada_si_otra_conexion_tiene_el_candado()
    {
        $principal = $this->sucursal_principal();
        $borrar    = $this->nueva_sucursal('zz Job con candado ajeno');

        $articulo = $this->nuevo_articulo('zz Job con candado ajeno A');
        $this->cargar_deposito($articulo, $borrar, 4);
        $this->cargar_deposito($articulo, $principal, 1);

        $proceso = \App\Http\Controllers\Helpers\BackgroundProcessHelper::iniciar(
            $this->comercio()->id,
            EliminarSucursalHelper::TIPO_DE_PROCESO,
            'zz Eliminación de prueba',
            ['status' => 'pendiente', 'referencia' => $borrar]
        );

        $otro_proceso = $this->otra_conexion();

        $this->assertTrue($this->tomar_candado_desde($otro_proceso, $borrar->id));

        (new EliminarSucursalJob($borrar->id, $this->comercio()->id, $this->comercio()->id, ['stock_accion' => 'descartar'], $proceso->id))->handle();

        $this->assertNotNull(Address::find($borrar->id), 'Con el candado ajeno, el job no borra nada.');
        $this->assertEquals(4.0, $this->stock_en($articulo, $borrar->id), 'Ni mueve stock.');
        $this->assertSame('fallo', $proceso->fresh()->status, 'Y cierra su registro: no queda "pendiente" para siempre.');

        $otro_proceso = null;
    }
}
