<?php

namespace Tests\Feature\Soporte;

use App\Models\AfipTicket;
use App\Models\AfipTipoComprobante;
use App\Models\Sale;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\EmpresaTestCase;

class RepararTotalFacturadoTest extends EmpresaTestCase
{
    private $ruta;
    private $venta;
    private $factura;
    private $salida;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.USER_ID' => auth()->id()]);
        $this->ruta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cc-total145-'.uniqid('', true).'.json';
        $this->venta = Sale::create(['user_id' => auth()->id(), 'num' => 51362, 'total' => 11500,
            'sub_total' => 11500, 'total_a_facturar' => 11500, 'total_facturado' => 23000, 'moneda_id' => 1,
            'discount_stock' => 0, 'is_consolidacion_facturacion' => 0]);
        $tipo = AfipTipoComprobante::firstOrCreate(['codigo' => 1], ['name' => 'Factura A']);
        $this->factura = AfipTicket::create(['sale_id' => $this->venta->id, 'afip_tipo_comprobante_id' => $tipo->id,
            'cbte_tipo' => 1, 'cbte_numero' => (string) (15770000 + $this->venta->id), 'punto_venta' => 4,
            'cuit_negocio' => '20000000000', 'importe_total' => 11500, 'imp_total_enviado' => 11500,
            'total_a_facturar' => 11500, 'resultado' => 'A', 'cae' => '99999999999999', 'moneda_id' => 'PES']);
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
        $resultado = Artisan::call('soporte:reparar-total-facturado', array_merge([
            '--negocio' => (string) auth()->id(), '--ventas' => (string) $this->venta->id, '--respaldo' => $this->ruta,
        ], $opciones));
        $this->salida = Artisan::output();
        return $resultado;
    }

    private function simular(): array
    {
        $this->assertSame(0, $this->ejecutar(), $this->salida);
        return json_decode(explode("\n", trim($this->salida))[0], true, 512, JSON_THROW_ON_ERROR);
    }

    private function aplicar(): string
    {
        $huella = $this->simular()['huella'];
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--huella' => $huella]), $this->salida);
        return $huella;
    }

    private function estado(): array
    {
        return [
            'venta' => (array) DB::table('sales')->where('id', $this->venta->id)->first(),
            'fiscal' => (array) DB::table('afip_tickets')->where('id', $this->factura->id)->first(),
            'caja' => DB::table('movimiento_cajas')->where('sale_id', $this->venta->id)->get()->toArray(),
            'stock' => DB::table('stock_movements')->where('sale_id', $this->venta->id)->get()->toArray(),
            'cuenta' => DB::table('current_acounts')->where('sale_id', $this->venta->id)->get()->toArray(),
        ];
    }

    public function test_simulacion_solo_lectura_muestra_11500_sin_cae_cuit_ni_archivos()
    {
        $antes = $this->estado();
        $salida = $this->simular();
        $this->assertSame('11500.00', $salida['ventas'][0]['destino']);
        $this->assertSame('23000.00', $salida['ventas'][0]['anterior']);
        $this->assertSame(51362, $salida['ventas'][0]['numero_visible']);
        $this->assertSame($antes, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
        $this->assertFileDoesNotExist($this->ruta.'.auditoria.jsonl');
        $this->assertStringNotContainsString('99999999999999', $this->salida);
        $this->assertStringNotContainsString('20000000000', $this->salida);
    }

    public function test_aplica_solo_acumulado_con_respaldo_verificable_y_repeticion_idempotente()
    {
        $antes = $this->estado();
        $huella = $this->aplicar();
        $esperado = $antes;
        $esperado['venta']['total_facturado'] = '11500.00';
        $this->assertSame($esperado, $this->estado());
        $respaldo = file_get_contents($this->ruta);
        $this->assertStringContainsString('confirmado_y_releido', file_get_contents($this->ruta.'.auditoria.jsonl'));
        $datos = json_decode($respaldo, true);
        $sha = $datos['sha256'];
        unset($datos['sha256']);
        $this->assertSame($sha, hash('sha256', json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)));
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--huella' => $huella]), $this->salida);
        $this->assertSame($respaldo, file_get_contents($this->ruta));
        $this->assertSame($esperado, $this->estado());
    }

    public function test_simula_y_revierte_solo_acumulado_y_repetir_no_pisa_otros_campos()
    {
        $antes = $this->estado();
        $huella = $this->aplicar();
        $reparado = $this->estado();
        $this->assertSame(0, $this->ejecutar(['--revertir' => true]), $this->salida);
        $this->assertSame($reparado, $this->estado());
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]), $this->salida);
        $this->assertSame($antes, $this->estado());
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]), $this->salida);
        $this->assertSame($antes, $this->estado());
    }

    public function test_aplicar_sin_huella_o_con_evidencia_que_cambio_no_escribe()
    {
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertStringContainsString('Primero simulá', $this->salida);
        $huella = $this->simular()['huella'];
        DB::table('sales')->where('id', $this->venta->id)->update(['total_facturado' => 34500]);
        $modificado = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--huella' => $huella]));
        $this->assertSame($modificado, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
        $this->assertSame($antes['fiscal'], $modificado['fiscal']);
    }

    public function test_reversion_rechaza_cambios_posteriores_en_venta_o_en_comprobante()
    {
        $huella = $this->aplicar();
        foreach ([['sales', 'total', '11501.00'], ['afip_tickets', 'consultado', 1]] as $cambio) {
            list($tabla, $columna, $valor) = $cambio;
            $id = $tabla === 'sales' ? $this->venta->id : $this->factura->id;
            $anterior = DB::table($tabla)->where('id', $id)->value($columna);
            DB::table($tabla)->where('id', $id)->update([$columna => $valor]);
            $estado = $this->estado();
            $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]));
            $this->assertStringContainsString('después del respaldo', $this->salida);
            $this->assertSame($estado, $this->estado());
            DB::table($tabla)->where('id', $id)->update([$columna => $anterior]);
        }
    }

    public function test_no_acepta_otro_negocio_ni_seleccion_implicita_o_invalida()
    {
        $antes = $this->estado();
        foreach ([['--negocio' => '0'], ['--negocio' => (string) (auth()->id() + 100000)],
            ['--ventas' => ''], ['--ventas' => '51362x'], ['--ventas' => '1,0'], ['--negocio' => '1e2']] as $opciones) {
            $this->assertSame(1, $this->ejecutar($opciones), json_encode($opciones));
            $this->assertSame($antes, $this->estado());
        }
        $otro = \App\Models\User::factory()->create(['owner_id' => null]);
        DB::table('sales')->where('id', $this->venta->id)->update(['user_id' => $otro->id]);
        $this->assertSame(1, $this->ejecutar());
        $this->assertStringContainsString('no pertenece', $this->salida);
    }

    public function test_respaldo_alterado_o_otra_instalacion_bloquea_la_reversion()
    {
        $huella = $this->aplicar();
        $original = file_get_contents($this->ruta);
        $datos = json_decode($original, true);
        $datos['estados'][0]['anterior'] = '1.00';
        file_put_contents($this->ruta, json_encode($datos));
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]));
        $this->assertSame($antes, $this->estado());
        file_put_contents($this->ruta, $original);
        config(['app.url' => 'https://otra-instalacion.invalid']);
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]));
        $this->assertSame($antes, $this->estado());
    }

    public function test_no_revierte_sin_respaldo_y_no_guarda_respaldo_publico()
    {
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--revertir' => true]));
        $this->assertStringContainsString('respaldo original', $this->salida);
        $this->assertSame(1, $this->ejecutar(['--respaldo' => public_path('reparacion-145.json')]));
        $this->assertStringContainsString('carpeta pública', $this->salida);
        $this->assertSame($antes, $this->estado());
    }

    public function test_nueva_factura_o_nota_impide_revertir_aunque_el_neto_no_cambie()
    {
        $huella = $this->aplicar();
        $copia = $this->factura->replicate();
        $copia->cbte_numero = (string) ((int) $this->factura->cbte_numero + 1);
        $copia->cae = '99999999999998';
        $copia->importe_total = 1;
        $copia->imp_total_enviado = 1;
        $copia->save();
        $nota = $this->factura->replicate();
        $nota->sale_id = null;
        $nota->sale_nota_credito_id = $this->venta->id;
        $nota->sale_afip_ticket_id = $copia->id;
        $nota->cbte_tipo = 3;
        $nota->cbte_numero = (string) ((int) $this->factura->cbte_numero + 2);
        $nota->cae = '99999999999997';
        $nota->importe_total = 1;
        $nota->imp_total_enviado = 1;
        $nota->save();
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true, '--huella' => $huella]));
        $this->assertStringContainsString('después del respaldo', $this->salida);
        $this->assertSame($antes, $this->estado());
        $this->assertSame('11500.00', $this->venta->fresh()->total_facturado);
    }

    public function test_aplicar_sin_respaldo_valido_no_escribe_y_falla_la_seleccion_completa()
    {
        $huella = $this->simular()['huella'];
        $antes = $this->estado();
        $this->assertSame(1, $this->ejecutar(['--respaldo' => '', '--aplicar' => true, '--huella' => $huella]));
        $this->assertStringContainsString('ruta absoluta', $this->salida);
        $this->assertSame($antes, $this->estado());
        $this->assertSame(1, $this->ejecutar(['--respaldo' => dirname($this->ruta).DIRECTORY_SEPARATOR.'carpeta-inexistente-'.uniqid().DIRECTORY_SEPARATOR.'145.json',
            '--aplicar' => true, '--huella' => $huella]));
        $this->assertSame($antes, $this->estado());
        $this->assertFileDoesNotExist($this->ruta);
    }

    public function test_una_diferencia_ambigua_bloquea_toda_la_seleccion()
    {
        $otra = Sale::create(['user_id' => auth()->id(), 'num' => 51363, 'total' => 11500,
            'total_facturado' => 23000, 'moneda_id' => 1, 'discount_stock' => 0]);
        $duplicada = $this->factura->replicate();
        $duplicada->sale_id = $otra->id;
        $duplicada->save();
        $antes = $this->estado();
        $opciones = ['--ventas' => $this->venta->id.','.$otra->id];
        $this->assertSame(1, $this->ejecutar($opciones), $this->salida);
        $this->assertStringContainsString('revisión', $this->salida);
        $this->assertSame($antes, $this->estado());
        $this->assertSame('23000.00', $otra->fresh()->total_facturado);
        $this->assertFileDoesNotExist($this->ruta);
    }

    public function test_sin_comprobantes_no_convierte_un_acumulado_historico_a_cero()
    {
        $this->factura->forceDelete();
        $this->assertSame(1, $this->ejecutar(), $this->salida);
        $this->assertStringContainsString('documentación histórica', $this->salida);
        $this->assertSame('23000.00', $this->venta->fresh()->total_facturado);
        $this->assertFileDoesNotExist($this->ruta);
    }
}
