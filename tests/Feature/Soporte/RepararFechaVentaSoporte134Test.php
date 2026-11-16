<?php

namespace Tests\Feature\Soporte;

use App\Models\User;
use App\Models\Sale;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepararFechaVentaSoporte134Test extends TestCase
{
    use DatabaseTransactions;
    private $ruta;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.url' => 'https://api-3dtisk.comerciocity.com/public']);
        $this->ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-fecha134-'.uniqid('', true).'.json';
        if (!User::find(1200)) {
            User::factory()->create(['id' => 1200]);
        }
        DB::table('users')->where('id', 1200)->update(['owner_id' => null, 'deleted_at' => null]);
        DB::table('sales')->updateOrInsert(['id' => 13691], ['user_id' => 1200, 'num' => 13684,
            'client_id' => null, 'total' => '61970.00', 'total_facturado' => '61970.00',
            'created_at' => '2026-11-15 10:27:13', 'updated_at' => '2026-11-16 10:27:16',
            'deleted_at' => null, 'actualizandose_por_id' => null,
            'consolidacion_facturacion_id' => null, 'is_consolidacion_facturacion' => 0]);
        DB::table('cajas')->updateOrInsert(['id' => 5], ['user_id' => 1200, 'num' => 5]);
        foreach ([31801 => 10, 31802 => 41, 31803 => 57] as $id => $articulo) {
            DB::table('stock_movements')->updateOrInsert(['id' => $id], ['sale_id' => 13691, 'user_id' => 1200,
                'article_id' => $articulo, 'amount' => -1, 'created_at' => '2026-11-16 10:27:13', 'updated_at' => '2026-11-16 10:27:13']);
        }
        DB::table('movimiento_cajas')->updateOrInsert(['id' => 13236], ['sale_id' => 13691, 'caja_id' => 5,
            'apertura_caja_id' => 1, 'ingreso' => '61970.00', 'egreso' => null, 'current_acount_id' => null,
            'created_at' => '2026-11-16 10:27:13', 'updated_at' => '2026-11-16 10:27:13']);
        DB::table('afip_tickets')->updateOrInsert(['id' => 8424], ['sale_id' => 13691,
            'sale_nota_credito_id' => null, 'nota_credito_id' => null, 'afip_fecha_emision' => '2026-11-16',
            'created_at' => '2026-11-16 10:27:14', 'importe_total' => '61970', 'resultado' => 'A',
            'cae' => 'CAE-FALSO-DE-TEST', 'deleted_at' => null]);
    }

    protected function tearDown(): void
    {
        foreach ([$this->ruta, $this->ruta.'.auditoria.jsonl'] as $ruta) {
            if (is_file($ruta)) { unlink($ruta); }
        }
        parent::tearDown();
    }

    private function ejecutar(array $opciones = []): int
    {
        return Artisan::call('soporte:reparar-fecha-venta-134', array_merge(['--respaldo' => $this->ruta], $opciones));
    }

    private function estado(): array
    {
        $resultado = [];
        foreach (['sales' => 'id', 'stock_movements' => 'sale_id', 'movimiento_cajas' => 'sale_id', 'afip_tickets' => 'sale_id'] as $tabla => $columna) {
            $resultado[$tabla] = DB::table($tabla)->where($columna, 13691)->orderBy('id')->get()->map(function ($fila) { return (array) $fila; })->all();
        }
        return $resultado;
    }

    public function test_simulacion_solo_lectura_muestra_alcance_y_no_crea_respaldo()
    {
        $antes = $this->estado();
        $resultado = $this->ejecutar();
        $salida = Artisan::output();
        $this->assertSame(0, $resultado, $salida);
        $this->assertSame($antes, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
        $this->assertStringContainsString('13684', $salida);
    }

    public function test_aplica_solo_created_at_y_conserva_fiscal_caja_stock_y_updated_at()
    {
        $antes = $this->estado();
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]), Artisan::output());
        $esperado = $antes;
        $esperado['sales'][0]['created_at'] = '2026-11-16 10:27:13';
        $this->assertSame($esperado, $this->estado());
        $this->assertFalse(Sale::where('id', 13691)->enRangoDeFechas('2026-11-15')->exists());
        $this->assertTrue(Sale::where('id', 13691)->enRangoDeFechas('2026-11-16')->exists());
        $respaldo = file_get_contents($this->ruta);
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]), Artisan::output());
        $this->assertSame($respaldo, file_get_contents($this->ruta));
        $this->assertSame($esperado, $this->estado());
        $datos = json_decode($respaldo, true);
        $this->assertSame($antes['afip_tickets'], $datos['estado']['comprobantes_fiscales']);
    }

    public function test_revierte_solo_fecha_con_respaldo_y_es_idempotente()
    {
        $antes = $this->estado();
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]), Artisan::output());
        $this->assertSame(0, $this->ejecutar(['--revertir' => true]), Artisan::output());
        $this->assertSame('2026-11-16 10:27:13', $this->estado()['sales'][0]['created_at']);
        $this->assertSame(0, $this->ejecutar(['--revertir' => true, '--aplicar' => true]), Artisan::output());
        $this->assertSame($antes, $this->estado());
        $this->assertSame(0, $this->ejecutar(['--revertir' => true, '--aplicar' => true]), Artisan::output());
    }

    public function test_rechaza_otro_host_otro_dueno_y_cambio_de_estado_sin_escribir()
    {
        $antes = $this->estado();
        config(['app.url' => 'https://api-otro.comerciocity.com/public']);
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->estado());
        config(['app.url' => 'https://api-3dtisk.comerciocity.com/public']);
        foreach (['user_id' => 500, 'num' => 13691, 'total' => '61971.00', 'updated_at' => '2026-11-16 10:28:00', 'created_at' => '2026-11-14 10:27:13'] as $campo => $valor) {
            DB::table('sales')->where('id', 13691)->update([$campo => $valor]);
            $modificado = $this->estado();
            $this->assertSame(1, $this->ejecutar(['--aplicar' => true]), $campo);
            $this->assertSame($modificado, $this->estado());
            DB::table('sales')->where('id', 13691)->update([$campo => $antes['sales'][0][$campo]]);
        }
        $this->assertFileDoesNotExist($this->ruta);
    }

    public function test_factura_incierta_o_fecha_fiscal_distinta_bloquean_sin_reemitir()
    {
        foreach (['cae' => null, 'resultado' => 'R', 'afip_fecha_emision' => '2026-11-15'] as $campo => $valor) {
            $original = DB::table('afip_tickets')->where('id', 8424)->value($campo);
            DB::table('afip_tickets')->where('id', 8424)->update([$campo => $valor]);
            $antes = $this->estado();
            $this->assertSame(1, $this->ejecutar(['--aplicar' => true]), $campo);
            $this->assertSame($antes, $this->estado());
            DB::table('afip_tickets')->where('id', 8424)->update([$campo => $original]);
        }
    }

    public function test_movimientos_distintos_no_se_reparan_y_falta_de_respaldo_bloquea_reversion()
    {
        $this->assertSame(1, $this->ejecutar(['--revertir' => true, '--aplicar' => true]));
        DB::table('movimiento_cajas')->where('id', 13236)->update(['created_at' => '2026-11-15 10:27:13']);
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
    }

    public function test_respaldo_alterado_o_cambio_posterior_bloquean_reversion()
    {
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]), Artisan::output());
        DB::table('stock_movements')->where('id', 31801)->update(['amount' => -2]);
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--revertir' => true, '--aplicar' => true]));
        $this->assertSame($antes, $this->estado());
        $datos = json_decode(file_get_contents($this->ruta), true);
        $datos['base'] = 'otra_base';
        file_put_contents($this->ruta, json_encode($datos));
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->estado());
    }

    public function test_cantidad_de_stock_modificada_antes_del_primer_respaldo_no_se_acepta()
    {
        DB::table('stock_movements')->where('id', 31801)->update(['amount' => -2]);
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
    }
}
