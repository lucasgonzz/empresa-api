<?php

namespace Tests\Feature\Infraestructura;

use App\Jobs\FinalizeArticleImport;
use App\Jobs\ProcessArticleChunk;
use App\Jobs\ProcessArticleExportJob;
use App\Jobs\ProcessClientExportJob;
use App\Jobs\ProcessProviderExportJob;
use App\Jobs\ProcessProviderOrderArticleImport;
use App\Jobs\ProcessSincronizarDescuentosProveedorJob;
use App\Jobs\RollbackArticleImportHistory;
use App\Jobs\RunExcelAnalysisJob;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Misión colas-excel-asistente (21/9/2026) — los jobs pesados de import/export de catálogo
 * (timeout 600-3600s) eligen su cola en su propio constructor, seteando la propiedad pública
 * $queue del trait Queueable: 'excel' en shared hosting (separados del asistente de IA, que se
 * queda en 'default'), y null (= 'default') en el VPS, porque ahí el supervisor de cada cliente
 * consume solo esa cola hoy — mandar estos jobs a 'excel' en el VPS los dejaría sin nadie que los
 * procese.
 *
 * 🔴 Por qué NO es un método `viaQueue()` (lo que este archivo tenía en su primera versión, y el
 * chequeo independiente de la propia misión encontró que era código muerto): ese hook de Laravel
 * existe únicamente para event listeners en cola (Illuminate\Events\Dispatcher) — nunca se invoca
 * para clases ShouldQueue despachadas con dispatch(), Bus::chain() o Bus::batch(), que son los
 * tres mecanismos que usan estos 9 jobs. Confirmado leyendo Illuminate\Bus\Dispatcher::pushCommandToQueue()
 * (vendor/laravel/framework, v8.83.29 real de este repo): solo mira la propiedad pública
 * `$command->queue`. Por eso estos tests construyen cada job con su CONSTRUCTOR REAL (no con
 * `newInstanceWithoutConstructor()`, que hubiera dejado pasar el bug original sin detectarlo,
 * porque nunca ejecuta la línea que setea $queue) y, para el caso de ProcessArticleChunk +
 * FinalizeArticleImport, con un despacho real de Bus::chain() + Queue::fake(): es el único de los
 * tres mecanismos de despacho que respeta la $queue individual de cada eslabón — Bus::batch(), en
 * cambio, fuerza TODOS los jobs del batch a una única cola de nivel batch
 * (Illuminate\Bus\Batch::add(), `$this->options['queue'] ?? null`) ignorando la propiedad
 * individual; no es el caso acá porque `InitExcelImport::mandar_batch()` es código muerto (su
 * propio docblock lo dice: "siempre procesamiento secuencial (Bus::chain), independientemente del
 * entorno") y el camino real que corre es `mandar_chain()`.
 */
class Jobs_pesados_cola_excel_en_shared_Test extends TestCase
{
    /**
     * Un argumento por cada clase de job de dispatch simple, en el orden exacto de su constructor
     * real — valores dummy: no se llama a handle(), solo interesa qué cola queda seteada tras
     * construir.
     *
     * @return array<string, array{0: string, 1: array}>
     */
    public function provider_jobs_de_dispatch_simple(): array
    {
        return [
            'RunExcelAnalysisJob'                     => [RunExcelAnalysisJob::class, [1]],
            'FinalizeArticleImport'                    => [FinalizeArticleImport::class, [1, 1, 1]],
            'ProcessProviderOrderArticleImport'        => [ProcessProviderOrderArticleImport::class, [
                [], 1, 10, 1, 1, 'articulos', false, 0, 'Hoja1', '/tmp/x.xlsx', 1, 1,
            ]],
            'RollbackArticleImportHistory'             => [RollbackArticleImportHistory::class, [1, 1, null]],
            'ProcessArticleExportJob'                  => [ProcessArticleExportJob::class, [1, 1, [1, 2], 1]],
            'ProcessProviderExportJob'                 => [ProcessProviderExportJob::class, [1, 1, 1]],
            'ProcessClientExportJob'                   => [ProcessClientExportJob::class, [1, 1, 1]],
            'ProcessSincronizarDescuentosProveedorJob' => [ProcessSincronizarDescuentosProveedorJob::class, [
                1, 1, 1, 'todos', false, 'ignorar', 'op-test',
            ]],
        ];
    }

    /** @test @dataProvider provider_jobs_de_dispatch_simple */
    public function en_shared_hosting_construye_con_queue_excel(string $clase, array $argumentos)
    {
        config(['app.VPS' => false]);

        $job = new $clase(...$argumentos);

        $this->assertSame('excel', $job->queue, $clase . ' tiene que quedar con $queue = "excel" tras construirse en shared hosting.');
    }

    /** @test @dataProvider provider_jobs_de_dispatch_simple */
    public function en_el_vps_construye_con_queue_null(string $clase, array $argumentos)
    {
        config(['app.VPS' => true]);

        $job = new $clase(...$argumentos);

        $this->assertNull($job->queue, $clase . ' tiene que quedar con $queue = null (default) tras construirse en el VPS.');
    }

    /**
     * ProcessArticleChunk no está en el provider de arriba a propósito: en producción nunca se
     * despacha solo, viaja como PRIMER eslabón de la Bus::chain que arma
     * InitExcelImport::mandar_chain() (chunks + FinalizeArticleImport al final). Este test
     * ejercita ESE camino real con Queue::fake() — es la prueba que faltaba y que el chequeo
     * independiente de esta misión señaló como el hueco que dejó pasar el bug original
     * (viaQueue(), que nunca se invocaba).
     *
     * 🔴 Solo se verifica el PRIMER eslabón acá, no FinalizeArticleImport: Queue::fake()
     * intercepta el job antes de ejecutar handle(), y dispatchNextJobInChain() —el método que
     * despacha el SIGUIENTE eslabón— recién se llama después de que el actual complete
     * (Illuminate\Queue\CallQueuedHandler::ensureNextJobInChainIsDispatched()), así que con fake
     * puro el segundo eslabón nunca llega a despacharse. Lo que sí queda demostrado con certeza,
     * leyendo Illuminate\Bus\Queueable::dispatchNextJobInChain() (línea
     * `$next->onQueue($next->queue ?: $this->chainQueue)`): prioriza SIEMPRE la $queue propia de
     * cada eslabón por encima de la de la chain — que es justo lo que
     * en_shared_hosting_construye_con_queue_excel() / en_el_vps_construye_con_queue_null() ya
     * prueban para FinalizeArticleImport (que su constructor deja $queue bien seteada, la
     * precondición que dispatchNextJobInChain() necesita).
     *
     * @return void
     */
    public function test_en_una_chain_real_el_primer_eslabon_respeta_su_propia_cola()
    {
        config(['app.VPS' => false]);
        Queue::fake();

        $chunk = new ProcessArticleChunk(
            '/tmp/fake.csv', [], [], false, 1, 10, 1, 1, 1, 1, 1, 1, 0,
            false, false, false, false, false
        );

        $finalize = new FinalizeArticleImport(1, 1, 1);

        Bus::chain([$chunk, $finalize])->dispatch();

        Queue::assertPushedOn('excel', ProcessArticleChunk::class);
    }

    /**
     * Mismo camino real, pero en el VPS: el primer eslabón tiene que quedar en la cola default
     * (sin nombre explícito), no en 'excel' — es el escenario de máximo riesgo de esta misión
     * (jobs huérfanos en una cola que el supervisor de un cliente ya migrado no consume).
     *
     * @return void
     */
    public function test_en_una_chain_real_en_el_vps_el_primer_eslabon_queda_en_default()
    {
        config(['app.VPS' => true]);
        Queue::fake();

        $chunk = new ProcessArticleChunk(
            '/tmp/fake.csv', [], [], false, 1, 10, 1, 1, 1, 1, 1, 1, 0,
            false, false, false, false, false
        );

        $finalize = new FinalizeArticleImport(1, 1, 1);

        Bus::chain([$chunk, $finalize])->dispatch();

        Queue::assertPushedOn(null, ProcessArticleChunk::class);
    }

    /**
     * Red de seguridad: el asistente de IA no tiene que competir con estos jobs. Si alguna vez
     * alguien le agregara al asistente el mismo mecanismo (por copiar/pegar de un job de excel),
     * este test lo detecta.
     *
     * @return void
     */
    public function test_el_asistente_no_va_a_la_cola_excel()
    {
        config(['app.VPS' => false]);

        $job = new \App\Jobs\ResponderMensajeChatIaJob(1);

        $this->assertNotSame('excel', $job->queue, 'ResponderMensajeChatIaJob no tiene que ir a la cola excel: es el job del asistente, la separación es justamente para que no compita con los jobs pesados.');
    }
}
