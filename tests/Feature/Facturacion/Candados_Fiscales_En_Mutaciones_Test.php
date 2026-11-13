<?php

namespace Tests\Feature\Facturacion;

use App\Http\Controllers\Helpers\Afip\AfipNotaCreditoHelper;
use App\Http\Controllers\Helpers\Afip\CandadoDeFacturaHelper;
use App\Http\Controllers\Helpers\Afip\MakeAfipTicket;
use App\Http\Controllers\Helpers\Afip\TransporteDeFacturaHelper;
use App\Http\Controllers\Helpers\Devoluciones\FacturarNotaCreditoExistenteHelper;
use App\Http\Controllers\Helpers\MasiveUpdateHelper;
use App\Http\Controllers\Helpers\sale\DeleteSaleHelper;
use App\Http\Controllers\SaleController;
use App\Models\AfipTicket;
use App\Models\CurrentAcount;
use App\Models\MasiveUpdate;
use App\Models\Sale;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\EmpresaTestCase;

/** Ticket #121: los mutadores comparten la exclusión de la factura, con dos conexiones reales. */
class Candados_Fiscales_En_Mutaciones_Test extends EmpresaTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Event::fake();
        Notification::fake();
        Queue::fake();
        // Aun si una guarda regresa, ningún test de este archivo puede construir el emisor SOAP.
        $this->app->bind(AfipNotaCreditoHelper::class, function () {
            throw new \LogicException('Este test no permite construir un emisor ARCA.');
        });
    }

    /** @test */
    public function una_baja_comun_no_borra_mientras_otra_conexion_factura_la_venta()
    {
        $venta = $this->venta();
        $this->ocupada($venta, function () use ($venta) {
            $this->rechaza(function () use ($venta) {
                DeleteSaleHelper::eliminar_venta($venta, new SaleController());
            });
        });
        $this->assertNotNull(Sale::find($venta->id));
    }

    /** @test */
    public function una_baja_con_modelo_viejo_revalida_la_factura_guardada_antes_de_eliminar()
    {
        $venta = $this->venta();
        $venta->load('afip_tickets');
        $this->assertNull(DeleteSaleHelper::motivo_por_el_que_no_se_puede_eliminar($venta));
        AfipTicket::create(['sale_id' => $venta->id, 'cae' => '71279049124261', 'resultado' => 'A']);
        $this->rechaza(function () use ($venta) {
            DeleteSaleHelper::eliminar_venta($venta, new SaleController());
        }, 'factura autorizada');
        $this->assertNotNull(Sale::find($venta->id));
    }

    /** @test */
    public function la_devolucion_no_crea_nota_ni_toca_stock_durante_una_factura_en_curso()
    {
        $venta = $this->venta();
        $antes = CurrentAcount::count();
        $this->ocupada($venta, function () use ($venta) {
            $this->postJson('api/devoluciones', [
                'sale_id' => $venta->id, 'items' => [], 'update_unidades_devueltas' => true,
                'regresar_stock' => true, 'generar_current_acount' => false,
            ])->assertStatus(422)->assertJsonFragment([
                'message' => 'Hay una facturación o consolidación en curso. Esperá a que termine antes de volver a confirmar.',
            ]);
        });
        $this->assertSame($antes, CurrentAcount::count());
        $this->assertEquals(100, $venta->fresh()->total);
    }

    /** @test */
    public function una_nota_existente_no_se_emite_ni_cambia_el_saldo_durante_otra_factura()
    {
        $venta = $this->venta();
        $nota = CurrentAcount::create([
            'user_id' => $venta->user_id, 'sale_id' => $venta->id, 'status' => 'nota_credito', 'haber' => 10,
        ]);
        $antes = AfipTicket::count();
        $this->ocupada($venta, function () use ($venta, $nota) {
            $this->postJson('api/nota-credito/'.$nota->id.'/facturar', [])->assertStatus(422)->assertJsonFragment([
                'message' => 'Hay una facturación o consolidación en curso. Esperá a que termine antes de volver a confirmar.',
            ]);
        });
        $this->assertSame($antes, AfipTicket::count());
        $this->assertEquals(0, (float) $venta->fresh()->total_facturado);
    }

    /** @test */
    public function la_actualizacion_masiva_de_ventas_se_rechaza_sin_escribir_si_la_factura_esta_en_curso()
    {
        $venta = $this->venta();
        $masiva = $this->masiva($venta);
        $this->ocupada($venta, function () use ($masiva) {
            $this->rechaza(function () use ($masiva) {
                MasiveUpdateHelper::process_update($masiva);
            });
        });
        $this->assertEquals(100, $venta->fresh()->total);
        $this->assertSame('pending', $masiva->fresh()->status);
    }

    /** @test */
    public function la_masiva_conserva_su_funcion_y_la_reversion_usa_el_mismo_candado()
    {
        $venta = $this->venta();
        $masiva = $this->masiva($venta);
        MasiveUpdateHelper::process_update($masiva);
        $this->assertEquals(125, $venta->fresh()->total);
        $this->assertSame('completed', $masiva->fresh()->status);
        $reversion = MasiveUpdate::create([
            'user_id' => $venta->user_id, 'employee_id' => $venta->user_id, 'model_name' => 'sale',
            'action' => 'revert', 'status' => 'pending', 'from_filter' => false,
            'parent_masive_update_id' => $masiva->id,
        ]);
        $this->ocupada($venta, function () use ($reversion, $masiva) {
            $this->rechaza(function () use ($reversion, $masiva) {
                MasiveUpdateHelper::process_revert($reversion, $masiva->fresh());
            });
        });
        $this->assertEquals(125, $venta->fresh()->total);
        $this->assertSame('pending', $reversion->fresh()->status);
        MasiveUpdateHelper::process_revert($reversion, $masiva->fresh());
        $this->assertEquals(100, $venta->fresh()->total);
        $this->assertSame('completed', $reversion->fresh()->status);
    }

    /** @test */
    public function emitir_espera_la_baja_sin_commit_externo_y_no_factura_una_venta_que_se_borra()
    {
        $original = DB::getDefaultConnection();
        $configuracion = DB::connection($original)->getConfig();
        $this->assertStringContainsString('testing', $configuracion['database']);
        $this->assertContains($configuracion['host'], ['127.0.0.1', 'localhost']);
        config(['database.connections.fiscal_actual_prueba' => $configuracion]);
        DB::purge('fiscal_actual_prueba');
        $conexion = DB::connection('fiscal_actual_prueba');
        $this->assertSame(0, $conexion->transactionLevel(), 'La conexión de emitir corre en autocommit, fuera del rollback del test.');
        $id = $conexion->table('sales')->insertGetId([
            'user_id' => auth()->id(), 'num' => 1219002, 'total' => 100, 'sub_total' => 100,
            'moneda_id' => 1, 'total_facturado' => 0, 'discount_stock' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $tickets_antes = $conexion->table('afip_tickets')->where('sale_id', $id)->count();
        $this->app->instance(TransporteDeFacturaHelper::class, new class extends TransporteDeFacturaHelper {
            public function emitir($ticket): void { throw new \LogicException('No se puede emitir una factura real en esta prueba.'); }
            public function conciliar($ticket): void { throw new \LogicException('No se puede consultar ARCA real en esta prueba.'); }
        });
        $codigo = <<<'PHP'
$datos = json_decode(trim(fgets(STDIN)), true);
$pdo = new PDO($datos['dsn'], $datos['usuario'], $datos['clave'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->beginTransaction();
$sentencia = $pdo->prepare('UPDATE sales SET deleted_at = NOW() WHERE id = ?');
$sentencia->execute([$datos['venta']]);
fwrite(STDOUT, "BAJA_SIN_COMMIT\n");
fflush(STDOUT);
fgets(STDIN);
usleep(3000000);
$pdo->commit();
fwrite(STDOUT, "COMMIT_REAL\n");
PHP;
        $entrada = json_encode([
            'dsn' => 'mysql:host='.$configuracion['host'].';port='.($configuracion['port'] ?? 3306).';dbname='.$configuracion['database'].';charset=utf8mb4',
            'usuario' => $configuracion['username'], 'clave' => $configuracion['password'], 'venta' => $id,
        ])."\nCOMMIT\n";
        $proceso = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $codigo]);
        $proceso->setInput($entrada);
        $proceso->setTimeout(15);
        try {
            $proceso->start();
            $lista = $proceso->waitUntil(function ($tipo, $salida) {
                return strpos($salida, 'BAJA_SIN_COMMIT') !== false;
            });
            $this->assertTrue($lista, 'El proceso auxiliar debe tener la baja escrita y sin commit.');
            DB::setDefaultConnection('fiscal_actual_prueba');
            $inicio = microtime(true);
            $this->rechaza(function () use ($id) {
                (new MakeAfipTicket())->make_afip_ticket(['sale_id' => $id]);
            }, 'no existe');
            $this->assertGreaterThanOrEqual(1.0, microtime(true) - $inicio, 'La lectura fiscal debe esperar el row lock de la transacción externa.');
            $proceso->wait();
            $this->assertTrue($proceso->isSuccessful(), 'El proceso auxiliar debe confirmar la baja sin errores.');
            $this->assertStringContainsString('COMMIT_REAL', $proceso->getOutput());
            $this->assertNotNull($conexion->table('sales')->where('id', $id)->value('deleted_at'));
            $this->assertSame($tickets_antes, $conexion->table('afip_tickets')->where('sale_id', $id)->count());
        } finally {
            if ($proceso->isRunning()) {
                $proceso->stop(0);
            }
            DB::setDefaultConnection($original);
            // Sólo el id propio persistido por esta prueba; no comparte el rollback de EmpresaTestCase.
            $conexion->table('afip_tickets')->where('sale_id', $id)->delete();
            $conexion->table('sales')->where('id', $id)->delete();
            DB::purge('fiscal_actual_prueba');
        }
    }

    protected function venta()
    {
        return Sale::create([
            'user_id' => auth()->id(), 'num' => 1219001, 'total' => 100, 'sub_total' => 100,
            'moneda_id' => 1, 'total_facturado' => 0, 'discount_stock' => 0,
        ]);
    }

    protected function masiva($venta)
    {
        return MasiveUpdate::create([
            'user_id' => $venta->user_id, 'employee_id' => $venta->user_id, 'model_name' => 'sale',
            'action' => 'update', 'status' => 'pending', 'from_filter' => false,
            'criteria_json' => json_encode([
                'resolved_models_id' => [$venta->id],
                'update_form' => [['type' => 'number', 'key' => 'set_total', 'value' => 125]],
            ]),
        ]);
    }

    protected function rechaza(callable $accion, $texto = 'en curso')
    {
        try {
            $accion();
            $this->fail('La mutación tenía que rechazarse antes de escribir o contactar ARCA.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString($texto, implode(' ', $e->errors()['facturacion']));
        }
    }

    protected function ocupada($venta, callable $accion)
    {
        $original = DB::getDefaultConnection();
        config(['database.connections.fiscal_otra_prueba' => DB::connection($original)->getConfig()]);
        DB::purge('fiscal_otra_prueba');
        DB::setDefaultConnection('fiscal_otra_prueba');
        try {
            return CandadoDeFacturaHelper::ventas([$venta->id], function () use ($original, $accion) {
                DB::setDefaultConnection($original);
                return $accion();
            });
        } finally {
            DB::setDefaultConnection($original);
            DB::purge('fiscal_otra_prueba');
        }
    }
}
