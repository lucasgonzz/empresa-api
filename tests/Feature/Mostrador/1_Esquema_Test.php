<?php

namespace Tests\Feature\Mostrador;

use App\Models\MostradorMemoria;
use App\Models\MostradorReporte;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

/**
 * Misión modulo-ia-mostrador — P1: el esquema.
 *
 * Las dos tablas con sus columnas, los casts de los modelos, y la unique
 * (user_id, tipo, fecha) que vuelve idempotente el upsert de POST hechos.
 */
class Esquema_Test extends MostradorTestCase
{
    /**
     * @group mostrador
     * @test
     */
    public function las_dos_tablas_existen_con_sus_columnas()
    {
        $this->assertTrue(Schema::hasTable('mostrador_reportes'));
        $this->assertTrue(Schema::hasColumns('mostrador_reportes', [
            'id', 'user_id', 'tipo', 'fecha', 'titulo', 'resumen', 'hechos', 'contenido',
            'estado', 'hechos_at', 'generado_at', 'leido_at', 'created_at', 'updated_at',
        ]));

        $this->assertTrue(Schema::hasTable('mostrador_memorias'));
        $this->assertTrue(Schema::hasColumns('mostrador_memorias', [
            'id', 'user_id', 'texto', 'hasta_ai_message_id', 'created_at', 'updated_at',
        ]));
    }

    /**
     * @group mostrador
     * @test
     */
    public function el_estado_nace_en_hechos_y_los_json_se_castean_a_array()
    {
        $reporte = MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'dia',
            'fecha'   => $this->ayer->format('Y-m-d'),
            'hechos'  => ['aplica' => true, 'ventas' => ['cantidad' => 3]],
        ]);

        $reporte->refresh();

        $this->assertSame('hechos', $reporte->estado);
        $this->assertSame(3, $reporte->hechos['ventas']['cantidad']);
        $this->assertNull($reporte->contenido);
        $this->assertSame($this->ayer->format('Y-m-d'), $reporte->fecha->format('Y-m-d'));

        $memoria = MostradorMemoria::create([
            'user_id'             => $this->comercio->id,
            'texto'               => 'Le importan las cobranzas.',
            'hasta_ai_message_id' => 12,
        ]);

        $memoria->refresh();

        $this->assertSame(12, $memoria->hasta_ai_message_id);
    }

    /**
     * @group mostrador
     * @test
     */
    public function no_puede_haber_dos_informes_del_mismo_dueno_tipo_y_fecha()
    {
        MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'compras',
            'fecha'   => $this->ayer->format('Y-m-d'),
        ]);

        $this->expectException(QueryException::class);

        MostradorReporte::create([
            'user_id' => $this->comercio->id,
            'tipo'    => 'compras',
            'fecha'   => $this->ayer->format('Y-m-d'),
        ]);
    }

    /**
     * @group mostrador
     * @test
     */
    public function una_memoria_por_dueno()
    {
        MostradorMemoria::create(['user_id' => $this->comercio->id, 'texto' => 'a']);

        $this->expectException(QueryException::class);

        MostradorMemoria::create(['user_id' => $this->comercio->id, 'texto' => 'b']);
    }
}
