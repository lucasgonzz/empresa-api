<?php

namespace Tests\Feature\ImportacionDeSoporte;

use App\Http\Controllers\Helpers\import\client\AiClientAnalyzer;
use App\Jobs\AvanzarImportacionDeSoporte;
use App\Models\AiAvisoDelAsistente;
use App\Models\Client;
use App\Models\CurrentAcount;
use App\Models\ImportacionDeSoporte;
use App\Models\ImportacionDeSoporteCambio;
use App\Services\Importacion\ImportacionDeSoporteService;
use App\Services\Importacion\PlanDeContactosDeSoporte;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/**
 * La revisión independiente de la parte B (misión importaciones-de-martin, 11/11/2026): lo que no se puede predecir
 * no se importa, cada tanda se controla contra lo previsto, los montos y signos de cada movimiento, las tandas de 100
 * filas, los relanzamientos con tope y el reintento después de una corrida muerta.
 */
class AmbiguedadesTandasYControlTest extends ImportacionDeSoporteTestCase
{
    /** El caso del revisor: el importador le hubiera hecho al Juan existente una nota de crédito de 700. */
    public function test_el_mismo_nombre_en_dos_filas_queda_para_corregir_sin_codigo_ni_cambios(): void
    {
        $juan = $this->cliente('Juan Dup Rev', 9601, '0', 1000);
        $archivo = $this->planilla([['Numero', 'Nombre', 'Telefono', 'Saldo'], [9699, 'Juan Dup Rev', '1', 200], [null, 'Juan Dup Rev', '2', 300]]);
        $corrida = $this->analizada('client', $archivo, ['numero', 'nombre', 'telefono', 'saldo_actual'], 100);

        $this->assertSame('requiere_correccion', $corrida->estado);
        $this->assertSame('filas_ambiguas', $corrida->motivo);
        $this->assertNull($corrida->codigo);
        $this->assertSame([
            ['fila' => 2, 'nombre' => 'Juan Dup Rev', 'motivo' => 'nombre_repetido_en_el_archivo'],
            ['fila' => 3, 'nombre' => 'Juan Dup Rev', 'motivo' => 'nombre_repetido_en_el_archivo'],
        ], $corrida->resumen['filas_ambiguas']);
        $this->assertStringContainsString('fila 2 (Juan Dup Rev): el nombre está repetido en el archivo', $corrida->error);
        $this->assertFalse(AiAvisoDelAsistente::where('owner_id', $this->dueno->id)->where('support_ticket_id', 100)->exists());
        $estado = $this->estado($corrida->uuid, 100)->assertJsonPath('corrida.estado', 'requiere_correccion')
            ->assertJsonPath('corrida.motivo', 'filas_ambiguas')->assertJsonPath('corrida.codigo_requerido', false)
            ->assertJsonPath('corrida.revertible', false)->json('corrida');
        $this->assertSame(2, $estado['resumen']['filas_ambiguas_total']);
        $this->postJson(self::BASE . $corrida->uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 100,
            'codigo' => 'ABCDEF12'], $this->cabeceras())->assertStatus(409)->assertJsonPath('motivo', 'codigo_vencido');
        $this->getJson('/api/admin-sync/ai-excel-import/prueba-pendientes?automatizar_soporte=1&user_id=' . $this->dueno->id
            . '&support_ticket_id=100', $this->cabeceras())->assertOk()->assertJsonPath('importaciones_de_contactos', []);

        $this->assertEquals(1000, $this->saldo('client', $juan->id));
        $this->assertSame('0', $juan->fresh()->phone);
        $this->assertSame(1, Client::where('user_id', $this->dueno->id)->where('name', 'Juan Dup Rev')->count());

        // Reenviar el mismo archivo después de una corrida que pide corregir crea otra corrida.
        $this->iniciar('client', $archivo, 100)->assertStatus(202)->assertJsonPath('corrida_existente', false);
        $this->assertSame(2, ImportacionDeSoporte::where('user_id', $this->dueno->id)->where('support_ticket_id', 100)->count());
    }

    public function test_acentos_mayusculas_y_varios_contactos_cuentan_como_la_base(): void
    {
        $this->cliente('Ana Gomez', 9701, '1');
        $this->cliente('Ana Gómez', 9702, '1');
        $nandu = $this->cliente('Ñandú Hermanos', 9703, '1');

        $corrida = $this->analizada('client', $this->planilla([['Nombre', 'Telefono'], ['José Pérez', '1'], ['jose  perez', '2'],
            ['ANA GOMEZ', '3']]), ['nombre', 'telefono'], 101);
        $this->assertSame('requiere_correccion', $corrida->estado);
        $this->assertSame(['nombre_repetido_en_el_archivo', 'nombre_repetido_en_el_archivo', 'varios_contactos_coinciden'],
            array_column($corrida->resumen['filas_ambiguas'], 'motivo'));

        // «nandu hermanos» es el mismo contacto que «Ñandú Hermanos» para la base: se actualiza, no se crea otro.
        $corrida = $this->importada('client', $this->planilla([['Nombre', 'Telefono'], ['nandu hermanos', '9']]), ['nombre', 'telefono'], 102);
        $this->assertSame(0, $corrida->resumen['crear']);
        $this->assertSame(1, $corrida->resumen['actualizar']);
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame('9', $nandu->fresh()->phone);
        $this->assertSame(1, Client::where('user_id', $this->dueno->id)->where('name', 'like', '%andu Hermanos')->count());
    }

    public function test_el_007_es_el_7_y_los_numeros_raros_o_los_renombres_cruzados_son_ambiguos(): void
    {
        $siete = $this->cliente('Siete Soporte', 7, '1');
        $this->cliente('Ocho Soporte', 8, '1');

        $columnas = ['numero', 'nombre'];
        $this->assertSame('numero_no_valido', $this->analizada('client', $this->planilla([['Numero', 'Nombre'], ['A12', 'Equis']]), $columnas, 103)
            ->resumen['filas_ambiguas'][0]['motivo']);
        $this->assertSame('renombra_con_el_nombre_de_otro', $this->analizada('client', $this->planilla([['Numero', 'Nombre'], ['007', 'Ocho Soporte']]), $columnas, 104)
            ->resumen['filas_ambiguas'][0]['motivo']);
        // Una fila renombra al 7 y otra lo busca por su nombre viejo: el mismo contacto en dos filas.
        $this->assertSame(['contacto_en_varias_filas', 'contacto_en_varias_filas'], array_column(
            $this->analizada('client', $this->planilla([['Numero', 'Nombre'], ['7', 'Siete Renombrado'], [null, 'Siete Soporte']]), $columnas, 105)
                ->resumen['filas_ambiguas'], 'motivo'));
        $this->assertSame('ya_existe_con_otro_numero', $this->analizada('client', $this->planilla([['Numero', 'Nombre'], ['77', 'Ocho Soporte']]), $columnas, 106)
            ->resumen['filas_ambiguas'][0]['motivo']);

        $corrida = $this->importada('client', $this->planilla([['Numero', 'Nombre'], ['007', 'Siete Renombrado']]), $columnas, 107);
        $this->assertSame(['crear' => 0, 'actualizar' => 1], ['crear' => $corrida->resumen['crear'], 'actualizar' => $corrida->resumen['actualizar']]);
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame('Siete Renombrado', $siete->fresh()->name);
        $this->revertir($corrida->uuid, 107)->assertJsonPath('corrida.estado', 'revertida');
        $this->assertSame('Siete Soporte', $siete->fresh()->name);
    }

    public function test_saldo_negativo_nota_de_debito_y_su_contraasiento_con_montos_y_signos_exactos(): void
    {
        $deudor = $this->cliente('Deudor Soporte', 9801, '1', 100);
        $archivo = $this->planilla([['Numero', 'Nombre', 'Saldo'], [9801, 'Deudor Soporte', 400.5], [9802, 'A Favor Soporte', -250.25]]);
        $corrida = $this->analizada('client', $archivo, ['numero', 'nombre', 'saldo_actual'], 108);
        $saldos = collect($corrida->resumen['saldos'])->keyBy('nombre');
        $this->assertEquals(['accion' => 'nota_de_debito', 'saldo_actual' => 100.0, 'saldo_resultante' => 400.5, 'monto' => 300.5],
            array_intersect_key($saldos['Deudor Soporte'], array_flip(['accion', 'saldo_actual', 'saldo_resultante', 'monto'])));
        $this->assertEquals(['accion' => 'saldo_inicial', 'saldo_resultante' => -250.25, 'monto' => 250.25],
            array_intersect_key($saldos['A Favor Soporte'], array_flip(['accion', 'saldo_resultante', 'monto'])));

        $corrida = $this->confirmada_e_importada($corrida, 108);
        $this->assertSame('terminada', $corrida->estado);
        $a_favor = Client::where('user_id', $this->dueno->id)->where('name', 'A Favor Soporte')->firstOrFail();
        $this->assertEquals(-250.25, $this->saldo('client', $a_favor->id));
        $inicial = CurrentAcount::where('client_id', $a_favor->id)->firstOrFail();
        $this->assertSame(['Saldo inicial', null, '250.25'], [$inicial->detalle, $inicial->debe, (string) $inicial->haber]);
        $debito = CurrentAcount::where('client_id', $deudor->id)->where('detalle', 'Nota de debito')->firstOrFail();
        $this->assertEquals(300.5, $debito->debe);
        $this->assertEquals(400.5, $this->saldo('client', $deudor->id));
        $this->assertSame(['saldo_inicial' => 1, 'nota_de_credito' => 0, 'nota_de_debito' => 1], $corrida->resultado['movimientos']);

        $this->debito('client', $deudor->id, 49.5);
        $this->revertir($corrida->uuid, 108)->assertJsonPath('corrida.resultado.reversion.contraasientos', 1)
            ->assertJsonPath('corrida.resultado.reversion.creados_borrados', 1);
        $contra = CurrentAcount::where('client_id', $deudor->id)->where('description', ImportacionDeSoporteService::OBSERVACION_DE_LA_REVERSION)->firstOrFail();
        $this->assertSame('nota_credito', $contra->status);
        $this->assertEquals(300.5, $contra->haber);
        // 100 de antes + 49,50 de la venta posterior: la nota de débito importada quedó anulada.
        $this->assertEquals(149.5, $this->saldo('client', $deudor->id));
        $this->assertSoftDeleted('clients', ['id' => $a_favor->id]);
        $this->assertFalse(CurrentAcount::where('client_id', $a_favor->id)->exists());
    }

    public function test_la_nota_de_credito_que_salda_deudas_viejas_se_revierte_y_las_deudas_vuelven(): void
    {
        $viejo = $this->cliente('Viejo Deudor Soporte', 9901, '1', 1000);
        $this->debito('client', $viejo->id, 500);
        $archivo = $this->planilla([['Numero', 'Nombre', 'Saldo'], [9901, 'Viejo Deudor Soporte', 200]]);
        $corrida = $this->analizada('client', $archivo, ['numero', 'nombre', 'saldo_actual'], 109);
        $this->assertEquals(1300, $corrida->resumen['saldos'][0]['monto']);
        $this->assertSame('nota_de_credito', $corrida->resumen['saldos'][0]['accion']);

        $corrida = $this->confirmada_e_importada($corrida, 109);
        $this->assertSame('terminada', $corrida->estado);
        $nota = CurrentAcount::where('client_id', $viejo->id)->where('status', 'nota_credito')->firstOrFail();
        $this->assertEquals(1300, $nota->haber);
        $this->assertEquals(200, $this->saldo('client', $viejo->id));
        $deudas = CurrentAcount::where('client_id', $viejo->id)->whereNotNull('debe')->orderBy('id')->get();
        $this->assertSame('pagado', $deudas[0]->fresh()->status);
        $this->assertTrue(DB::table('pagado_por')->whereIn('debe_id', $deudas->pluck('id'))->exists());

        $this->revertir($corrida->uuid, 109)->assertJsonPath('corrida.resultado.reversion.movimientos_borrados', 1);
        $this->assertFalse(CurrentAcount::whereKey($nota->id)->exists());
        $this->assertEquals(1500, $this->saldo('client', $viejo->id));
        foreach ($deudas as $deuda) {
            $this->assertSame('sin_pagar', $deuda->fresh()->status);
        }
        $this->assertFalse(DB::table('pagado_por')->whereIn('debe_id', $deudas->pluck('id'))->exists());
    }

    public function test_un_saldo_de_cero_o_de_menos_de_un_centavo_no_crea_saldo_inicial(): void
    {
        $archivo = $this->planilla([['Nombre', 'Saldo'], ['Cero Soporte', 0], ['Casi Cero Soporte', 0.004], ['Con Saldo Soporte', 10]]);
        $corrida = $this->importada('client', $archivo, ['nombre', 'saldo_actual'], 110);
        $this->assertSame(1, $corrida->resumen['saldos_total']);
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame(['saldo_inicial' => 1, 'nota_de_credito' => 0, 'nota_de_debito' => 0], $corrida->resultado['movimientos']);
        foreach (['Cero Soporte', 'Casi Cero Soporte'] as $nombre) {
            $cliente = Client::where('user_id', $this->dueno->id)->where('name', $nombre)->firstOrFail();
            $this->assertFalse(CurrentAcount::where('client_id', $cliente->id)->exists(), $nombre);
        }
    }

    public function test_si_la_tanda_no_hace_lo_previsto_se_deshace_entera_y_queda_el_motivo(): void
    {
        $ana = $this->cliente('Ana Control', 9951, '1', 1000);
        $archivo = $this->planilla([['Numero', 'Nombre', 'Saldo'], [9951, 'Ana Control', 600], [null, 'Nueva Control', 50]]);
        $corrida = $this->analizada('client', $archivo, ['numero', 'nombre', 'saldo_actual'], 111);
        $this->postJson(self::BASE . $corrida->uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 111,
            'codigo' => $corrida->codigo], $this->cabeceras())->assertStatus(202);

        // Lo previsto dice una nota de crédito de 300 (el importador va a hacer una de 400).
        $previsto = PlanDeContactosDeSoporte::armar($corrida->fresh())['previsto'];
        $previsto[0]['movimientos'] = [['nota_de_credito', 300.0]];
        $corrida->forceFill(['previsto' => $previsto, 'tandas_total' => 1])->save();
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());

        $corrida->refresh();
        $this->assertSame('error', $corrida->estado);
        $this->assertSame('no_coincide_con_el_resumen', $corrida->motivo);
        $this->assertFalse($corrida->revertible());
        $this->assertEquals(1000, $this->saldo('client', $ana->id));
        $this->assertFalse(CurrentAcount::where('client_id', $ana->id)->where('status', 'nota_credito')->exists());
        $this->assertDatabaseMissing('clients', ['user_id' => $this->dueno->id, 'name' => 'Nueva Control', 'deleted_at' => null]);
        $this->assertSame(0, ImportacionDeSoporteCambio::where('importacion_de_soporte_id', $corrida->id)->count());
        $this->assertStringContainsString('no cargué nada', $corrida->error);
    }

    public function test_tandas_de_cien_filas_y_una_tanda_que_falla_deja_revertir_lo_anterior(): void
    {
        $filas = [['Nombre', 'Saldo']];
        for ($i = 1; $i <= 230; $i++) {
            $filas[] = ['Tanda Soporte ' . $i, $i];
        }
        $archivo = $this->planilla($filas);
        $corrida = $this->importada('client', $archivo, ['nombre', 'saldo_actual'], 112);
        $this->assertSame('terminada', $corrida->estado);
        $this->assertSame([3, 3], [(int) $corrida->tandas_total, (int) $corrida->tandas_hechas]);
        $this->assertSame(230, $corrida->resultado['creados']);
        $this->assertSame(['1' => 100, '2' => 100, '3' => 30], ImportacionDeSoporteCambio::where('importacion_de_soporte_id', $corrida->id)
            ->where('tipo', 'creado')->select('tanda', DB::raw('count(*) as n'))->groupBy('tanda')->orderBy('tanda')->pluck('n', 'tanda')
            ->map(function ($n) {
                return (int) $n;
            })->all());
        $ultimo = Client::where('user_id', $this->dueno->id)->where('name', 'Tanda Soporte 230')->firstOrFail();
        $this->assertEquals(230, $this->saldo('client', $ultimo->id));
        $this->revertir($corrida->uuid, 112)->assertJsonPath('corrida.tandas.revertidas', 3)
            ->assertJsonPath('corrida.resultado.reversion.creados_borrados', 230);
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('name', 'like', 'Tanda Soporte %')->count());

        // Una segunda planilla cuya tanda 2 no hace lo previsto: la 1 queda y se puede deshacer.
        $filas[0] = ['Nombre', 'Saldo'];
        foreach ($filas as $i => $fila) {
            if ($i > 0) {
                $filas[$i][0] = 'Tanda B Soporte ' . $i;
            }
        }
        $corrida = $this->analizada('client', $this->planilla($filas), ['nombre', 'saldo_actual'], 113);
        $this->postJson(self::BASE . $corrida->uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 113,
            'codigo' => $corrida->codigo], $this->cabeceras())->assertStatus(202);
        $previsto = PlanDeContactosDeSoporte::armar($corrida->fresh())['previsto'];
        $previsto[150]['movimientos'] = [['saldo_inicial', 1.0]];
        $corrida->forceFill(['previsto' => $previsto, 'tandas_total' => 3])->save();
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());
        $corrida->refresh();
        $this->assertSame(['error', 'no_coincide_con_el_resumen', 1], [$corrida->estado, $corrida->motivo, (int) $corrida->tandas_hechas]);
        $this->assertTrue($corrida->revertible());
        $this->assertSame(100, Client::where('user_id', $this->dueno->id)->where('name', 'like', 'Tanda B Soporte %')->count());
        $this->assertStringContainsString('se puede deshacer', $corrida->error);
        $this->revertir($corrida->uuid, 113)->assertJsonPath('corrida.estado', 'revertida');
        $this->assertSame(0, Client::where('user_id', $this->dueno->id)->where('name', 'like', 'Tanda B Soporte %')->count());
    }

    public function test_los_relanzamientos_tienen_tope_y_una_importacion_vencida_se_da_por_cortada(): void
    {
        $this->analizador(AiClientAnalyzer::class, ['nombre']);
        $uuid = $this->iniciar('client', $this->planilla([['Nombre'], ['Relanzada Soporte']]), 114)->json('corrida.corrida_id');
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        // El primer despacho es el de iniciar; los dos siguientes, los relanzamientos.
        foreach ([1, 2] as $vez) {
            $this->soltar_candado_de_unico($corrida);
            DB::table('importaciones_de_soporte')->where('id', $corrida->id)->update(['updated_at' => now()->subMinutes(20)]);
            $this->estado($uuid, 114)->assertJsonPath('corrida.estado', 'analizar');
            $this->assertSame($vez, (int) $corrida->fresh()->relanzamientos);
        }
        Queue::assertPushed(AvanzarImportacionDeSoporte::class, 3);
        $this->soltar_candado_de_unico($corrida);
        $this->estado($uuid, 114)->assertJsonPath('corrida.estado', 'analizar');
        Queue::assertPushed(AvanzarImportacionDeSoporte::class, 3);
        DB::table('importaciones_de_soporte')->where('id', $corrida->id)->update(['updated_at' => now()->subMinutes(20)]);
        $this->estado($uuid, 114)->assertJsonPath('corrida.estado', 'error')->assertJsonPath('corrida.motivo', 'se_corto');
        Queue::assertPushed(AvanzarImportacionDeSoporte::class, 3);

        // Confirmada hace más que un timeout del job y sin señales: se da por cortada sin relanzar.
        $otra = $this->analizada('client', $this->planilla([['Nombre'], ['Vencida Soporte']]), ['nombre'], 115);
        DB::table('importaciones_de_soporte')->where('id', $otra->id)->update(['estado' => 'importar',
            'confirmada_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(20)]);
        $this->estado($otra->uuid, 115)->assertJsonPath('corrida.estado', 'error')->assertJsonPath('corrida.motivo', 'se_corto');
        $this->assertSame(0, (int) $otra->fresh()->relanzamientos);
    }

    public function test_reintentar_el_mismo_archivo_crea_otra_corrida_solo_si_la_anterior_esta_muerta(): void
    {
        $archivo = $this->planilla([['Nombre'], ['Reintento Soporte']]);
        $corrida = $this->analizada('client', $archivo, ['nombre'], 116);
        $this->iniciar('client', $archivo, 116)->assertJsonPath('corrida.corrida_id', $corrida->uuid)->assertJsonPath('corrida_existente', true);
        $this->postJson(self::BASE . $corrida->uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => 116,
            'codigo' => $corrida->codigo, 'decision' => 'cancelar'], $this->cabeceras())->assertOk();
        $nueva = $this->iniciar('client', $archivo, 116)->assertJsonPath('corrida_existente', false)->json('corrida.corrida_id');
        $this->assertNotSame($corrida->uuid, $nueva);
        $this->iniciar('client', $archivo, 116)->assertJsonPath('corrida.corrida_id', $nueva);
    }

    /* -------------------------------------------------------------------------------------------------- */

    private function analizada(string $modelo, string $archivo, array $campos, int $ticket): ImportacionDeSoporte
    {
        $this->analizador(AiClientAnalyzer::class, $campos);
        $uuid = $this->iniciar($modelo, $archivo, $ticket)->assertStatus(202)->json('corrida.corrida_id');
        $corrida = ImportacionDeSoporte::where('uuid', $uuid)->firstOrFail();
        app(ImportacionDeSoporteService::class)->avanzar($corrida);

        return $corrida->fresh();
    }

    private function confirmada_e_importada(ImportacionDeSoporte $corrida, int $ticket): ImportacionDeSoporte
    {
        $this->postJson(self::BASE . $corrida->uuid . '/confirmar', ['user_id' => $this->dueno->id, 'support_ticket_id' => $ticket,
            'codigo' => $corrida->codigo], $this->cabeceras())->assertStatus(202);
        app(ImportacionDeSoporteService::class)->avanzar($corrida->fresh());

        return $corrida->fresh();
    }

    private function importada(string $modelo, string $archivo, array $campos, int $ticket): ImportacionDeSoporte
    {
        $corrida = $this->analizada($modelo, $archivo, $campos, $ticket);
        $this->assertSame('confirmacion', $corrida->estado, (string) $corrida->error);

        return $this->confirmada_e_importada($corrida, $ticket);
    }

    /** En la cola real el candado de único del job perdido ya venció (uniqueFor < 10 minutos); acá el reloj no corre. */
    private function soltar_candado_de_unico(ImportacionDeSoporte $corrida): void
    {
        Cache::lock('laravel_unique_job:' . AvanzarImportacionDeSoporte::class . 'soporte-' . $corrida->id)->forceRelease();
    }

    private function estado(string $uuid, int $ticket)
    {
        return $this->getJson(self::BASE . $uuid . '?user_id=' . $this->dueno->id . '&support_ticket_id=' . $ticket, $this->cabeceras())->assertOk();
    }
}
