<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\BackgroundProcess;
use App\Models\ImportStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * El registro único de procesos en segundo plano (misión procesos-en-segundo-plano, 18/9/2026).
 *
 * Lo que protege: que `BackgroundProcessHelper` lleve la cuenta bien (porcentaje, estados,
 * idempotencia del cierre), que emita `BackgroundProcessUpdated` cuando corresponde y NO cuando
 * no (el throttle es lo que cuida la cuota de Pusher que comparten los 40 clientes), y que el
 * payload que viaja quepa siempre en el límite de Pusher.
 *
 * `Event::fake()` captura el broadcast: `broadcast()` termina en el dispatcher de eventos (el
 * `__destruct` de PendingBroadcast), así que con el fake se puede contar cuántas veces se emitió
 * sin tocar Pusher.
 *
 * @group procesos-en-segundo-plano
 */
class Registro_de_procesos_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user_id = (int) auth()->id();
    }

    /** @test */
    public function iniciar_crea_la_fila_en_proceso_y_emite_el_evento()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Actualización masiva de artículos', [
            'auth_user_id' => $this->user_id,
            'detalle'      => '120 artículos',
            'total'        => 120,
            'unidad'       => 'artículos',
        ]);

        $this->assertNotNull($proceso);
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, $proceso->status);
        $this->assertSame(0, (int) $proceso->porcentaje);
        $this->assertSame(120, (int) $proceso->total);
        $this->assertNotNull($proceso->started_at);
        $this->assertNull($proceso->finished_at);
        $this->assertSame(36, strlen($proceso->uuid));

        Event::assertDispatched(BackgroundProcessUpdated::class, function ($evento) use ($proceso) {
            return $evento->owner_id === $this->user_id
                && $evento->proceso['id'] === $proceso->id
                && $evento->proceso['status'] === 'en_proceso'
                && $evento->broadcastAs() === 'BackgroundProcessUpdated'
                && $evento->broadcastOn()->name === 'background_processes.' . $this->user_id;
        });
    }

    /** @test */
    public function sin_total_el_porcentaje_es_null_y_completar_lo_lleva_a_100()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'exportacion', 'Exportación de artículos');

        $this->assertNull($proceso->total);
        $this->assertNull($proceso->porcentaje);

        $proceso = BackgroundProcessHelper::completar($proceso, ['archivo' => 'articulos.xlsx']);

        $this->assertSame(BackgroundProcess::STATUS_COMPLETADO, $proceso->status);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertNotNull($proceso->finished_at);
        $this->assertSame('articulos.xlsx', $proceso->resultado()['archivo']);
    }

    /** @test */
    public function avanzar_calcula_el_porcentaje_y_mezcla_el_resultado_parcial()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'importacion_articulos', 'Importación de artículos', [
            'total'  => 12,
            'unidad' => 'lotes',
        ]);

        $proceso = BackgroundProcessHelper::avanzar($proceso, 3, [
            'etapa'     => 'Lote 3 de 12',
            'resultado' => ['creados' => 10, 'actualizados' => 5],
        ]);

        $this->assertSame(25, (int) $proceso->porcentaje);
        $this->assertSame('Lote 3 de 12', $proceso->etapa);

        $proceso = BackgroundProcessHelper::avanzar($proceso, 6, [
            'resultado' => ['creados' => 22],
        ]);

        $this->assertSame(50, (int) $proceso->porcentaje);
        // Se mezcla: la clave que no vino se conserva.
        $this->assertSame(22, $proceso->resultado()['creados']);
        $this->assertSame(5, $proceso->resultado()['actualizados']);
        // Nunca pasa de 100 aunque el llamador se pase.
        $proceso = BackgroundProcessHelper::avanzar($proceso, 40);
        $this->assertSame(100, (int) $proceso->porcentaje);
        $this->assertSame(BackgroundProcess::STATUS_EN_PROCESO, $proceso->status, 'llegar al 100% no cierra: cierra completar()');
    }

    /** @test */
    public function incrementar_suma_de_forma_atomica_y_lee_el_valor_real()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'recalculo_precios', 'Recálculo de precios', ['total' => 4]);

        // Otro worker avanzó por detrás de esta copia en memoria.
        DB::table('background_processes')->where('id', $proceso->id)->update(['procesados' => 2]);

        $proceso = BackgroundProcessHelper::incrementar($proceso, 1);

        $this->assertSame(3, (int) $proceso->procesados);
        $this->assertSame(75, (int) $proceso->porcentaje);
    }

    /** @test */
    public function el_throttle_no_emite_dos_avances_seguidos_pero_si_el_cierre()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'eliminacion_masiva', 'Eliminación de artículos', ['total' => 100]);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);

        // Tres avances en el mismo segundo: ninguno pasa el throttle (el iniciar acaba de emitir).
        BackgroundProcessHelper::avanzar($proceso, 10);
        BackgroundProcessHelper::avanzar($proceso, 20);
        BackgroundProcessHelper::avanzar($proceso, 30);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);

        // Si el último broadcast fue hace más del mínimo, el avance sí sale.
        DB::table('background_processes')->where('id', $proceso->id)->update([
            'broadcast_at' => Carbon::now()->subSeconds(BackgroundProcessHelper::SEGUNDOS_ENTRE_BROADCASTS + 1),
        ]);
        BackgroundProcessHelper::avanzar($proceso, 40);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 2);

        // Llegar al total emite aunque no haya pasado el tiempo: es el último cuadro de la barra.
        BackgroundProcessHelper::avanzar($proceso, 100);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 3);

        // Y el cierre emite siempre.
        BackgroundProcessHelper::completar($proceso);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 4);
    }

    /** @test */
    public function forzar_broadcast_y_cambio_de_etapa_saltean_el_throttle()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'analisis_excel', 'Análisis del Excel');
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 1);

        BackgroundProcessHelper::etapa($proceso, 'Leyendo el archivo', 40);
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 2);

        $proceso = BackgroundProcess::find($proceso->id);
        $this->assertSame(40, (int) $proceso->porcentaje);
        $this->assertSame(100, (int) $proceso->total);
        $this->assertSame('Leyendo el archivo', $proceso->etapa);
    }

    /** @test */
    public function completar_y_fallar_son_idempotentes_y_no_pisan_un_cierre_anterior()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Actualización masiva', ['total' => 10]);

        $proceso = BackgroundProcessHelper::fallar($proceso, 'Se cortó la luz', ['afectados' => 3]);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $proceso->status);
        $this->assertSame('Se cortó la luz', $proceso->error_message);
        $cerrado_en = $proceso->finished_at;

        // El failed() del job llega después del catch del handle(): no duplica ni cambia nada.
        $otra_vez = BackgroundProcessHelper::fallar($proceso, 'Otro mensaje');
        $this->assertSame('Se cortó la luz', $otra_vez->error_message);
        $this->assertEquals($cerrado_en, $otra_vez->finished_at);

        // Un completar tardío tampoco reabre.
        $completado = BackgroundProcessHelper::completar($proceso);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $completado->status);

        // Y avanzar sobre un cerrado no escribe nada.
        $avanzado = BackgroundProcessHelper::avanzar($proceso, 9);
        $this->assertSame(0, (int) $avanzado->procesados);

        // iniciar + fallar: exactamente dos emisiones, las demás no salieron.
        Event::assertDispatchedTimes(BackgroundProcessUpdated::class, 2);
    }

    /** @test */
    public function por_referencia_encuentra_el_proceso_abierto_del_modelo_propio()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $import_status = ImportStatus::create([
            'user_id'      => $this->user_id,
            'total_chunks' => 3,
            'status'       => 'pendiente',
        ]);

        $this->assertNull(BackgroundProcessHelper::por_referencia($import_status));

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'importacion_articulos', 'Importación de artículos', [
            'referencia' => $import_status,
            'total'      => 3,
        ]);

        $encontrado = BackgroundProcessHelper::por_referencia($import_status);
        $this->assertNotNull($encontrado);
        $this->assertSame($proceso->id, $encontrado->id);
        $this->assertSame(ImportStatus::class, $encontrado->referencia_type);

        BackgroundProcessHelper::completar($proceso);

        $this->assertNull(BackgroundProcessHelper::por_referencia($import_status), 'cerrado ya no cuenta como abierto');
        $this->assertSame($proceso->id, BackgroundProcessHelper::por_referencia($import_status, false)->id);
    }

    /** @test */
    public function el_payload_nunca_supera_el_limite_de_pusher()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        // Un resultado enorme (lo que rompió el aviso de imágenes automáticas el 25/8/2026).
        $resultado = [];
        for ($i = 0; $i < 400; $i++) {
            $resultado['clave_' . $i] = str_repeat('x', 40);
        }
        $resultado['lista'] = range(1, 500);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'imagenes_automaticas', 'Imágenes automáticas', [
            'resultado' => $resultado,
        ]);

        $payload = BackgroundProcessHelper::payload($proceso);

        $this->assertLessThan(10240, strlen(json_encode($payload)));
        $this->assertTrue($payload['resultado_recortado']);
        $this->assertSame([], $payload['resultado']);

        // Con un resultado normal, viaja entero y sin arrays anidados.
        $chico = BackgroundProcessHelper::iniciar($this->user_id, 'imagenes_automaticas', 'Imágenes automáticas', [
            'resultado' => ['asignadas' => 12, 'saltadas' => 3, 'nombres' => ['a', 'b']],
        ]);
        $payload = BackgroundProcessHelper::payload($chico);
        $this->assertSame(['asignadas' => 12, 'saltadas' => 3], $payload['resultado']);
        $this->assertArrayNotHasKey('resultado_recortado', $payload);
    }

    /** @test */
    public function un_proceso_mudo_hace_horas_se_da_por_interrumpido_al_listar()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $vivo = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Viva', ['total' => 10]);
        $muerto = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Muerta', ['total' => 10]);
        $nunca_arranco = BackgroundProcessHelper::iniciar($this->user_id, 'exportacion', 'Encolada', ['status' => 'pendiente']);

        $hace_mucho = Carbon::now()->subHours(BackgroundProcessHelper::HORAS_SIN_NOVEDADES_PARA_DARLO_POR_MUERTO + 1);
        DB::table('background_processes')->whereIn('id', [$muerto->id, $nunca_arranco->id])->update(['updated_at' => $hace_mucho]);

        $respuesta = $this->getJson('/api/background-processes');
        $respuesta->assertStatus(200);

        $activos = collect($respuesta->json('activos'))->pluck('id')->all();
        $recientes = collect($respuesta->json('recientes'))->keyBy('id');

        // El listado dice si ESTE servidor puede emitir: en testing el driver es `log`, así que
        // habilitado tiene que venir en false (y con el driver a la vista, para que el que lea
        // sepa por qué).
        $this->assertSame('log', $respuesta->json('broadcast.driver'));
        $this->assertFalse($respuesta->json('broadcast.habilitado'));

        $this->assertSame([$vivo->id], $activos);
        $this->assertSame('fallo', $recientes[$muerto->id]['status']);
        $this->assertStringContainsString('dejó de reportar', $recientes[$muerto->id]['error_message']);
        $this->assertStringContainsString('nunca llegó a arrancar', $recientes[$nunca_arranco->id]['error_message']);
    }

    /** @test */
    public function los_endpoints_scopean_por_dueno_y_marcan_vistos()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $mio = BackgroundProcessHelper::iniciar($this->user_id, 'exportacion', 'Mía');
        $ajeno = BackgroundProcessHelper::iniciar($this->user_id + 100000, 'exportacion', 'Ajena');

        $this->getJson('/api/background-processes/' . $ajeno->id)->assertStatus(404);
        $this->putJson('/api/background-processes/' . $ajeno->id . '/visto')->assertStatus(404);

        $detalle = $this->getJson('/api/background-processes/' . $mio->id);
        $detalle->assertStatus(200);
        $this->assertSame('Mía', $detalle->json('proceso.titulo'));
        $this->assertNull($detalle->json('referencia'));

        // Un activo no se puede marcar visto.
        $this->putJson('/api/background-processes/' . $mio->id . '/visto')->assertStatus(200);
        $this->assertNull(BackgroundProcess::find($mio->id)->visto_at);

        BackgroundProcessHelper::completar($mio);
        $this->putJson('/api/background-processes/' . $mio->id . '/visto')->assertStatus(200);
        $this->assertNotNull(BackgroundProcess::find($mio->id)->visto_at);

        // Y desaparece de los recientes (se mira por id: la base del slot puede tener otros
        // procesos del mismo dueño, de corridas anteriores o de una verificación a mano).
        $listado = $this->getJson('/api/background-processes');
        $this->assertNotContains($mio->id, collect($listado->json('recientes'))->pluck('id')->all());
        $this->assertNotContains($mio->id, collect($listado->json('activos'))->pluck('id')->all());

        // "Limpiar": marca todos los terminados del comercio, no los ajenos.
        $otro = BackgroundProcessHelper::iniciar($this->user_id, 'exportacion', 'Otra');
        BackgroundProcessHelper::fallar($otro, 'x');
        BackgroundProcessHelper::completar($ajeno);
        $marcados = (int) $this->putJson('/api/background-processes/vistos')->assertStatus(200)->json('marcados');
        $this->assertGreaterThanOrEqual(1, $marcados);
        $this->assertNotNull(BackgroundProcess::find($otro->id)->visto_at);
        $this->assertNull(BackgroundProcess::find($ajeno->id)->visto_at);
    }

    /** @test */
    public function el_detalle_de_una_importacion_trae_el_import_status_con_su_proveedor()
    {
        Event::fake([BackgroundProcessUpdated::class]);

        $import_status = ImportStatus::create([
            'user_id'          => $this->user_id,
            'total_chunks'     => 2,
            'processed_chunks' => 1,
            'status'           => 'en_proceso',
            'filas_procesadas' => 50,
            'created_models'   => 10,
            'updated_models'   => 40,
        ]);

        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'importacion_articulos', 'Importación de artículos', [
            'referencia' => $import_status,
            'total'      => 2,
        ]);

        $detalle = $this->getJson('/api/background-processes/' . $proceso->id);
        $detalle->assertStatus(200);
        $this->assertSame($import_status->id, $detalle->json('referencia.import_status.id'));
        $this->assertSame(50, $detalle->json('referencia.import_status.filas_procesadas'));
        $this->assertArrayHasKey('import_history', $detalle->json('referencia'));
    }
}
