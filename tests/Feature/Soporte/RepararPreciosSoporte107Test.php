<?php

namespace Tests\Feature\Soporte;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RepararPreciosSoporte107Test extends TestCase
{
    use DatabaseTransactions;

    private $respaldo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->respaldo = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cc-soporte107-' . uniqid('', true) . '.json';
        if (!User::find(46000)) {
            User::factory()->create(['id' => 46000]);
        }
        DB::table('importaciones_de_prueba')->updateOrInsert(['uuid' => 'f6585f15-c2cc-431c-9068-5cd7a35744a5'], [
            'user_id' => 46000, 'support_ticket_id' => 107, 'clave' => 'prueba-107', 'archivo' => 'prueba.xlsx',
            'nombre' => 'mis productos.xlsx', 'origen' => 'soporte', 'estado' => 'terminado', 'import_history_id' => 1,
        ]);
        foreach ([34 => ['Llave francesa 10', '313.22'], 46 => ['Disyuntor diferencial 2x40A', null]] as $id => $datos) {
            DB::table('articles')->updateOrInsert(['id' => $id], ['user_id' => 46000, 'name' => $datos[0],
                'price' => $datos[1], 'final_price' => $datos[1], 'stock' => '17.00', 'cost' => '9.123456',
                'percentage_gain' => null, 'descripcion' => 'No tocar notas', 'updated_at' => '2026-11-01 09:00:00']);
            DB::table('articles')->where('id', $id)->update(['deleted_at' => null]);
        }
    }

    protected function tearDown(): void
    {
        foreach ([$this->respaldo, $this->respaldo . '.auditoria.jsonl'] as $ruta) {
            if (is_file($ruta)) {
                unlink($ruta);
            }
        }
        parent::tearDown();
    }

    private function ejecutar(array $opciones = []): int
    {
        return Artisan::call('soporte:reparar-precios-107', array_merge(['--respaldo' => $this->respaldo], $opciones));
    }

    private function filas(): array
    {
        return DB::table('articles')->whereIn('id', [34, 46])->orderBy('id')->get()->map(function ($fila) {
            return (array) $fila;
        })->all();
    }

    public function test_simulacion_no_escribe_datos_ni_archivos()
    {
        $antes = $this->filas();
        $this->assertSame(0, $this->ejecutar());
        $this->assertSame($antes, $this->filas());
        $this->assertFileDoesNotExist($this->respaldo);
        $this->assertFileDoesNotExist($this->respaldo . '.auditoria.jsonl');
        $salida = Artisan::output();
        $this->assertStringContainsString('344.54', $salida);
        $this->assertStringContainsString('54883.47', $salida);
    }

    public function test_aplica_solo_dos_columnas_con_respaldo_y_es_idempotente()
    {
        $antes = $this->filas();
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]));
        $esperadas = $antes;
        $esperadas[0]['price'] = $esperadas[0]['final_price'] = '344.54';
        $esperadas[1]['price'] = $esperadas[1]['final_price'] = '54883.47';
        $this->assertSame($esperadas, $this->filas());
        $backup = file_get_contents($this->respaldo);
        $datos = json_decode($backup, true);
        $this->assertSame('313.22', $datos['articulos'][34]['price']);
        $this->assertNull($datos['articulos'][46]['price']);
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($backup, file_get_contents($this->respaldo));
        $this->assertSame($esperadas, $this->filas());
        $eventos = array_map(function ($linea) { return json_decode($linea, true); },
            file($this->respaldo . '.auditoria.jsonl', FILE_IGNORE_NEW_LINES));
        $this->assertSame(['preparado', 'verificado_en_transaccion'], array_column($eventos, 'evento'));
        $this->assertSame('54883.47', $eventos[1]['despues'][46]['final_price']);
    }

    public function test_rechaza_importe_modificado_sin_escribir_el_otro_articulo()
    {
        DB::table('articles')->where('id', 46)->update(['price' => '100.00']);
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->filas());
        $this->assertFileDoesNotExist($this->respaldo);
    }

    public function test_rechaza_otro_negocio_y_nombre_distinto()
    {
        DB::table('articles')->where('id', 34)->update(['user_id' => User::factory()->create()->id]);
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->filas());
        DB::table('articles')->where('id', 34)->update(['user_id' => 46000, 'name' => 'Otro artículo']);
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertFileDoesNotExist($this->respaldo);
    }

    public function test_revierte_solo_importes_y_repetir_no_modifica_el_respaldo()
    {
        $antes = $this->filas();
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]));
        $respaldo = file_get_contents($this->respaldo);
        $this->assertSame(0, $this->ejecutar(['--revertir' => true]));
        $this->assertSame('344.54', $this->filas()[0]['price']);
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--revertir' => true]));
        $this->assertSame($antes, $this->filas());
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true, '--revertir' => true]));
        $this->assertSame($respaldo, file_get_contents($this->respaldo));
    }

    public function test_reversion_no_pisa_cambio_posterior()
    {
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]));
        DB::table('articles')->where('id', 34)->update(['final_price' => '400.00']);
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true]));
        $this->assertSame($antes, $this->filas());
    }

    public function test_respaldo_de_otra_base_o_invalido_bloquea_la_escritura()
    {
        $this->assertSame(0, $this->ejecutar(['--aplicar' => true]));
        $datos = json_decode(file_get_contents($this->respaldo), true);
        $datos['base'] = 'otra_base';
        file_put_contents($this->respaldo, json_encode($datos));
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true]));
        $this->assertSame($antes, $this->filas());
    }

    public function test_reversion_sin_respaldo_no_escribe()
    {
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true, '--revertir' => true]));
        $this->assertSame($antes, $this->filas());
    }

    public function test_sin_corrida_original_del_negocio_no_escribe()
    {
        DB::table('importaciones_de_prueba')->where('uuid', 'f6585f15-c2cc-431c-9068-5cd7a35744a5')
            ->update(['support_ticket_id' => 108]);
        $antes = $this->filas();
        $this->assertSame(1, $this->ejecutar(['--aplicar' => true]));
        $this->assertSame($antes, $this->filas());
        $this->assertFileDoesNotExist($this->respaldo);
    }
}
