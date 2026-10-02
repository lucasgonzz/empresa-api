<?php

namespace Tests\Feature\ProcesosEnSegundoPlano;

use App\Events\BackgroundProcessUpdated;
use App\Http\Controllers\Helpers\BackgroundProcessHelper;
use App\Models\BackgroundProcess;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\EmpresaTestCase;

/**
 * Cuánto tardó cada proceso en segundo plano (misión procesos-duracion, 1/10/2026).
 *
 * La duración no se persiste: sale de created_at (se encoló), started_at (empezó a correr) y
 * finished_at (cerró), y viaja en el payload como `duracion_segundos` y `espera_segundos`.
 *
 * @group procesos-en-segundo-plano
 */
class Duracion_de_los_procesos_Test extends EmpresaTestCase
{
    /** @var int */
    protected $user_id;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([BackgroundProcessUpdated::class]);
        $this->user_id = (int) auth()->id();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function un_proceso_abierto_no_tiene_duracion_todavia()
    {
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Masiva', ['total' => 10]);

        $payload = BackgroundProcessHelper::payload($proceso);

        $this->assertNull($payload['duracion_segundos']);
        $this->assertNotNull($payload['created_at']);
    }

    /** @test */
    public function al_completar_la_duracion_es_de_que_arranco_a_que_termino()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Masiva', ['total' => 10]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:03:20'));
        $cerrado = BackgroundProcessHelper::completar($proceso);

        $payload = BackgroundProcessHelper::payload($cerrado);

        $this->assertSame(200, $payload['duracion_segundos']);
        $this->assertSame(0, $payload['espera_segundos']);
    }

    /** @test */
    public function la_espera_en_cola_no_cuenta_como_ejecucion()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'recalculo_precios', 'Recálculo', [
            'status' => BackgroundProcess::STATUS_PENDIENTE,
            'total'  => 100,
        ]);

        // Mientras espera, la espera todavía no terminó: no se informa.
        $this->assertNull(BackgroundProcessHelper::payload($proceso)['espera_segundos']);

        // Un worker lo levanta 40 s después: el reloj de ejecución arranca ahí.
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:40'));
        BackgroundProcessHelper::avanzar($proceso, 10);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:02:10'));
        $cerrado = BackgroundProcessHelper::completar($proceso);

        $payload = BackgroundProcessHelper::payload($cerrado);

        $this->assertSame(90, $payload['duracion_segundos']);
        $this->assertSame(40, $payload['espera_segundos']);
    }

    /** @test */
    public function un_proceso_que_se_cierra_sin_haber_arrancado_cuenta_todo_como_espera()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'recalculo_precios', 'Recálculo', [
            'status' => BackgroundProcess::STATUS_PENDIENTE,
        ]);

        Carbon::setTestNow(Carbon::parse('2026-10-01 10:05:00'));
        $cerrado = BackgroundProcessHelper::fallar($proceso, 'Se cayó el worker');

        $payload = BackgroundProcessHelper::payload($cerrado);

        $this->assertSame(0, $payload['duracion_segundos']);
        $this->assertSame(300, $payload['espera_segundos']);
        $this->assertSame(BackgroundProcess::STATUS_FALLO, $payload['status']);
    }

    /** @test */
    public function el_endpoint_de_listado_devuelve_la_duracion_de_los_terminados()
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:00'));
        $proceso = BackgroundProcessHelper::iniciar($this->user_id, 'actualizacion_masiva', 'Masiva', ['total' => 5]);
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00:07'));
        BackgroundProcessHelper::completar($proceso);
        Carbon::setTestNow();

        // El listado filtra "terminados de las últimas 24 h": se le acerca la fecha al presente.
        DB::table('background_processes')->where('id', $proceso->id)->update(['finished_at' => Carbon::now()]);

        $respuesta = $this->getJson('/api/background-processes');
        $respuesta->assertStatus(200);

        $fila = collect($respuesta->json('recientes') ?: [])
            ->firstWhere('id', $proceso->id);

        $this->assertNotNull($fila, 'El proceso terminado debe venir en el listado.');
        $this->assertArrayHasKey('duracion_segundos', $fila);
        $this->assertArrayHasKey('espera_segundos', $fila);
    }
}
